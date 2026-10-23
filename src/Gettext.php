<?php
/**
 * Access to TranslatePress gettext tables.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

namespace StringAbilities;

/**
 * Plain SELECTs and one conditional UPDATE on TranslatePress's gettext
 * tables (plugin and theme strings, SCHEMA §14). Never calls TranslatePress's
 * gettext processing, editor or String Translation code, which insert rows
 * and spend machine-translation quota (SCHEMA §14.3).
 *
 * A row is addressed by original_id + plural_form; TranslatePress keeps one
 * per language (unique key, SCHEMA §14.1). Rows without an original_id are
 * legacy rows and are ignored (decision G6).
 */
final class Gettext {

	/**
	 * Search filter: four status flags (untranslated, human, language_file,
	 * machine), the domain twice, then the normalised lower-case query and its
	 * LIKE pattern for original, plural original and translation.
	 */
	private const SEARCH_WHERE = 'tt.original_id > 0'
		. ' AND ((%d AND COALESCE(tt.status, 0) = 0) OR (%d AND tt.status = 2) OR (%d AND tt.status = 4) OR (%d AND tt.status NOT IN (0, 2, 4)))'
		. " AND (%s = '' OR ot.domain = %s)"
		. " AND (%s = '' OR LOWER(ot.original) LIKE %s OR LOWER(COALESCE(ot.original_plural, '')) LIKE %s OR LOWER(COALESCE(tt.translated, '')) LIKE %s)";

	/**
	 * Columns read from a gettext row joined with its original.
	 */
	private const ROW_COLUMNS = 'tt.id, tt.original_id, COALESCE(tt.plural_form, 0) AS plural_form, tt.translated, COALESCE(tt.status, 0) AS status, ot.original, ot.original_plural, ot.domain, ot.context';

	/**
	 * Counts per status in one language, one per original_id and plural form.
	 *
	 * @param string $language Validated language code.
	 * @param string $domain   Text domain, or '' for all.
	 * @return array{untranslated: int, machine: int, human: int, language_file: int}
	 */
	public function stats( string $language, string $domain ): array {
		global $wpdb;
		$counts = array(
			'untranslated'  => 0,
			'machine'       => 0,
			'human'         => 0,
			'language_file' => 0,
		);
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(tt.status, 0) AS status, COUNT(*) AS n FROM %i AS tt JOIN %i AS ot ON ot.id = tt.original_id WHERE tt.original_id > 0 AND (%s = '' OR ot.domain = %s) GROUP BY COALESCE(tt.status, 0)",
				$this->table( $language ),
				$this->originals_table(),
				$domain,
				$domain
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$counts[ Strings::status_label( (int) $row['status'] ) ] += (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Paged search in one language.
	 *
	 * @param string   $language Validated language code.
	 * @param string[] $statuses Status labels to keep; empty keeps all.
	 * @param string   $query    Text to find in the original, plural original or translation (normalised, case-insensitive); empty for none.
	 * @param string   $domain   Text domain, or '' for all.
	 * @param int      $page     Page number, from 1.
	 * @param int      $per_page Items per page.
	 * @return array{items: list<array<string, mixed>>, total: int, truncated: bool}
	 */
	public function search( string $language, array $statuses, string $query, string $domain, int $page, int $per_page ): array {
		global $wpdb;
		$all   = array() === $statuses;
		$flags = array();
		foreach ( array( 'untranslated', 'human', 'language_file', 'machine' ) as $status ) {
			$flags[] = (int) ( $all || in_array( $status, $statuses, true ) );
		}
		$needle = mb_strtolower( Validator::normalize( $query ) );
		$like   = '%' . $wpdb->esc_like( Strings::longest_word( $needle ) ) . '%';
		$args   = array_merge( array( $this->table( $language ), $this->originals_table() ), $flags, array( $domain, $domain, $needle, $like, $like, $like ) );
		$from   = 'FROM %i AS tt JOIN %i AS ot ON ot.id = tt.original_id WHERE ' . self::SEARCH_WHERE;

		if ( '' === $needle ) {
			$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) ' . $from, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $from is a constant with placeholders; every value goes through prepare().
			$limit  = $per_page;
			$offset = ( $page - 1 ) * $per_page;
		} else {
			$total  = 0;
			$limit  = Strings::CANDIDATE_CAP + 1;
			$offset = 0;
		}
		$rows      = $wpdb->get_results(
			$wpdb->prepare( 'SELECT ' . self::ROW_COLUMNS . ' ' . $from . ' ORDER BY tt.original_id, plural_form LIMIT %d OFFSET %d', array_merge( $args, array( $limit, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- constants with placeholders; every value goes through prepare().
			ARRAY_A
		);
		$truncated = '' !== $needle && count( $rows ) > Strings::CANDIDATE_CAP;

		$items = array();
		foreach ( array_slice( $rows, 0, Strings::CANDIDATE_CAP ) as $row ) {
			if ( '' !== $needle && ! self::contains( $row, $needle ) ) {
				continue;
			}
			$items[] = array(
				'original_id' => (int) $row['original_id'],
				'original'    => (string) $row['original'],
				'translated'  => (string) ( $row['translated'] ?? '' ),
				'status'      => Strings::status_label( (int) $row['status'] ),
				'plural_form' => (int) $row['plural_form'],
			) + self::identity( $row );
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
	 * Originals by id.
	 *
	 * @param int[] $ids Gettext original ids.
	 * @return array<int, array{original: string, original_plural: string|null, domain: string, context: string|null}> By id; unknown ids are absent.
	 */
	public function originals_by_id( array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, original, original_plural, domain, context FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( array( $this->originals_table() ), $ids )
			),
			ARRAY_A
		);
		return self::originals( $rows );
	}

	/**
	 * Originals whose normalised text equals the normalised input (REQUIREMENTS §5.4),
	 * optionally limited to one text domain and one context.
	 *
	 * @param string $text    Source text as typed by the caller.
	 * @param string $domain  Text domain, or '' for any.
	 * @param string $context Gettext context, or '' for any.
	 * @return array<int, array{original: string, original_plural: string|null, domain: string, context: string|null}> By id; empty when nothing matches.
	 */
	public function originals_by_text( string $text, string $domain, string $context ): array {
		global $wpdb;
		$needle = Validator::normalize( $text );
		if ( '' === $needle ) {
			return array();
		}
		// An empty or NULL context is stored as TranslatePress's trp_context sentinel or left empty (SCHEMA §14.7).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, original, original_plural, domain, context FROM %i WHERE original LIKE %s AND (%s = '' OR domain = %s) AND (%s = '' OR COALESCE(NULLIF(context, ''), 'trp_context') = %s) ORDER BY CHAR_LENGTH(original), id LIMIT %d",
				$this->originals_table(),
				'%' . $wpdb->esc_like( Strings::longest_word( $needle ) ) . '%',
				$domain,
				$domain,
				$context,
				$context,
				Strings::CANDIDATE_CAP
			),
			ARRAY_A
		);
		$rows = array_filter( $rows, static fn( array $row ): bool => Validator::normalize( (string) $row['original'] ) === $needle );
		return self::originals( $rows );
	}

	/**
	 * Items of get-strings: one per original_id and plural form that has a row in
	 * one of the languages, plural form 0 when none has.
	 *
	 * @param array<int, array{original: string, original_plural: string|null, domain: string, context: string|null}> $originals Originals by id.
	 * @param string[]                                                                                                $languages Validated language codes.
	 * @return list<array<string, mixed>>
	 */
	public function items( array $originals, array $languages ): array {
		global $wpdb;
		$items = array();
		$ids   = array_keys( $originals );
		foreach ( $languages as $language ) {
			if ( array() === $ids ) {
				break;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT original_id, COALESCE(plural_form, 0) AS plural_form, translated, COALESCE(status, 0) AS status FROM %i WHERE original_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
					array_merge( array( $this->table( $language ) ), $ids )
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$key                                        = (int) $row['original_id'] . '|' . (int) $row['plural_form'];
				$items[ $key ]['translations'][ $language ] = array(
					'translated' => (string) ( $row['translated'] ?? '' ),
					'status'     => Strings::status_label( (int) $row['status'] ),
				);
			}
		}
		foreach ( $ids as $id ) {
			$items[ $id . '|0' ] = $items[ $id . '|0' ] ?? array();
		}

		$output = array();
		foreach ( $items as $key => $item ) {
			list( $id, $plural_form ) = array_map( 'intval', explode( '|', (string) $key ) );
			$original                 = $originals[ $id ];
			$output[]                 = array(
				'original_id' => $id,
				'original'    => $original['original'],
				'plural_form' => $plural_form,
			) + $original + array(
				'translations' => (object) ( $item['translations'] ?? array() ),
				'warnings'     => array(),
			);
		}
		usort( $output, static fn( array $a, array $b ): int => array( $a['original_id'], $a['plural_form'] ) <=> array( $b['original_id'], $b['plural_form'] ) );
		return $output;
	}

	/**
	 * The row a write targets. original is the text the translation must match:
	 * the plural original for plural forms above 0, as TranslatePress checks it
	 * (SCHEMA §14.4).
	 *
	 * @param string $language    Validated language code.
	 * @param int    $original_id Gettext original id.
	 * @param int    $plural_form Plural form index.
	 * @return array{id: int, original: string, translated: string, status: int, rows: int}|null Null when the language has no row for it.
	 */
	public function target_row( string $language, int $original_id, int $plural_form ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . self::ROW_COLUMNS . ' FROM %i AS tt JOIN %i AS ot ON ot.id = tt.original_id WHERE tt.original_id = %d AND COALESCE(tt.plural_form, 0) = %d ORDER BY tt.id LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ROW_COLUMNS is a constant column list.
				$this->table( $language ),
				$this->originals_table(),
				$original_id,
				$plural_form
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$plural = (string) ( $row['original_plural'] ?? '' );
		return array(
			'id'         => (int) $row['id'],
			'original'   => $plural_form > 0 && '' !== $plural ? $plural : (string) $row['original'],
			'translated' => (string) ( $row['translated'] ?? '' ),
			'status'     => (int) $row['status'],
			'rows'       => 1,
		);
	}

	/**
	 * Change the translation and status of one gettext row, only if it still
	 * holds the expected translation and status. One UPDATE that can never
	 * insert; original, domain and plural_form are never written.
	 *
	 * @param string $language       Validated language code.
	 * @param int    $id             Gettext row id.
	 * @param int    $original_id    Original id the row must belong to.
	 * @param int    $plural_form    Plural form the row must have.
	 * @param string $old_translated Expected current translation (NULL counts as '').
	 * @param int    $old_status     Expected current status (NULL counts as 0).
	 * @param string $new_translated New translation.
	 * @param int    $new_status     New status.
	 * @return bool True if the row was changed; false on conflict.
	 */
	public function update_row( string $language, int $id, int $original_id, int $plural_form, string $old_translated, int $old_status, string $new_translated, int $new_status ): bool {
		global $wpdb;
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET translated = %s, status = %d WHERE id = %d AND original_id = %d AND COALESCE(plural_form, 0) = %d AND BINARY COALESCE(translated, '') = %s AND COALESCE(status, 0) = %d",
				$this->table( $language ),
				$new_translated,
				$new_status,
				$id,
				$original_id,
				$plural_form,
				$old_translated,
				$old_status
			)
		);
		return 1 === $changed;
	}

	/**
	 * Gettext table of a language, through TranslatePress (respects its filter).
	 *
	 * @param string $language Validated language code.
	 * @return string Table name.
	 */
	public function table( string $language ): string {
		return $this->trp_query()->get_gettext_table_name( $language );
	}

	/**
	 * Gettext originals table, through TranslatePress.
	 *
	 * @return string Table name.
	 */
	public function originals_table(): string {
		return $this->trp_query()->get_table_name_for_gettext_original_strings();
	}

	/**
	 * Originals keyed by id.
	 *
	 * @param array<array<string, mixed>> $rows Rows of the originals table.
	 * @return array<int, array{original: string, original_plural: string|null, domain: string, context: string|null}>
	 */
	private static function originals( array $rows ): array {
		$originals = array();
		foreach ( $rows as $row ) {
			$originals[ (int) $row['id'] ] = array( 'original' => (string) $row['original'] ) + self::identity( $row );
		}
		ksort( $originals );
		return $originals;
	}

	/**
	 * Plural original, domain and context of a row; empty values and
	 * TranslatePress's trp_context sentinel are null.
	 *
	 * @param array<string, mixed> $row Row with original_plural, domain and context.
	 * @return array{original_plural: string|null, domain: string, context: string|null}
	 */
	private static function identity( array $row ): array {
		$plural  = (string) ( $row['original_plural'] ?? '' );
		$context = (string) ( $row['context'] ?? '' );
		return array(
			'original_plural' => '' === $plural ? null : $plural,
			'domain'          => (string) ( $row['domain'] ?? '' ),
			'context'         => '' === $context || 'trp_context' === $context ? null : $context,
		);
	}

	/**
	 * Whether a row's original, plural original or translation contains the needle.
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param string               $needle Normalised lower-case text.
	 * @return bool
	 */
	private static function contains( array $row, string $needle ): bool {
		foreach ( array( 'original', 'original_plural', 'translated' ) as $column ) {
			if ( str_contains( mb_strtolower( Validator::normalize( (string) ( $row[ $column ] ?? '' ) ) ), $needle ) ) {
				return true;
			}
		}
		return false;
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
