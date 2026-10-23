<?php
/**
 * Tests for Validator.
 *
 * @package StringAbilities
 */

declare(strict_types=1);

namespace StringAbilities\Tests;

use PHPUnit\Framework\TestCase;
use StringAbilities\Validator;

/**
 * Unit tests for Validator (plain PHP, no WordPress).
 */
final class ValidatorTest extends TestCase {

	/**
	 * An entity in stored text matches the literal character.
	 */
	public function testNormalizeEntityMatchesLiteral(): void {
		$this->assertSame(
			Validator::normalize( 'Fish & chips' ),
			Validator::normalize( 'Fish &amp; chips' )
		);
	}

	/**
	 * A typographic apostrophe, as an entity or a character, matches a straight one.
	 */
	public function testNormalizeTypographicApostropheMatchesStraight(): void {
		$expected = "Don't stop";

		$this->assertSame( $expected, Validator::normalize( 'Don&#8217;t stop' ) );
		$this->assertSame( $expected, Validator::normalize( "Don\u{2019}t stop" ) );
		$this->assertSame( $expected, Validator::normalize( "Don't stop" ) );
	}

	/**
	 * A typographic ellipsis, as an entity or a character, matches three dots.
	 */
	public function testNormalizeEllipsisMatchesThreeDots(): void {
		$this->assertSame( 'Wait...', Validator::normalize( 'Wait&hellip;' ) );
		$this->assertSame( 'Wait...', Validator::normalize( "Wait\u{2026}" ) );
	}

	/**
	 * Non-breaking spaces, repeated whitespace and surrounding blanks collapse to single spaces.
	 */
	public function testNormalizeNonBreakingSpaceMatchesPlainSpace(): void {
		$this->assertSame( 'Prix : 10 €', Validator::normalize( 'Prix&nbsp;: 10' . "\u{202F}" . '€' ) );
		$this->assertSame( 'one two three', Validator::normalize( "  one\u{00A0}\u{00A0}two\n\tthree " ) );
	}

	/**
	 * Accents and case are kept, so different words stay different.
	 */
	public function testNormalizeKeepsAccentsAndCase(): void {
		$this->assertSame( 'Élévation', Validator::normalize( '&Eacute;l&eacute;vation' ) );
		$this->assertNotSame( Validator::normalize( 'resume' ), Validator::normalize( 'résumé' ) );
		$this->assertNotSame( Validator::normalize( 'Hello' ), Validator::normalize( 'hello' ) );
	}
}
