<?php
/**
 * Pure text functions, no WordPress calls.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

namespace StringAbilities;

/**
 * Translation checks (REQUIREMENTS §5.3) and text normalisation for lookups (§5.4).
 */
final class Validator {

	/**
	 * Maximum translation length in characters.
	 */
	public const MAX_LENGTH = 10000;

	/**
	 * Attributes whose values must be identical to the original's: they carry
	 * links, sources or code. Event handlers (on*) are checked separately.
	 */
	private const LOCKED_ATTRIBUTES = array( 'href', 'src', 'action', 'style' );

	/**
	 * A tag: optional closing slash, name, then the attribute text.
	 */
	private const TAG = '/<\s*(\/?)\s*([a-z][a-z0-9-]*)([^>]*)>/i';

	/**
	 * An attribute name with an optional double-quoted, single-quoted or bare value.
	 */
	private const ATTRIBUTE = '/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/';

	/**
	 * A printf token such as %s, %d, %1$s or %05.2f (%% is removed first).
	 */
	private const PLACEHOLDER = '/%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

	/**
	 * TranslatePress's sprintf token for gettext strings, copied from
	 * TRP_Process_Gettext::is_sprintf_compatible() (TranslatePress 3.3.8,
	 * includes/gettext/class-process-gettext.php:1065).
	 */
	private const GETTEXT_PLACEHOLDER = "/%(?:\\d+\\\$)?[-+0 #]*(?:'.)?\\d*(?:\\.\\d+)?[bcdeEfFgGhHosuxX%]/";

	/**
	 * Typographic characters and their plain equivalents.
	 */
	private const PLAIN_EQUIVALENTS = array(
		"\u{2018}" => "'", // Left single quotation mark.
		"\u{2019}" => "'", // Right single quotation mark, typographic apostrophe.
		"\u{201A}" => "'", // Single low-9 quotation mark.
		"\u{201B}" => "'", // Single high-reversed-9 quotation mark.
		"\u{2032}" => "'", // Prime.
		"\u{201C}" => '"', // Left double quotation mark.
		"\u{201D}" => '"', // Right double quotation mark.
		"\u{201E}" => '"', // Double low-9 quotation mark.
		"\u{201F}" => '"', // Double high-reversed-9 quotation mark.
		"\u{2033}" => '"', // Double prime.
		"\u{2010}" => '-', // Hyphen.
		"\u{2011}" => '-', // Non-breaking hyphen.
		"\u{2012}" => '-', // Figure dash.
		"\u{2013}" => '-', // En dash.
		"\u{2014}" => '-', // Em dash.
		"\u{2212}" => '-', // Minus sign.
		"\u{2026}" => '...', // Horizontal ellipsis.
		"\u{00A0}" => ' ', // No-break space.
		"\u{2007}" => ' ', // Figure space.
		"\u{2009}" => ' ', // Thin space.
		"\u{202F}" => ' ', // Narrow no-break space.
	);

	/**
	 * Check a translation against its original. Errors block the write;
	 * warnings never do. The caller still passes the text through wp_kses
	 * and TranslatePress's sanitising afterwards.
	 *
	 * @param string   $original        Original as stored by TranslatePress.
	 * @param string   $translated      Proposed translation.
	 * @param string[] $protected_terms Terms that must survive verbatim when present in the original.
	 * @param bool     $gettext         Whether the string is a gettext string: placeholders then follow TranslatePress's gettext rule.
	 * @return array{errors: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>}
	 */
	public static function check( string $original, string $translated, array $protected_terms = array(), bool $gettext = false ): array {
		$errors   = array();
		$warnings = array();

		if ( ! mb_check_encoding( $translated, 'UTF-8' ) ) {
			$errors[] = self::issue( 'sat_invalid_utf8', 'The translation is not valid UTF-8.' );
			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}
		if ( mb_strlen( $translated ) > self::MAX_LENGTH ) {
			$errors[] = self::issue( 'sat_too_long', 'The translation is longer than ' . self::MAX_LENGTH . ' characters.' );
		}
		if ( self::tags( $original ) !== self::tags( $translated ) ) {
			$errors[] = self::issue( 'sat_html_mismatch', 'Tags or attributes differ from the original, or a link, source, style or event-handler value changed.' );
		}
		if ( $gettext && ! self::sprintf_compatible( $translated, $original ) ) {
			$errors[] = self::issue( 'sat_placeholder_mismatch', 'The printf placeholders (%s, %d, %1$s…) differ from the original; they must be the same tokens, in any order. TranslatePress would not display this translation.' );
		} elseif ( ! $gettext && self::placeholder_count( $original ) !== self::placeholder_count( $translated ) ) {
			$errors[] = self::issue( 'sat_placeholder_mismatch', 'The number of printf placeholders (%s, %d, %1$s…) differs from the original.' );
		}

		if ( self::numbers( $original ) !== self::numbers( $translated ) ) {
			$warnings[] = self::issue( 'sat_numbers_mismatch', 'A number appears in the translation but not in the original, or the reverse.' );
		}
		if ( self::normalize( $original ) === self::normalize( $translated ) ) {
			$warnings[] = self::issue( 'sat_identical_to_original', 'The translation is identical to the original.' );
		}
		// Case-sensitive, on normalised text so that &amp; and & are the same term.
		foreach ( $protected_terms as $term ) {
			$needle = self::normalize( $term );
			if ( '' !== $needle && str_contains( self::normalize( $original ), $needle ) && ! str_contains( self::normalize( $translated ), $needle ) ) {
				$warnings[] = self::issue( 'sat_protected_term_missing', 'Protected term missing or altered: ' . $term );
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Encode every & that does not start a valid HTML entity as &amp;, as
	 * TranslatePress's sanitising (wp_kses) would.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function encode_ampersands( string $text ): string {
		return (string) preg_replace_callback(
			'/&(?:#\d+;|#x[0-9a-f]+;|[a-z][a-z0-9]*;)?/i',
			static function ( array $found ): string {
				$entity = $found[0];
				if ( '&' === $entity || html_entity_decode( $entity, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === $entity ) {
					return '&amp;' . substr( $entity, 1 );
				}
				return $entity;
			},
			$text
		);
	}

	/**
	 * The wp_kses allowlist of an original: its tags with their attributes.
	 *
	 * @param string $original Original as stored by TranslatePress.
	 * @return array<string, array<string, bool>> Attribute names by tag name.
	 */
	public static function allowed_html( string $original ): array {
		preg_match_all( self::TAG, $original, $tags, PREG_SET_ORDER );
		$allowed = array();
		foreach ( $tags as $tag ) {
			$name             = strtolower( $tag[2] );
			$allowed[ $name ] = $allowed[ $name ] ?? array();
			preg_match_all( self::ATTRIBUTE, $tag[3], $attributes, PREG_SET_ORDER );
			foreach ( $attributes as $attribute ) {
				$allowed[ $name ][ strtolower( $attribute[1] ) ] = true;
			}
		}
		return $allowed;
	}

	/**
	 * Normalise text for comparison: decode HTML entities, map typographic
	 * apostrophes, quotes, dashes, the ellipsis and non-breaking spaces to plain ones,
	 * collapse whitespace and trim. Case and accents are kept.
	 *
	 * @param string $text Text as stored by TranslatePress or typed by a caller.
	 * @return string Normalised text.
	 */
	public static function normalize( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtr( $text, self::PLAIN_EQUIVALENTS );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Sorted tag signatures: name, sorted attribute names, and the decoded
	 * values of locked and on* attributes. Sorted because word order, and so
	 * tag order, may change in a translation.
	 *
	 * @param string $text HTML or plain text.
	 * @return list<string>
	 */
	private static function tags( string $text ): array {
		preg_match_all( self::TAG, $text, $tags, PREG_SET_ORDER );
		$signatures = array();
		foreach ( $tags as $tag ) {
			preg_match_all( self::ATTRIBUTE, $tag[3], $attributes, PREG_SET_ORDER );
			$names  = array();
			$locked = array();
			foreach ( $attributes as $attribute ) {
				$name    = strtolower( $attribute[1] );
				$names[] = $name;
				if ( in_array( $name, self::LOCKED_ATTRIBUTES, true ) || str_starts_with( $name, 'on' ) ) {
					$value    = ( $attribute[2] ?? '' ) . ( $attribute[3] ?? '' ) . ( $attribute[4] ?? '' );
					$locked[] = $name . '=' . html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
			sort( $names );
			sort( $locked );
			$signatures[] = $tag[1] . strtolower( $tag[2] ) . ' ' . implode( ' ', $names ) . "\n" . implode( "\n", $locked );
		}
		sort( $signatures );
		return $signatures;
	}

	/**
	 * Number of printf placeholders, ignoring the literal %%.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function placeholder_count( string $text ): int {
		return (int) preg_match_all( self::PLACEHOLDER, str_replace( '%%', '', $text ) );
	}

	/**
	 * TranslatePress's gettext placeholder rule, mirrored exactly from
	 * TRP_Process_Gettext::is_sprintf_compatible() (TranslatePress 3.3.8,
	 * includes/gettext/class-process-gettext.php:1052-1089): the sorted token
	 * lists, without %%, must be equal. TranslatePress silently ignores a
	 * stored translation that fails it.
	 *
	 * @param string $translated Translation.
	 * @param string $reference  Original, or the plural original for plural forms above 0.
	 * @return bool
	 */
	private static function sprintf_compatible( string $translated, string $reference ): bool {
		if ( ! str_contains( $reference, '%' ) && ! str_contains( $translated, '%' ) ) {
			return true;
		}
		$tokens = static function ( string $text ): array {
			preg_match_all( self::GETTEXT_PLACEHOLDER, $text, $matches );
			$found = array_values( array_filter( $matches[0], static fn( string $token ): bool => '%%' !== $token ) );
			sort( $found );
			return $found;
		};
		return $tokens( $reference ) === $tokens( $translated );
	}

	/**
	 * Distinct digit sequences outside tags and entities, sorted.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	private static function numbers( string $text ): array {
		preg_match_all( '/\d+/', self::normalize( (string) preg_replace( self::TAG, ' ', $text ) ), $matches );
		$numbers = array_values( array_unique( $matches[0] ) );
		sort( $numbers );
		return $numbers;
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
