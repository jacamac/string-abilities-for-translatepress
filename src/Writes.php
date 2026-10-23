<?php
/**
 * Write abilities and the change log.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

namespace StringAbilities;

use WP_Error;

/**
 * Registers update-translations, revert and get-change-log, and applies the
 * write rules of REQUIREMENTS §5.2: dry run by default, expected_current,
 * protection of human translations, Validator, sanitisation, change log.
 */
final class Writes {

	/**
	 * Option that turns writes off, like the SAT_READ_ONLY constant.
	 */
	public const READ_ONLY_OPTION = 'sat_read_only';

	/**
	 * Register the write abilities and get-change-log.
	 */
	public static function register_abilities(): void {
		$value  = array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'translated' => array( 'type' => 'string' ),
				'status'     => Plugin::STATUS,
			),
		);
		$issues = array(
			'type'  => 'array',
			'items' => Plugin::object(
				array(
					'code'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				)
			),
		);

		Plugin::register(
			'update-translations',
			__( 'Update translations', 'string-abilities-for-translatepress' ),
			'Translate, approve or reset strings, in several languages in one call. dry_run defaults to true: nothing is written until you repeat the call with dry_run false. Each item needs expected_current, the translation as you last read it (empty if untranslated); if it changed since, the item is a conflict and nothing is written. Changing or resetting a human translation needs overwrite_reviewed true. translate stores the text as human; approve turns a machine translation into human without changing it; reset makes the string untranslated, and with automatic translation on (and manual_only off) TranslatePress machine-translates it again on its next page view. Translations are checked against the original (HTML, printf placeholders, numbers, protected_terms) and must survive TranslatePress\'s sanitising unchanged; a bare & is stored as &amp; (warning sat_ampersand_encoded). In read-only mode only dry runs are allowed, and writes_disabled is true. A string with no row yet in a language cannot be written (sat_not_found): TranslatePress creates the row when a page showing it is viewed in that language. Full-page caches still show the old text until purged. ' . Plugin::UNTRUSTED,
			Plugin::input(
				array(
					'items'           => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'original_id'        => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'language'           => Plugin::LANGUAGE,
								'action'             => array(
									'type' => 'string',
									'enum' => array( 'translate', 'approve', 'reset' ),
								),
								'translated'         => array(
									'type'        => 'string',
									'description' => 'New text, for translate.',
								),
								'expected_current'   => array(
									'type'        => 'string',
									'description' => 'The translation as last read; empty if untranslated.',
								),
								'overwrite_reviewed' => array(
									'type'        => 'boolean',
									'description' => 'Required to translate or reset a human translation.',
								),
							),
							'required'             => array( 'original_id', 'language', 'action', 'expected_current' ),
							'additionalProperties' => false,
						),
					),
					'protected_terms' => array(
						'type'        => 'array',
						'items'       => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'description' => 'Terms that must appear verbatim in the translation when the original contains them.',
					),
					'note'            => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'Stored in the change log.',
					),
					'dry_run'         => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				array( 'items' )
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'dry_run'         => array( 'type' => 'boolean' ),
					'writes_disabled' => array(
						'type'        => 'boolean',
						'description' => 'Read-only mode is on: only dry runs are possible.',
					),
					'batch_id'        => array(
						'type'        => 'string',
						'description' => 'Only when something was written.',
					),
					'items'           => array(
						'type'  => 'array',
						'items' => Plugin::object(
							array(
								'original_id' => array( 'type' => 'integer' ),
								'language'    => array( 'type' => 'string' ),
								'outcome'     => array(
									'type' => 'string',
									'enum' => array( 'would_apply', 'applied', 'unchanged', 'rejected', 'conflict' ),
								),
								'errors'      => $issues,
								'warnings'    => $issues,
								'old'         => $value,
								'new'         => $value,
							)
						),
					),
				),
				'required'   => array( 'dry_run', 'writes_disabled', 'items' ),
			),
			array( self::class, 'update_translations' ),
			true
		);

		Plugin::register(
			'revert',
			__( 'Revert changes', 'string-abilities-for-translatepress' ),
			'Undo one change (change_id) or a whole batch (batch_id) made by this plugin: restores the previous translation and status on the exact row it wrote. A change whose row was modified since is a conflict and left alone; one already reverted is unchanged. Each restoration is logged as a new change in a new batch. Pass exactly one of change_id or batch_id. ' . Plugin::UNTRUSTED,
			Plugin::input(
				array(
					'change_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'batch_id'  => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				)
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'batch_id' => array(
						'type'        => 'string',
						'description' => 'Batch of the restorations; only when something was restored.',
					),
					'items'    => array(
						'type'  => 'array',
						'items' => Plugin::object(
							array(
								'change_id'   => array( 'type' => 'integer' ),
								'original_id' => array( 'type' => 'integer' ),
								'language'    => array( 'type' => 'string' ),
								'outcome'     => array(
									'type' => 'string',
									'enum' => array( 'applied', 'unchanged', 'conflict' ),
								),
								'old'         => $value,
								'new'         => $value,
							)
						),
					),
				),
				'required'   => array( 'items' ),
			),
			array( self::class, 'revert' ),
			true
		);

		Plugin::register(
			'get-change-log',
			__( 'Get change log', 'string-abilities-for-translatepress' ),
			'Paged history of the changes made by this plugin, newest first, optionally filtered by language, batch_id and since (UTC date-time). Each entry has the previous and new translation and status, the user, the note and reverted_by (id of the change that reverted it). ' . Plugin::UNTRUSTED,
			Plugin::input(
				array(
					'language' => array( 'type' => 'string' ),
					'batch_id' => array( 'type' => 'string' ),
					'since'    => array(
						'type'        => 'string',
						'description' => 'UTC date-time, e.g. 2026-01-31T00:00:00Z.',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 20,
					),
				),
				array(),
				true
			),
			Plugin::object(
				array(
					'entries'  => array(
						'type'  => 'array',
						'items' => Plugin::object(
							array(
								'id'          => array( 'type' => 'integer' ),
								'batch_id'    => array( 'type' => 'string' ),
								'created_at'  => array( 'type' => 'string' ),
								'user_id'     => array( 'type' => 'integer' ),
								'language'    => array( 'type' => 'string' ),
								'original_id' => array( 'type' => 'integer' ),
								'row_id'      => array( 'type' => 'integer' ),
								'action'      => array( 'type' => 'string' ),
								'old_value'   => array( 'type' => 'string' ),
								'old_status'  => Plugin::STATUS,
								'new_value'   => array( 'type' => 'string' ),
								'new_status'  => Plugin::STATUS,
								'note'        => array( 'type' => 'string' ),
								'reverted_by' => array( 'type' => array( 'integer', 'null' ) ),
							)
						),
					),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
					'total'    => array( 'type' => 'integer' ),
				)
			),
			array( self::class, 'get_change_log' )
		);
	}

	/**
	 * Execute update-translations.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_translations( $input ) {
		$input   = (array) $input;
		$strings = new Strings();
		$items   = array_values( array_map( static fn( $item ): array => (array) $item, (array) ( $input['items'] ?? array() ) ) );
		// Core fills no nested defaults (SCHEMA §12): dry run unless explicitly false.
		$dry_run   = isset( $input['dry_run'] ) ? self::flag( $input['dry_run'] ) : true;
		$read_only = self::read_only();
		if ( $read_only && ! $dry_run ) {
			return self::writes_disabled();
		}
		$error = self::write_error( $strings ) ?? self::items_error( $strings, $items );
		if ( $error ) {
			return $error;
		}
		$terms    = array_values( array_map( 'strval', (array) ( $input['protected_terms'] ?? array() ) ) );
		$note     = mb_substr( (string) ( $input['note'] ?? '' ), 0, 200 );
		$batch_id = wp_generate_uuid4();
		$written  = false;

		$results = array();
		foreach ( $items as $item ) {
			list( $result, $write ) = self::plan( $strings, $item, $terms );
			if ( null !== $write && ! $dry_run ) {
				$result['outcome'] = self::apply( $strings, $result, $write, $batch_id, $note ) ? 'applied' : 'conflict';
				$written           = $written || 'applied' === $result['outcome'];
			}
			$results[] = $result;
		}

		$output = array(
			'dry_run'         => $dry_run,
			'writes_disabled' => $read_only,
		);
		if ( $written ) {
			$output['batch_id'] = $batch_id;
		}
		$output['items'] = $results;
		return $output;
	}

	/**
	 * Execute revert.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function revert( $input ) {
		$input = (array) $input;
		if ( isset( $input['change_id'] ) === isset( $input['batch_id'] ) ) {
			return new WP_Error( 'sat_invalid_input', __( 'Pass exactly one of change_id or batch_id.', 'string-abilities-for-translatepress' ), array( 'status' => 400 ) );
		}
		if ( self::read_only() ) {
			return self::writes_disabled();
		}
		$strings = new Strings();
		$error   = self::write_error( $strings );
		if ( $error ) {
			return $error;
		}
		$changes = ChangeLog::changes( (int) ( $input['change_id'] ?? 0 ), (string) ( $input['batch_id'] ?? '' ) );
		if ( array() === $changes ) {
			return new WP_Error( 'sat_not_found', __( 'No change with this change_id or batch_id.', 'string-abilities-for-translatepress' ), array( 'status' => 404 ) );
		}

		$batch_id  = wp_generate_uuid4();
		$written   = false;
		$languages = $strings->languages();
		$items     = array();
		foreach ( $changes as $change ) {
			$item = array(
				'change_id'   => (int) $change['id'],
				'original_id' => (int) $change['original_id'],
				'language'    => (string) $change['language'],
				'outcome'     => 'conflict',
				'old'         => self::value( (string) $change['new_value'], (int) $change['new_status'] ),
				'new'         => self::value( (string) $change['old_value'], (int) $change['old_status'] ),
			);
			if ( null !== $change['reverted_by'] ) {
				$item['outcome'] = 'unchanged';
			} elseif ( self::restore( $strings, $languages, $change, $batch_id ) ) {
				$item['outcome'] = 'applied';
				$written         = true;
			}
			$items[] = $item;
		}

		$output = array();
		if ( $written ) {
			$output['batch_id'] = $batch_id;
		}
		$output['items'] = $items;
		return $output;
	}

	/**
	 * Execute get-change-log.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_change_log( $input ) {
		$input = (array) $input;
		$since = '';
		if ( isset( $input['since'] ) && '' !== $input['since'] ) {
			$time = strtotime( (string) $input['since'] );
			if ( false === $time ) {
				return new WP_Error( 'sat_invalid_input', __( 'since is not a valid date-time.', 'string-abilities-for-translatepress' ), array( 'status' => 400 ) );
			}
			$since = gmdate( 'Y-m-d H:i:s', $time );
		}
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

		ChangeLog::maybe_install();
		$result  = ChangeLog::entries( (string) ( $input['language'] ?? '' ), (string) ( $input['batch_id'] ?? '' ), $since, $page, $per_page );
		$entries = array();
		foreach ( $result['entries'] as $row ) {
			$entries[] = array(
				'id'          => (int) $row['id'],
				'batch_id'    => (string) $row['batch_id'],
				'created_at'  => str_replace( ' ', 'T', (string) $row['created_at'] ) . 'Z',
				'user_id'     => (int) $row['user_id'],
				'language'    => (string) $row['language'],
				'original_id' => (int) $row['original_id'],
				'row_id'      => (int) $row['row_id'],
				'action'      => (string) $row['action'],
				'old_value'   => (string) $row['old_value'],
				'old_status'  => Strings::status_label( (int) $row['old_status'] ),
				'new_value'   => (string) $row['new_value'],
				'new_status'  => Strings::status_label( (int) $row['new_status'] ),
				'note'        => (string) $row['note'],
				'reverted_by' => null === $row['reverted_by'] ? null : (int) $row['reverted_by'],
			);
		}
		return array(
			'entries'  => $entries,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $result['total'],
		);
	}

	/**
	 * Work out one item without writing: target row, conflict, rules, checks.
	 *
	 * @param Strings              $strings Data access.
	 * @param array<string, mixed> $item    Input item.
	 * @param string[]             $terms   Protected terms.
	 * @return array{0: array<string, mixed>, 1: array{row: array{id: int, original: string, translated: string, status: int, rows: int}, action: string, translated: string, status: int}|null} Output item, and what to write if it would apply.
	 */
	private static function plan( Strings $strings, array $item, array $terms ): array {
		$original_id = (int) ( $item['original_id'] ?? 0 );
		$language    = (string) ( $item['language'] ?? '' );
		$action      = (string) ( $item['action'] ?? '' );
		$result      = array(
			'original_id' => $original_id,
			'language'    => $language,
			'outcome'     => 'rejected',
			'errors'      => array(),
			'warnings'    => array(),
			'old'         => null,
			'new'         => null,
		);

		$row = $strings->target_row( $language, $original_id );
		if ( null === $row ) {
			$result['errors'][] = self::issue( 'sat_not_found', __( 'No row for this string in this language: unknown original_id, or TranslatePress has not created the row yet. It does so when a page showing the string is viewed in that language; this plugin never inserts rows.', 'string-abilities-for-translatepress' ) );
			return array( $result, null );
		}
		$result['old'] = self::value( $row['translated'], $row['status'] );
		if ( $row['rows'] > 1 ) {
			/* translators: %d: number of rows. */
			$result['warnings'][] = self::issue( 'sat_duplicate_rows', sprintf( __( 'TranslatePress holds %d rows for this string in this language; only the displayed one is changed.', 'string-abilities-for-translatepress' ), $row['rows'] ) );
		}
		if ( (string) ( $item['expected_current'] ?? '' ) !== $row['translated'] ) {
			$result['outcome'] = 'conflict';
			return array( $result, null );
		}

		list( $translated, $status ) = match ( $action ) {
			'translate' => array( (string) ( $item['translated'] ?? '' ), 2 ),
			'approve'   => array( $row['translated'], 2 ),
			default     => array( '', 0 ), // reset; the schema allows nothing else.
		};
		if ( 'translate' === $action ) {
			$encoded = Validator::encode_ampersands( $translated );
			if ( $encoded !== $translated ) {
				$result['warnings'][] = self::issue( 'sat_ampersand_encoded', __( 'Bare & converted to &amp;, as TranslatePress stores it.', 'string-abilities-for-translatepress' ) );
				$translated           = $encoded;
			}
		}
		$result['new']    = self::value( $translated, $status );
		$result['errors'] = self::rule_errors( $action, $row, $translated, self::flag( $item['overwrite_reviewed'] ?? false ) );
		if ( 'translate' === $action && '' !== $translated ) {
			$checks             = Validator::check( $row['original'], $translated, $terms );
			$result['errors']   = array_merge( $result['errors'], $checks['errors'] );
			$result['warnings'] = array_merge( $result['warnings'], $checks['warnings'] );
			if ( array() === $checks['errors'] ) {
				$sanitized = trp_sanitize_string( wp_kses( $translated, Validator::allowed_html( $row['original'] ) ) );
				if ( $sanitized !== $translated ) {
					/* translators: %s: text as it would be stored. */
					$result['errors'][] = self::issue( 'sat_sanitization_changed', sprintf( __( 'TranslatePress sanitising would store a different text. Send it as is if that is what you mean: %s', 'string-abilities-for-translatepress' ), $sanitized ) );
				}
			}
		}

		if ( array() !== $result['errors'] ) {
			return array( $result, null );
		}
		if ( $translated === $row['translated'] && $status === $row['status'] ) {
			$result['outcome'] = 'unchanged';
			return array( $result, null );
		}
		$result['outcome'] = 'would_apply';
		return array(
			$result,
			array(
				'row'        => $row,
				'action'     => $action,
				'translated' => $translated,
				'status'     => $status,
			),
		);
	}

	/**
	 * Errors from the action rules (REQUIREMENTS §5.2).
	 *
	 * @param string                                                                       $action     translate, approve or reset.
	 * @param array{id: int, original: string, translated: string, status: int, rows: int} $row        Target row.
	 * @param string                                                                       $translated New text.
	 * @param bool                                                                         $overwrite  overwrite_reviewed.
	 * @return list<array{code: string, message: string}>
	 */
	private static function rule_errors( string $action, array $row, string $translated, bool $overwrite ): array {
		$errors = array();
		if ( 'translate' === $action && '' === $translated ) {
			$errors[] = self::issue( 'sat_invalid_input', __( 'translate needs a non-empty translated; use reset to clear a translation.', 'string-abilities-for-translatepress' ) );
		}
		if ( 'approve' === $action && ( 0 === $row['status'] || '' === $row['translated'] ) ) {
			$errors[] = self::issue( 'sat_invalid_input', __( 'Nothing to approve: the string is untranslated.', 'string-abilities-for-translatepress' ) );
		}
		if ( 'approve' !== $action && 2 === $row['status'] && ! $overwrite ) {
			$errors[] = self::issue( 'sat_reviewed_protected', __( 'The current translation is human-reviewed; set overwrite_reviewed to change or reset it.', 'string-abilities-for-translatepress' ) );
		}
		return $errors;
	}

	/**
	 * Write one planned item and log it.
	 *
	 * @param Strings              $strings  Data access.
	 * @param array<string, mixed> $result   Output item (original_id, language).
	 * @param array<string, mixed> $write    Planned write from plan().
	 * @param string               $batch_id Batch UUID.
	 * @param string               $note     Note.
	 * @return bool False on conflict.
	 */
	private static function apply( Strings $strings, array $result, array $write, string $batch_id, string $note ): bool {
		$row = $write['row'];
		if ( ! $strings->update_row( $result['language'], $row['id'], $result['original_id'], $row['translated'], $row['status'], $write['translated'], $write['status'] ) ) {
			return false;
		}
		ChangeLog::record(
			$batch_id,
			$write['action'],
			array(
				'language'    => $result['language'],
				'original_id' => $result['original_id'],
				'row_id'      => $row['id'],
				'old_value'   => $row['translated'],
				'old_status'  => $row['status'],
				'new_value'   => $write['translated'],
				'new_status'  => $write['status'],
				'note'        => $note,
			)
		);
		return true;
	}

	/**
	 * Restore the previous value and status of one change on the row it wrote,
	 * if that row still holds what the change wrote, and log the restoration.
	 * Never locates a row by value: duplicate rows often hold the same text.
	 *
	 * @param Strings              $strings   Data access.
	 * @param string[]             $languages Configured translation languages.
	 * @param array<string, mixed> $change    Change-log row.
	 * @param string               $batch_id  Batch UUID of the restorations.
	 * @return bool False on conflict.
	 */
	private static function restore( Strings $strings, array $languages, array $change, string $batch_id ): bool {
		$language    = (string) $change['language'];
		$original_id = (int) $change['original_id'];
		$current     = (string) $change['new_value'];
		$status      = (int) $change['new_status'];
		$row_id      = (int) $change['row_id'];
		if ( 0 === $row_id || ! in_array( $language, $languages, true ) || ! $strings->update_row( $language, $row_id, $original_id, $current, $status, (string) $change['old_value'], (int) $change['old_status'] ) ) {
			return false;
		}
		$id = ChangeLog::record(
			$batch_id,
			'revert',
			array(
				'language'    => $language,
				'original_id' => $original_id,
				'row_id'      => $row_id,
				'old_value'   => $current,
				'old_status'  => $status,
				'new_value'   => (string) $change['old_value'],
				'new_status'  => (int) $change['old_status'],
				'note'        => 'Revert of change ' . (int) $change['id'],
			)
		);
		ChangeLog::mark_reverted( (int) $change['id'], $id );
		return true;
	}

	/**
	 * Whether read-only mode is on (constant SAT_READ_ONLY or option sat_read_only).
	 *
	 * @return bool
	 */
	private static function read_only(): bool {
		return ( defined( 'SAT_READ_ONLY' ) && constant( 'SAT_READ_ONLY' ) ) || self::flag( get_option( self::READ_ONLY_OPTION, false ) );
	}

	/**
	 * Error for a real write in read-only mode. Returned from the execute
	 * callback: the Abilities API replaces permission-callback errors with a
	 * generic one (SCHEMA §12).
	 *
	 * @return WP_Error
	 */
	private static function writes_disabled(): WP_Error {
		return new WP_Error( 'sat_writes_disabled', __( 'Writes are disabled: read-only mode is on (SAT_READ_ONLY or the sat_read_only option). Dry runs still work.', 'string-abilities-for-translatepress' ), array( 'status' => 403 ) );
	}

	/**
	 * Error if the schema is not recognised; installs the change log if needed.
	 *
	 * @param Strings $strings Data access.
	 * @return WP_Error|null
	 */
	private static function write_error( Strings $strings ): ?WP_Error {
		ChangeLog::maybe_install();
		return $strings->schema_error();
	}

	/**
	 * Error if an item names an invalid language, or two items target the same string and language.
	 *
	 * @param Strings                    $strings Data access.
	 * @param list<array<string, mixed>> $items   Input items.
	 * @return WP_Error|null
	 */
	private static function items_error( Strings $strings, array $items ): ?WP_Error {
		$keys = array();
		foreach ( $items as $item ) {
			$keys[] = (int) ( $item['original_id'] ?? 0 ) . '|' . (string) ( $item['language'] ?? '' );
		}
		if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
			return new WP_Error( 'sat_invalid_input', __( 'Each original_id and language pair may appear only once per call.', 'string-abilities-for-translatepress' ), array( 'status' => 400 ) );
		}
		return Plugin::language_error( $strings, array_values( array_unique( array_map( static fn( array $item ): string => (string) ( $item['language'] ?? '' ), $items ) ) ) );
	}

	/**
	 * Boolean from JSON, a query string or an option ('false', '0', 'no' and '' are false).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function flag( $value ): bool {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Exposed translation and status.
	 *
	 * @param string $translated Translation.
	 * @param int    $status     TranslatePress status value.
	 * @return array{translated: string, status: string}
	 */
	private static function value( string $translated, int $status ): array {
		return array(
			'translated' => $translated,
			'status'     => Strings::status_label( $status ),
		);
	}

	/**
	 * One error or warning.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array{code: string, message: string}
	 */
	private static function issue( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
