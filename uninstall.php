<?php
/**
 * Uninstall: drop the change-log table, remove the plugin's options, role and
 * capabilities. TranslatePress data is never touched.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'sat_changes' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table.

remove_role( 'translation_agent' );

$sat_administrator = get_role( 'administrator' );
if ( $sat_administrator ) {
	$sat_administrator->remove_cap( 'sat_read' );
	$sat_administrator->remove_cap( 'sat_manage' );
}

delete_option( 'sat_schema_check' );
delete_option( 'sat_db_version' );
delete_option( 'sat_read_only' );
