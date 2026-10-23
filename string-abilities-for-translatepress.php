<?php
/**
 * Plugin Name:       String Abilities for TranslatePress
 * Plugin URI:        https://github.com/jacamac/string-abilities-for-translatepress
 * Description:       WordPress abilities for reading, reviewing and correcting TranslatePress string translations.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  translatepress-multilingual
 * Author:            jacamac
 * Author URI:        https://github.com/jacamac
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       string-abilities-for-translatepress
 * GitHub Plugin URI: jacamac/string-abilities-for-translatepress
 * GitHub Branch:     main
 * Primary Branch:    main
 *
 * @package StringAbilities
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SAT_VERSION', '0.1.0' );

require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/Strings.php';
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook( __FILE__, array( StringAbilities\Plugin::class, 'activate' ) );

StringAbilities\Plugin::init();
