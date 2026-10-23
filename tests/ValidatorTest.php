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
	 * The same tags and attributes, reordered, with translated text pass.
	 */
	public function testIdenticalMarkupPasses(): void {
		$result = Validator::check(
			'Read <a href="/terms" class="link">the terms</a> before <strong>booking</strong>.',
			'Lisez <a class="link" href="/terms">les conditions</a> avant de <strong>réserver</strong>.'
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * An added script tag is an HTML mismatch.
	 */
	public function testAddedScriptIsRejected(): void {
		$this->assertSame( array( 'sat_html_mismatch' ), $this->errors( 'Welcome', 'Bienvenue<script>alert(1)</script>' ) );
	}

	/**
	 * An added event handler is an HTML mismatch.
	 */
	public function testAddedOnclickIsRejected(): void {
		$this->assertSame( array( 'sat_html_mismatch' ), $this->errors( '<b>Open</b>', '<b onclick="steal()">Ouvert</b>' ) );
	}

	/**
	 * A changed link target is an HTML mismatch.
	 */
	public function testChangedHrefIsRejected(): void {
		$this->assertSame( array( 'sat_html_mismatch' ), $this->errors( '<a href="/contact">Contact us</a>', '<a href="https://example.com/">Contactez-nous</a>' ) );
	}

	/**
	 * A dropped tag is an HTML mismatch.
	 */
	public function testDroppedTagIsRejected(): void {
		$this->assertSame( array( 'sat_html_mismatch' ), $this->errors( 'Line one<br>Line two', 'Ligne un Ligne deux' ) );
	}

	/**
	 * A renamed attribute is an HTML mismatch.
	 */
	public function testRenamedAttributeIsRejected(): void {
		$this->assertSame( array( 'sat_html_mismatch' ), $this->errors( '<span class="note">Note</span>', '<span id="note">Remarque</span>' ) );
	}

	/**
	 * A dropped printf placeholder is a placeholder mismatch; %% is not a placeholder.
	 */
	public function testPlaceholderCountMismatchIsRejected(): void {
		$this->assertSame( array( 'sat_placeholder_mismatch' ), $this->errors( '%1$s rooms from %2$s', '%1$s chambres' ) );
		$this->assertSame( array(), $this->errors( '%s guests, 100%% sure', '%s invités, sûr à 100%%' ) );
	}

	/**
	 * Invalid UTF-8 is rejected.
	 */
	public function testInvalidUtf8IsRejected(): void {
		$this->assertSame( array( 'sat_invalid_utf8' ), $this->errors( 'Hello', "Bonjour \xC3\x28" ) );
	}

	/**
	 * A translation over the maximum length is rejected.
	 */
	public function testOverLengthIsRejected(): void {
		$this->assertSame( array( 'sat_too_long' ), $this->errors( 'Long text', str_repeat( 'é', Validator::MAX_LENGTH + 1 ) ) );
	}

	/**
	 * A number that is not in the original is a warning, not an error.
	 */
	public function testInventedNumberWarns(): void {
		$result = Validator::check( 'Open every day', 'Ouvert tous les jours de 9 h à 18 h' );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'sat_numbers_mismatch' ), $this->codes( $result['warnings'] ) );
	}

	/**
	 * A number dropped from the original is a warning; entities are not numbers.
	 */
	public function testDroppedNumberWarns(): void {
		$this->assertSame( array( 'sat_numbers_mismatch' ), $this->warnings( 'Rooms from 80 euros', 'Chambres à partir de euros' ) );
		$this->assertSame( array(), $this->warnings( 'Don&#8217;t miss it', "N'oubliez pas" ) );
	}

	/**
	 * A translation equal to the original, after normalisation, is a warning.
	 */
	public function testIdenticalToOriginalWarns(): void {
		$this->assertSame( array( 'sat_identical_to_original' ), $this->warnings( 'Fish &amp; chips', 'Fish & chips' ) );
	}

	/**
	 * A protected term present in the original but altered in the translation is a warning.
	 */
	public function testProtectedTermMissingWarns(): void {
		$result = Validator::check( 'Book at Example Lodge', 'Réservez au Example lodge', array( 'Example Lodge', 'Absent Term' ) );

		$this->assertSame( array( 'sat_protected_term_missing' ), $this->codes( $result['warnings'] ) );
	}

	/**
	 * A protected term kept verbatim raises nothing.
	 */
	public function testProtectedTermPresentPasses(): void {
		$result = Validator::check( 'Book at Example Lodge', 'Réservez au Example Lodge', array( 'Example Lodge' ) );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * A bare & becomes &amp;; valid named and numeric entities are kept.
	 */
	public function testBareAmpersandIsEncoded(): void {
		$this->assertSame(
			'Fish &amp; chips &amp;co &amp;fake; &amp; &#8217; &#x2019; &nbsp;',
			Validator::encode_ampersands( 'Fish & chips &co &fake; &amp; &#8217; &#x2019; &nbsp;' )
		);
	}

	/**
	 * Plain text without markup, numbers or placeholders passes.
	 */
	public function testPlainTextPasses(): void {
		$result = Validator::check( 'Good morning', 'Bonjour' );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
	}

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

	/**
	 * Error codes of a check.
	 *
	 * @param string $original   Original.
	 * @param string $translated Translation.
	 * @return list<string>
	 */
	private function errors( string $original, string $translated ): array {
		return $this->codes( Validator::check( $original, $translated )['errors'] );
	}

	/**
	 * Warning codes of a check.
	 *
	 * @param string $original   Original.
	 * @param string $translated Translation.
	 * @return list<string>
	 */
	private function warnings( string $original, string $translated ): array {
		return $this->codes( Validator::check( $original, $translated )['warnings'] );
	}

	/**
	 * Codes of a list of issues.
	 *
	 * @param list<array{code: string, message: string}> $issues Errors or warnings.
	 * @return list<string>
	 */
	private function codes( array $issues ): array {
		return array_column( $issues, 'code' );
	}
}
