<?php
/**
 * PHPStan-only declarations of the TranslatePress 3.3.8 API the plugin calls.
 * Signatures from TP/class-translate-press.php, TP/includes/queries/class-query.php and TP/includes/functions.php.
 *
 * @package StringAbilities
 */

define( 'TRP_PLUGIN_VERSION', '3.3.8' );

class TRP_Translate_Press {
	/** @return TRP_Translate_Press */
	public static function get_trp_instance() {}

	/**
	 * @param string $component Component name.
	 * @return mixed
	 */
	public function get_component( $component ) {}
}

class TRP_Query {
	/**
	 * @param string      $language_code    Language code.
	 * @param string|null $default_language Default language code.
	 * @param bool        $only_prefix      Only the prefix.
	 * @return string
	 */
	public function get_table_name( $language_code, $default_language = null, $only_prefix = false ) {}

	/** @return string */
	public function get_table_name_for_original_strings() {}
}

/**
 * @param mixed $filtered        Text.
 * @param bool  $execute_wp_kses Also run wp_kses_post.
 * @return string
 */
function trp_sanitize_string( $filtered, $execute_wp_kses = true ) {}
