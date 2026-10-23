<?php
/**
 * Access to TranslatePress tables and settings.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

namespace StringAbilities;

use WP_Error;

/**
 * Plain SELECTs and one conditional UPDATE on TranslatePress tables. Never
 * calls TranslatePress's render, editor or String Translation code, which
 * insert rows and spend machine-translation quota (SCHEMA §9).
 *
 * Only regular strings with block_type 0 or 1 are read (decision C). When a
 * language holds several rows for one original_id, the row TranslatePress
 * renders is used (decision A, TRANSLATEPRESS-INTERNALS §10a).
 */
final class Strings {

	/**
	 * Maximum candidate rows fetched for a text lookup (REQUIREMENTS §5.4).
	 */
	public const CANDIDATE_CAP = 200;

	/**
	 * Option caching the schema guard result.
	 */
	public const SCHEMA_OPTION = 'sat_schema_check';

	/**
	 * Search filter: three status flags (untranslated, human, machine), then the
	 * normalised lower-case query and its LIKE pattern for original and translation,
	 * compared lower-cased whatever the column collation.
	 */
	private const SEARCH_WHERE = 'block_type IN (0, 1) AND original_id > 0'
		. ' AND ((%d AND status = 0) OR (%d AND status = 2) OR (%d AND status NOT IN (0, 2)))'
		. " AND (%s = '' OR LOWER(original) LIKE %s OR LOWER(translated) LIKE %s)";

	private const DICTIONARY_COLUMNS = array( 'id', 'original', 'translated', 'status', 'block_type', 'original_id' );
	private const ORIGINALS_COLUMNS  = array( 'id', 'original' );

	/**
	 * Translation languages configured in TranslatePress, without the default language.
	 *
	 * @return list<string> Language codes, e.g. fr_FR.
	 */
	public function languages(): array {
		$settings = get_option( 'trp_settings' );
		if ( ! is_array( $settings ) || ! is_array( $settings['translation-languages'] ?? null ) ) {
			return array();
		}
		$default   = (string) ( $settings['default-language'] ?? '' );
		$languages = array();
		foreach ( $settings['translation-languages'] as $code ) {
			// Same character set as trp_is_valid_language_code().
			if ( is_string( $code ) && $code !== $default && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $code ) ) {
				$languages[] = $code;
			}
		}
		return $languages;
	}

	/**
	 * Map a TranslatePress status value to the exposed label.
	 * 1 (machine) and 3 (similar) are both "machine" (decision B).
	 *
	 * @param int $status TranslatePress status value.
	 * @return string untranslated, machine or human.
	 */
	public static function status_label( int $status ): string {
		return match ( $status ) {
			0       => 'untranslated',
			2       => 'human',
			default => 'machine',
		};
	}

	/**
	 * Schema guard (REQUIREMENTS §7.5): checks the expected columns once per
	 * TranslatePress version and table set, and caches the result.
	 *
	 * @return WP_Error|null Error with code sat_schema_unsupported, or null if the schema is supported.
	 */
	public function schema_error(): ?WP_Error {
		$tables = array( $this->originals_table() => self::ORIGINALS_COLUMNS );
		foreach ( $this->languages() as $language ) {
			$tables[ $this->dictionary_table( $language ) ] = self::DICTIONARY_COLUMNS;
		}
		$version = defined( 'TRP_PLUGIN_VERSION' ) ? (string) TRP_PLUGIN_VERSION : '';
		$key     = md5( $version . '|' . implode( ',', array_keys( $tables ) ) );

		$cached = get_option( self::SCHEMA_OPTION );
		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && is_array( $cached['missing'] ?? null ) ) {
			$missing = $cached['missing'];
		} else {
			$missing = $this->missing_columns( $tables );
			update_option(
				self::SCHEMA_OPTION,
				array(
					'key'     => $key,
					'missing' => $missing,
				),
				false
			);
		}

		if ( array() === $missing ) {
			return null;
		}
		return new WP_Error(
			'sat_schema_unsupported',
			sprintf(
				/* translators: %s: comma-separated table.column list. */
				__( 'TranslatePress database schema not recognised. Missing: %s.', 'string-abilities-for-translatepress' ),
				implode( ', ', $missing )
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Counts per status of the strings in one language, one per original_id.
	 *
	 * @param string $language Validated language code.
	 * @return array{untranslated: int, machine: int, human: int}
	 */
	public function stats( string $language ): array {
		global $wpdb;
		$table  = $this->dictionary_table( $language );
		$counts = array(
			'untranslated' => 0,
			'machine'      => 0,
			'human'        => 0,
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE block_type IN (0, 1) GROUP BY status', $table ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$counts[ self::status_label( (int) $row['status'] ) ] += (int) $row['n'];
		}

		// Duplicate rows: count only the row TranslatePress renders.
		$duplicated = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT original_id FROM %i WHERE block_type IN (0, 1) AND original_id > 0 GROUP BY original_id HAVING COUNT(*) > 1',
				$table
			)
		);
		foreach ( array_chunk( array_map( 'intval', $duplicated ), 500 ) as $chunk ) {
			foreach ( $this->rows_by_original( $language, $chunk ) as $group ) {
				foreach ( $group as $row ) {
					--$counts[ self::status_label( (int) $row['status'] ) ];
				}
				++$counts[ self::status_label( (int) self::rendered_row( $group )['status'] ) ];
			}
		}

		return $counts;
	}

	/**
	 * Paged search in one language.
	 *
	 * @param string   $language Validated language code.
	 * @param string[] $statuses Status labels to keep; empty keeps all.
	 * @param string   $query    Text to find in the original or translation (normalised, case-insensitive); empty for none.
	 * @param int      $page     Page number, from 1.
	 * @param int      $per_page Items per page.
	 * @return array{items: list<array<string, mixed>>, total: int, truncated: bool}
	 */
	public function search( string $language, array $statuses, string $query, int $page, int $per_page ): array {
		global $wpdb;
		$table  = $this->dictionary_table( $language );
		$all    = array() === $statuses;
		$flags  = array(
			(int) ( $all || in_array( 'untranslated', $statuses, true ) ),
			(int) ( $all || in_array( 'human', $statuses, true ) ),
			(int) ( $all || in_array( 'machine', $statuses, true ) ),
		);
		$needle = mb_strtolower( Validator::normalize( $query ) );
		$like   = '%' . $wpdb->esc_like( self::longest_word( $needle ) ) . '%';
		$args   = array_merge( array( $table ), $flags, array( $needle, $like, $like ) );

		if ( '' === $needle ) {
			$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT original_id) FROM %i WHERE ' . self::SEARCH_WHERE, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SEARCH_WHERE is a constant with placeholders; every value goes through prepare().
			$limit  = $per_page;
			$offset = ( $page - 1 ) * $per_page;
		} else {
			$total  = 0;
			$limit  = self::CANDIDATE_CAP + 1;
			$offset = 0;
		}
		$ids       = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare( 'SELECT DISTINCT original_id FROM %i WHERE ' . self::SEARCH_WHERE . ' ORDER BY original_id LIMIT %d OFFSET %d', array_merge( $args, array( $limit, $offset ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- SEARCH_WHERE is a constant with placeholders; every value goes through prepare().
			)
		);
		$truncated = '' !== $needle && count( $ids ) > self::CANDIDATE_CAP;

		$items = array();
		foreach ( $this->rows_by_original( $language, array_slice( $ids, 0, self::CANDIDATE_CAP ) ) as $original_id => $group ) {
			$row  = self::rendered_row( $group );
			$item = array( 'original_id' => $original_id ) + self::translation( $row );
			if ( ! $all && ! in_array( $item['status'], $statuses, true ) ) {
				continue;
			}
			if ( '' !== $needle && ! str_contains( mb_strtolower( Validator::normalize( (string) $row['original'] ) ), $needle ) && ! str_contains( mb_strtolower( Validator::normalize( $item['translated'] ) ), $needle ) ) {
				continue;
			}
			$items[] = array(
				'original_id' => $original_id,
				'original'    => (string) $row['original'],
				'translated'  => $item['translated'],
				'status'      => $item['status'],
			);
		}

		if ( '' !== $needle ) {
			$total = count( $items );
			$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
		}
		return array(
			'items'     => $items,
			'total'     => $total,
			'truncated' => $truncated,
		);
	}

	/**
	 * Originals by id, as stored in trp_original_strings.
	 *
	 * @param int[] $ids Original ids.
	 * @return array<int, string> Original text by id; unknown ids are absent.
	 */
	public function originals_by_id( array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, original FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( array( $this->originals_table() ), $ids )
			),
			ARRAY_A
		);
		$originals = array();
		foreach ( $rows as $row ) {
			$originals[ (int) $row['id'] ] = (string) $row['original'];
		}
		return $originals;
	}

	/**
	 * Originals whose normalised text equals the normalised input (REQUIREMENTS §5.4).
	 *
	 * @param string $text Source-language text as typed by the caller.
	 * @return array<int, string> Original text by id; empty when nothing matches.
	 */
	public function originals_by_text( string $text ): array {
		global $wpdb;
		$needle = Validator::normalize( $text );
		if ( '' === $needle ) {
			return array();
		}
		// Shortest first: an exact match is about as long as the input.
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, original FROM %i WHERE original LIKE %s ORDER BY CHAR_LENGTH(original), id LIMIT %d',
				$this->originals_table(),
				'%' . $wpdb->esc_like( self::longest_word( $needle ) ) . '%',
				self::CANDIDATE_CAP
			),
			ARRAY_A
		);
		$originals = array();
		foreach ( $rows as $row ) {
			if ( Validator::normalize( (string) $row['original'] ) === $needle ) {
				$originals[ (int) $row['id'] ] = (string) $row['original'];
			}
		}
		return $originals;
	}

	/**
	 * Translations of some originals in one language.
	 *
	 * @param string $language Validated language code.
	 * @param int[]  $ids      Original ids.
	 * @return array<int, array{translated: string, status: string, rows: int}> By original_id; absent when the language has no row.
	 */
	public function translations( string $language, array $ids ): array {
		$translations = array();
		foreach ( $this->rows_by_original( $language, $ids ) as $original_id => $group ) {
			$translations[ $original_id ] = self::translation( self::rendered_row( $group ) ) + array( 'rows' => count( $group ) );
		}
		return $translations;
	}

	/**
	 * The row a write targets for one original in one language: the row
	 * TranslatePress renders (decision A).
	 *
	 * @param string $language    Validated language code.
	 * @param int    $original_id Original id.
	 * @return array{id: int, original: string, translated: string, status: int, rows: int}|null Null when the language has no row for it.
	 */
	public function target_row( string $language, int $original_id ): ?array {
		$group = $this->rows_by_original( $language, array( $original_id ) )[ $original_id ] ?? null;
		if ( null === $group ) {
			return null;
		}
		$row = self::rendered_row( $group );
		return array(
			'id'         => (int) $row['id'],
			'original'   => (string) $row['original'],
			'translated' => (string) ( $row['translated'] ?? '' ),
			'status'     => (int) $row['status'],
			'rows'       => count( $group ),
		);
	}

	/**
	 * Change the translation and status of one dictionary row, only if it still
	 * holds the expected translation and status (SCHEMA §5). One UPDATE that
	 * can never insert; original and block_type are never written.
	 *
	 * @param string $language       Validated language code.
	 * @param int    $id             Dictionary row id.
	 * @param int    $original_id    Original id the row must belong to.
	 * @param string $old_translated Expected current translation (NULL counts as '').
	 * @param int    $old_status     Expected current status.
	 * @param string $new_translated New translation.
	 * @param int    $new_status     New status.
	 * @return bool True if the row was changed; false on conflict.
	 */
	public function update_row( string $language, int $id, int $original_id, string $old_translated, int $old_status, string $new_translated, int $new_status ): bool {
		global $wpdb;
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET translated = %s, status = %d WHERE id = %d AND original_id = %d AND BINARY COALESCE(translated, '') = %s AND status = %d",
				$this->dictionary_table( $language ),
				$new_translated,
				$new_status,
				$id,
				$original_id,
				$old_translated,
				$old_status
			)
		);
		return 1 === $changed;
	}

	/**
	 * Automatic-translation settings, from an explicit key allowlist
	 * (REQUIREMENTS §7.6). Never returns API keys, license data or the
	 * notification email. Calls no TranslatePress code: engine language
	 * checks can make HTTP calls (TRANSLATEPRESS-INTERNALS §10, item 18).
	 *
	 * @return array{enabled: bool, manual_only: bool, engine: string, languages: list<string>}
	 */
	public function auto_translation_settings(): array {
		$machine  = get_option( 'trp_machine_translation_settings' );
		$advanced = get_option( 'trp_advanced_settings' );
		$machine  = is_array( $machine ) ? $machine : array();
		$advanced = is_array( $advanced ) ? $advanced : array();
		$engine   = $machine['translation-engine'] ?? 'mtapi';

		return array(
			'enabled'     => 'yes' === ( $machine['machine-translation'] ?? 'no' ),
			'manual_only' => 'yes' === ( $advanced['manual_translation_only'] ?? 'no' ),
			'engine'      => is_string( $engine ) ? $engine : 'mtapi',
			'languages'   => $this->languages(),
		);
	}

	/**
	 * Dictionary rows (block_type 0 or 1) of some originals, grouped by original_id, each group ordered by id.
	 *
	 * @param string $language Validated language code.
	 * @param int[]  $ids      Original ids.
	 * @return array<int, non-empty-list<array<string, mixed>>>
	 */
	private function rows_by_original( string $language, array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, original_id, original, translated, status FROM %i WHERE block_type IN (0, 1) AND original_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id',
				array_merge( array( $this->dictionary_table( $language ) ), $ids )
			),
			ARRAY_A
		);
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ (int) $row['original_id'] ][] = $row;
		}
		ksort( $groups );
		return $groups;
	}

	/**
	 * The row TranslatePress renders among the rows of one original_id
	 * (TRANSLATEPRESS-INTERNALS §10a): the first served row with a non-empty
	 * translation, else the first served row, else the first row.
	 *
	 * @param non-empty-list<array<string, mixed>> $rows Rows ordered by id.
	 * @return array<string, mixed>
	 */
	private static function rendered_row( array $rows ): array {
		$served = array_values( array_filter( $rows, static fn( array $row ): bool => 0 !== (int) $row['status'] ) );
		foreach ( $served as $row ) {
			if ( ! empty( $row['translated'] ) ) {
				return $row;
			}
		}
		return $served[0] ?? $rows[0];
	}

	/**
	 * Exposed translation and status of a row. A NULL translation is ''.
	 *
	 * @param array<string, mixed> $row Dictionary row.
	 * @return array{translated: string, status: string}
	 */
	private static function translation( array $row ): array {
		return array(
			'translated' => (string) ( $row['translated'] ?? '' ),
			'status'     => self::status_label( (int) $row['status'] ),
		);
	}

	/**
	 * Longest run of letters and digits, used to narrow a LIKE lookup.
	 *
	 * @param string $text Normalised text.
	 * @return string Longest word, or '' if there is none.
	 */
	private static function longest_word( string $text ): string {
		$longest = '';
		foreach ( (array) preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			if ( mb_strlen( $word ) > mb_strlen( $longest ) ) {
				$longest = $word;
			}
		}
		return $longest;
	}

	/**
	 * Columns missing from the given tables.
	 *
	 * @param array<string, list<string>> $tables Expected columns by table name.
	 * @return list<string> Missing columns as table.column.
	 */
	private function missing_columns( array $tables ): array {
		global $wpdb;
		$missing = array();
		foreach ( $tables as $table => $expected ) {
			$columns = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					$wpdb->dbname,
					$table
				)
			);
			$columns = array_map( 'strtolower', $columns );
			foreach ( array_diff( $expected, $columns ) as $column ) {
				$missing[] = $table . '.' . $column;
			}
		}
		return $missing;
	}

	/**
	 * Dictionary table of a language, through TranslatePress (respects its filter).
	 *
	 * @param string $language Validated language code.
	 * @return string Table name.
	 */
	private function dictionary_table( string $language ): string {
		return $this->trp_query()->get_table_name( $language );
	}

	/**
	 * Original strings table, through TranslatePress (respects its filter).
	 *
	 * @return string Table name.
	 */
	private function originals_table(): string {
		return $this->trp_query()->get_table_name_for_original_strings();
	}

	/**
	 * TranslatePress query component.
	 *
	 * @return \TRP_Query
	 */
	private function trp_query(): \TRP_Query {
		return \TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
	}
}
