<?php
/**
 * Tests for the RTL bidi isolation helpers.
 *
 * Run from the plugin root:
 *     php tests/bidi.php
 *
 * No WordPress: `esc_html()` is stubbed with the same entity set WordPress uses
 * for the characters that matter here, so the assertions below pin the exact
 * markup the templates emit.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal esc_html(): WordPress uses htmlspecialchars with ENT_QUOTES + double
// encoding, which is what the Bidi helper relies on.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', true );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

require_once __DIR__ . '/../includes/Frontend/RateFormatter.php';
require_once __DIR__ . '/../includes/Frontend/Bidi.php';

use RateBridge\Frontend\Bidi;

/**
 * PASS/FAIL collector.
 */
final class BidiAssert {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Assert two values are identical.
	 *
	 * @param string $label    Description.
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @return void
	 */
	public static function same( string $label, mixed $expected, mixed $actual ): void {
		if ( $expected === $actual ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );

			return;
		}

		++self::$failed;
		printf(
			"  FAIL  %s\n          expected: %s\n          actual:   %s\n",
			$label,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}

	/**
	 * Assert a condition.
	 *
	 * @param string $label Description.
	 * @param bool   $ok    Condition.
	 * @param string $info  Extra info.
	 * @return void
	 */
	public static function check( string $label, bool $ok, string $info = '' ): void {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );

			return;
		}

		++self::$failed;
		printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
	}
}

echo "RateBridge Woo — bidi isolation\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] isolate() wraps and escapes\n";
/* ------------------------------------------------------------------------- */

BidiAssert::same(
	'plain date is wrapped',
	'<bdi dir="ltr">2026-10-29 17:23</bdi>',
	Bidi::isolate( '2026-10-29 17:23' )
);

BidiAssert::same(
	'empty input yields an empty string (no stray tag)',
	'',
	Bidi::isolate( '' )
);

BidiAssert::same(
	'markup in the value is escaped, not injected',
	'<bdi dir="ltr">&lt;script&gt;alert(1)&lt;/script&gt;</bdi>',
	Bidi::isolate( '<script>alert(1)</script>' )
);

BidiAssert::same(
	'quotes are escaped',
	'<bdi dir="ltr">a&quot;b&#039;c</bdi>',
	Bidi::isolate( 'a"b\'c' )
);

BidiAssert::same(
	'ampersand is escaped',
	'<bdi dir="ltr">A &amp; B</bdi>',
	Bidi::isolate( 'A & B' )
);

// isolate_escaped() must NOT double-escape.
BidiAssert::same(
	'isolate_escaped does not re-escape',
	'<bdi dir="ltr">&lt;b&gt;</bdi>',
	Bidi::isolate_escaped( '&lt;b&gt;' )
);

/* ------------------------------------------------------------------------- */
echo "\n[2] Parenthesised numbers keep their brackets in place\n";
/* ------------------------------------------------------------------------- */

// The rounding preview used to read «(−1,234,567)» with the bracket on the wrong
// side. The isolate is the fix: the whole fragment, brackets included, is LTR.
BidiAssert::same(
	'parenthesised grouped number stays one LTR run',
	'<bdi dir="ltr">(1,234,567)</bdi>',
	Bidi::isolate( '(1,234,567)' )
);

BidiAssert::check(
	'opening bracket precedes the digits in the markup',
	str_contains( Bidi::isolate( '(1,234,567)' ), '(<bdi' ) === false
);

$paren = Bidi::isolate( '(1,234,567)' );
BidiAssert::check( 'bracket comes before digits', strpos( $paren, '(' ) < strpos( $paren, '1' ) );
BidiAssert::check( 'closing bracket comes after digits', strpos( $paren, ')' ) > strpos( $paren, '7' ) );

/* ------------------------------------------------------------------------- */
echo "\n[3] number(): grouping and Persian digits\n";
/* ------------------------------------------------------------------------- */

BidiAssert::same(
	'integer is grouped',
	'<bdi dir="ltr">1,234,567</bdi>',
	Bidi::number( 1234567 )
);

BidiAssert::same(
	'float with decimals',
	'<bdi dir="ltr">1,234.50</bdi>',
	Bidi::number( 1234.5, false, 2 )
);

BidiAssert::same(
	'negative stays a single run',
	'<bdi dir="ltr">-1,234,567</bdi>',
	Bidi::number( -1234567 )
);

BidiAssert::same(
	'Persian digits inside the isolate (separators preserved)',
	'<bdi dir="ltr">۱,۲۳۴,۵۶۷</bdi>',
	Bidi::number( 1234567, true )
);

BidiAssert::same(
	'numeric string is passed through',
	'<bdi dir="ltr">271500</bdi>',
	Bidi::number( '271500' )
);

/* ------------------------------------------------------------------------- */
echo "\n[4] code() and ltr()\n";
/* ------------------------------------------------------------------------- */

BidiAssert::same(
	'currency code',
	'<bdi dir="ltr">GOLD18</bdi>',
	Bidi::code( 'GOLD18' )
);

BidiAssert::same(
	'cron hook',
	'<bdi dir="ltr">rbw_update_rates</bdi>',
	Bidi::code( 'rbw_update_rates' )
);

BidiAssert::same(
	'ltr label',
	'<bdi dir="ltr">JSON</bdi>',
	Bidi::ltr( 'JSON' )
);

BidiAssert::same( 'empty code', '', Bidi::code( '' ) );

/* ------------------------------------------------------------------------- */
echo "\n[5] Isolation only affects the fragment\n";
/* ------------------------------------------------------------------------- */

// A Persian sentence containing an isolated date must keep its own words in
// logical order — the helper never touches the surrounding text.
$sentence = sprintf(
	'آخرین به‌روزرسانی: %s',
	Bidi::isolate( '2026-10-29 17:23' )
);

BidiAssert::check(
	'the Persian prefix is untouched',
	str_starts_with( $sentence, 'آخرین به‌روزرسانی: <bdi dir="ltr">' ),
	$sentence
);

BidiAssert::check(
	'the fragment is isolated exactly once',
	1 === substr_count( $sentence, '<bdi' ) && 1 === substr_count( $sentence, '</bdi>' )
);

BidiAssert::check(
	'no invisible bidi control characters are emitted',
	false === str_contains( $sentence, "\u{2068}" ) && false === str_contains( $sentence, "\u{2069}" )
);

BidiAssert::check(
	'no <bdo> override is used (isolate is preferred)',
	! str_contains( $sentence, '<bdo' )
);

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", BidiAssert::$passed, BidiAssert::$failed );

exit( BidiAssert::$failed > 0 ? 1 : 0 );
