<?php
/**
 * Uninstall: remove the plugin's role, capabilities and options.
 * TranslatePress data is never touched.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

remove_role( 'translation_agent' );

$sat_administrator = get_role( 'administrator' );
if ( $sat_administrator ) {
	$sat_administrator->remove_cap( 'sat_read' );
	$sat_administrator->remove_cap( 'sat_manage' );
}

delete_option( 'sat_schema_check' );
