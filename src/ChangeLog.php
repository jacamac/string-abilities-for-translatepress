<?php
/**
 * Change-log table.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

namespace StringAbilities;

/**
 * The plugin's only table, {prefix}sat_changes (REQUIREMENTS §6): one row per
 * written translation, grouped in batches, with what is needed to revert it.
 */
final class ChangeLog {

	/**
	 * Schema version of the table, stored in the sat_db_version option.
	 */
	public const DB_VERSION = '2';

	/**
	 * Option holding the installed schema version.
	 */
	public const DB_VERSION_OPTION = 'sat_db_version';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sat_changes';
	}

	/**
	 * Create or upgrade the table with dbDelta.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// dbDelta format: two spaces after PRIMARY KEY, one column per line.
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id char(36) NOT NULL,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			language varchar(20) NOT NULL,
			original_id bigint(20) unsigned NOT NULL,
			row_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(20) NOT NULL,
			old_value longtext NOT NULL,
			old_status int(11) NOT NULL,
			new_value longtext NOT NULL,
			new_status int(11) NOT NULL,
			note varchar(200) NOT NULL DEFAULT '',
			reverted_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY created_at (created_at)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Install the table if missing or outdated. Plugin updates do not run the
	 * activation hook, so write abilities call this before use.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Log one written change.
	 *
	 * @param string               $batch_id Batch UUID.
	 * @param string               $action   translate, approve, reset or revert.
	 * @param array<string, mixed> $change   language, original_id, row_id (dictionary row written), old_value, old_status, new_value, new_status, note.
	 * @return int Change id.
	 */
	public static function record( string $batch_id, string $action, array $change ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'batch_id'    => $batch_id,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => get_current_user_id(),
				'language'    => (string) $change['language'],
				'original_id' => (int) $change['original_id'],
				'row_id'      => (int) $change['row_id'],
				'action'      => $action,
				'old_value'   => (string) $change['old_value'],
				'old_status'  => (int) $change['old_status'],
				'new_value'   => (string) $change['new_value'],
				'new_status'  => (int) $change['new_status'],
				'note'        => (string) $change['note'],
			),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a change as reverted by another change.
	 *
	 * @param int $id          Reverted change id.
	 * @param int $reverted_by Id of the change that reverted it.
	 */
	public static function mark_reverted( int $id, int $reverted_by ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'reverted_by' => $reverted_by ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Changes to revert: one change by id, or every change of a batch, newest first.
	 *
	 * @param int    $change_id Change id, or 0.
	 * @param string $batch_id  Batch id, or ''.
	 * @return list<array<string, mixed>> Rows; empty when nothing matches.
	 */
	public static function changes( int $change_id, string $batch_id ): array {
		global $wpdb;
		$sql = 0 !== $change_id
			? $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $change_id )
			: $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %s ORDER BY id DESC', self::table(), $batch_id );
		return array_values( (array) $wpdb->get_results( $sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
	}

	/**
	 * Paged entries, newest first.
	 *
	 * @param string $language Language code, or '' for all.
	 * @param string $batch_id Batch id, or '' for all.
	 * @param string $since    UTC 'Y-m-d H:i:s', or '' for all.
	 * @param int    $page     Page number, from 1.
	 * @param int    $per_page Entries per page.
	 * @return array{entries: list<array<string, mixed>>, total: int}
	 */
	public static function entries( string $language, string $batch_id, string $since, int $page, int $per_page ): array {
		global $wpdb;
		// Only the filters given: MySQL 8 rejects created_at >= '' outright,
		// even inside an OR whose other side is true, and the query then fails.
		$where = '1 = 1';
		$args  = array( self::table() );
		foreach ( array(
			'language = %s'    => $language,
			'batch_id = %s'    => $batch_id,
			'created_at >= %s' => $since,
		) as $condition => $value ) {
			if ( '' !== $value ) {
				$where .= ' AND ' . $condition;
				$args[] = $value;
			}
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is built from literals with placeholders; every value goes through prepare().
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is built from literals with placeholders; every value goes through prepare().
			ARRAY_A
		);
		return array(
			'entries' => array_values( (array) $rows ),
			'total'   => $total,
		);
	}
}
