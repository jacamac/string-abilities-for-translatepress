<?php
/**
 * Plugin Name:       String Abilities for TranslatePress
 * Plugin URI:        https://github.com/jacamac/string-abilities-for-translatepress
 * Description:       WordPress abilities for reading, reviewing and correcting TranslatePress string translations.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  translatepress-multilingual
 * Author:            jacamac
 * Author URI:        https://github.com/jacamac
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       string-abilities-for-translatepress
 *
 * @package StringAbilities
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SAT_VERSION', '0.2.0' );

require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/Strings.php';
require_once __DIR__ . '/src/Plugin.php';

/*
 * Plugin Update Checker: update notifications in the WordPress admin when a
 * new GitHub Release is published (same integration as the owner's other plugins).
 *
 * Default: tagged releases. To install the rolling dev-latest build of a
 * development branch instead, add to wp-config.php:
 *     define( 'SAT_UPDATE_BRANCH', 'develop' );
 */
$sat_puc = __DIR__ . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $sat_puc ) ) {
	require_once $sat_puc;
	$sat_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/jacamac/string-abilities-for-translatepress/',
		__FILE__,
		'string-abilities-for-translatepress'
	);
	if ( defined( 'SAT_UPDATE_BRANCH' ) && SAT_UPDATE_BRANCH ) {
		$sat_checker->setBranch( SAT_UPDATE_BRANCH );
	} else {
		$sat_checker->getVcsApi()->enableReleaseAssets(); // @phpstan-ignore method.notFound (GitHubApi uses ReleaseAssetSupport trait; base Api class return type hides it)
	}

	add_filter(
		'puc_request_info_result-string-abilities-for-translatepress',
		function ( $info ) {
			if ( null === $info ) {
				return $info;
			}
			if ( defined( 'SAT_UPDATE_BRANCH' ) && SAT_UPDATE_BRANCH ) {
				// Branch mode: GitHub names the branch zipball {repo}-{branch}/, so WordPress
				// would install it as a separate plugin. Use the correctly named zip that
				// dev-release.yml attaches to the rolling dev-latest pre-release instead.
				$info->download_url = 'https://github.com/jacamac/string-abilities-for-translatepress/releases/download/dev-latest/string-abilities-for-translatepress.zip';
			} elseif ( ! empty( $info->download_url ) ) {
				// Release mode: take the version from the release download URL
				// (.../releases/download/v1.2.3/... or .../zipball/v1.2.3).
				if ( preg_match( '~(?:releases/download|zipball)/v?([\d.]+)~', $info->download_url, $m ) ) {
					$info->version = $m[1];
				}
			}
			return $info;
		}
	);

	unset( $sat_checker );
}
unset( $sat_puc );

register_activation_hook( __FILE__, array( StringAbilities\Plugin::class, 'activate' ) );

StringAbilities\Plugin::init();
