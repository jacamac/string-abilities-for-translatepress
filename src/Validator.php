<?php
/**
 * Pure text functions, no WordPress calls.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

namespace StringAbilities;

/**
 * Text normalisation for lookups (REQUIREMENTS §5.4).
 */
final class Validator {

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
}
