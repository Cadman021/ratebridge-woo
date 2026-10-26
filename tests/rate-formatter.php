<?php
/**
 * Dependency-free test suite for the frontend rate formatter.
 *
 * Run from the plugin root:
 *     php tests/rate-formatter.php
 *
 * No WordPress and no WooCommerce are loaded: RateFormatter is pure PHP, so
 * every case below runs anywhere PHP 8.1+ is available.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

// RateFormatter guards on ABSPATH; define it so it can be included outside WP.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/Frontend/RateFormatter.php';

use RateBridge\Frontend\RateFormatter;

/**
 * Tiny PASS/FAIL collector.
 */
final class FmtAssert {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Record a boolean check.
	 *
	 * @param string $label Description.
	 * @param bool   $ok    Result.
	 * @param string $info  Extra info printed on failure.
	 * @return bool
	 */
	public static function check( string $label, bool $ok, string $info = '' ): bool {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );
		} else {
			++self::$failed;
			printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
		}

		return $ok;
	}

	/**
	 * Assert a string equality.
	 *
	 * @param string $label    Description.
	 * @param string $actual   Actual value.
	 * @param string $expected Expected value.
	 * @return bool
	 */
	public static function same( string $label, string $actual, string $expected ): bool {
		return self::check(
			sprintf( '%s => "%s"', $label, $expected ),
			$actual === $expected,
			'got "' . $actual . '"'
		);
	}
}

echo "RateBridge Woo — rate formatter\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Number formatting (thousand separators + decimals)\n";
/* ------------------------------------------------------------------------- */
FmtAssert::same( '0 decimals, small', RateFormatter::number( 1234, 0 ), '1,234' );
FmtAssert::same( '0 decimals, millions', RateFormatter::number( 133476000, 0 ), '133,476,000' );
FmtAssert::same( '0 decimals, billion (bitcoin)', RateFormatter::number( 20500000000, 0 ), '20,500,000,000' );
FmtAssert::same( '2 decimals', RateFormatter::number( 1234.5, 2 ), '1,234.50' );
FmtAssert::same( '2 decimals, rounding', RateFormatter::number( 1234.567, 2 ), '1,234.57' );
FmtAssert::same( 'gold gram, 2 decimals', RateFormatter::number( 4526811.25, 2 ), '4,526,811.25' );
FmtAssert::same( 'zero', RateFormatter::number( 0.0, 0 ), '0' );
FmtAssert::same( 'decimals clamped high', RateFormatter::number( 1.5, 99 ), '1.500000' );
FmtAssert::same( 'decimals clamped low', RateFormatter::number( 1.5, -3 ), '2' );
FmtAssert::same( 'NAN returns empty', RateFormatter::number( NAN, 0 ), '' );
FmtAssert::same( 'INF returns empty', RateFormatter::number( INF, 0 ), '' );
FmtAssert::same( 'separators stay ASCII', RateFormatter::number( 1234567.89, 2 ), '1,234,567.89' );

/* ------------------------------------------------------------------------- */
echo "\n[2] Persian digits\n";
/* ------------------------------------------------------------------------- */
FmtAssert::same(
	'ASCII -> Persian',
	RateFormatter::to_persian_digits( '133,476,000' ),
	'۱۳۳,۴۷۶,۰۰۰'
);
FmtAssert::same( 'separators untouched', RateFormatter::to_persian_digits( '1.5' ), '۱.۵' );
FmtAssert::same( 'no digits untouched', RateFormatter::to_persian_digits( 'USD' ), 'USD' );
FmtAssert::same( 'mixed text', RateFormatter::to_persian_digits( 'USD 1,234' ), 'USD ۱,۲۳۴' );
FmtAssert::same( 'not converted when off', RateFormatter::maybe_persian( '20,500', false ), '20,500' );
FmtAssert::same( 'converted when on', RateFormatter::maybe_persian( '20,500', true ), '۲۰,۵۰۰' );
FmtAssert::same( 'all ten digits', RateFormatter::to_persian_digits( '0123456789' ), '۰۱۲۳۴۵۶۷۸۹' );

/* ------------------------------------------------------------------------- */
echo "\n[3] Unit labels\n";
/* ------------------------------------------------------------------------- */
FmtAssert::same( 'toman', RateFormatter::unit_label( 'toman' ), 'تومان' );
FmtAssert::same( 'rial', RateFormatter::unit_label( 'rial' ), 'ریال' );
FmtAssert::same( 'unknown falls back to toman', RateFormatter::unit_label( 'xyz' ), 'تومان' );
FmtAssert::same( 'custom labels', RateFormatter::unit_label( 'rial', 'T', 'R' ), 'R' );

/* ------------------------------------------------------------------------- */
echo "\n[4] Relative time boundaries\n";
/* ------------------------------------------------------------------------- */
$now = 1793000000;

/**
 * Assert one time_ago_parts() case.
 *
 * @param string $label    Description.
 * @param int    $ago      Seconds before now.
 * @param string $unit     Expected unit.
 * @param int    $value    Expected value.
 * @return void
 */
function time_case( string $label, int $ago, string $unit, int $value ): void {
	global $now;

	$parts = RateFormatter::time_ago_parts( $now - $ago, $now );

	FmtAssert::check(
		sprintf( '%s => %s %d', $label, $unit, $value ),
		$parts['unit'] === $unit && $parts['value'] === $value,
		sprintf( 'got %s %d', $parts['unit'], $parts['value'] )
	);
}

time_case( '0 s', 0, 'now', 0 );
time_case( '1 s', 1, 'now', 0 );
time_case( '59 s', 59, 'now', 0 );
time_case( '60 s', 60, 'minute', 1 );
time_case( '119 s', 119, 'minute', 1 );
time_case( '120 s', 120, 'minute', 2 );
time_case( '59 min', 59 * 60, 'minute', 59 );
time_case( '60 min', 3600, 'hour', 1 );
time_case( '90 min', 5400, 'hour', 1 );
time_case( '23 h', 23 * 3600, 'hour', 23 );
time_case( '24 h', 24 * 3600, 'day', 1 );
time_case( '29 days', 29 * 86400, 'day', 29 );
time_case( '30 days', 30 * 86400, 'month', 1 );
time_case( '59 days', 59 * 86400, 'month', 1 );
time_case( '60 days', 60 * 86400, 'month', 2 );
// 12 months is already bucketed as "1 year" (documented: months < 12, else years).
time_case( '359 days', 359 * 86400, 'month', 11 );
time_case( '360 days', 360 * 86400, 'year', 1 );
time_case( '365 days', 365 * 86400, 'year', 1 );
time_case( '730 days', 730 * 86400, 'year', 2 );

// Edge cases.
FmtAssert::check(
	'unknown timestamp (0)',
	'unknown' === RateFormatter::time_ago_parts( 0, $now )['unit']
);
FmtAssert::check(
	'negative timestamp',
	'unknown' === RateFormatter::time_ago_parts( -5, $now )['unit']
);
FmtAssert::check(
	'future timestamp clamps to now',
	'now' === RateFormatter::time_ago_parts( $now + 500, $now )['unit']
);
FmtAssert::same( 'time_ago text', RateFormatter::time_ago( $now - 120, $now ), '2 minute ago' );
FmtAssert::same( 'time_ago now', RateFormatter::time_ago( $now, $now ), 'now' );
FmtAssert::same( 'time_ago unknown', RateFormatter::time_ago( 0, $now ), '—' );

/* ------------------------------------------------------------------------- */
echo "\n[5] Date formatting\n";
/* ------------------------------------------------------------------------- */
$ts = 1793000000; // 2026-10-26 07:33:20 UTC

FmtAssert::same( 'Y-m-d H:i (UTC)', RateFormatter::format_date( $ts, 'Y-m-d H:i', 'UTC' ), '2026-10-26 07:33' );
FmtAssert::same( 'tokens j/H/i', RateFormatter::format_date( $ts, 'j/H/i', 'UTC' ), '26/07/33' );
FmtAssert::same( 'timezone shift', RateFormatter::format_date( $ts, 'H:i', 'Asia/Tehran' ), '11:03' );
FmtAssert::same( 'literal text preserved', RateFormatter::format_date( $ts, 'Y/m/d', 'UTC' ), '2026/10/26' );
FmtAssert::same( 'empty format falls back', RateFormatter::format_date( $ts, '', 'UTC' ), '2026-10-26 07:33' );
FmtAssert::same( 'zero timestamp is empty', RateFormatter::format_date( 0, 'Y-m-d' ), '' );
FmtAssert::same( 'escaped literal', RateFormatter::format_date( $ts, '\\Y=Y', 'UTC' ), 'Y=2026' );

/* ------------------------------------------------------------------------- */
echo "\n[6] Template engine\n";
/* ------------------------------------------------------------------------- */
$values = array(
	'name'         => 'دلار آمریکا',
	'code'         => 'USD',
	'symbol'       => '$',
	'rate'         => '1,234,000',
	'unit'         => 'تومان',
	'updated'      => '2 دقیقه پیش',
	'updated_date' => '2026-10-26 21:00',
	'source'       => 'Navasan',
);

FmtAssert::same(
	'all placeholders',
	RateFormatter::render_template( '%name% (%code%): %rate% %unit%', $values ),
	'دلار آمریکا (USD): 1,234,000 تومان'
);
FmtAssert::same(
	'updated + date',
	RateFormatter::render_template( '%updated% / %updated_date%', $values ),
	'2 دقیقه پیش / 2026-10-26 21:00'
);
FmtAssert::same( 'symbol', RateFormatter::render_template( '%symbol%%rate%', $values ), '$1,234,000' );
FmtAssert::same( 'source', RateFormatter::render_template( '%source%', $values ), 'Navasan' );

// Unknown placeholders survive.
FmtAssert::same(
	'unknown placeholder untouched',
	RateFormatter::render_template( '%name% %nope% %code%', $values ),
	'دلار آمریکا %nope% USD'
);
FmtAssert::same(
	'bare percent untouched',
	RateFormatter::render_template( '100% sure %code%', $values ),
	'100% sure USD'
);

// Missing value for a known placeholder keeps the token (visible typo).
FmtAssert::same(
	'missing value keeps token',
	RateFormatter::render_template( '%source%', array( 'name' => 'x' ) ),
	'%source%'
);

// Empty template.
FmtAssert::same( 'empty template', RateFormatter::render_template( '', $values ), '' );
// No values: template unchanged.
FmtAssert::same( 'no values', RateFormatter::render_template( '%name%', array() ), '%name%' );

// Injection attempts: a replacement value containing a placeholder is NOT expanded.
FmtAssert::same(
	'injection: value is not re-expanded',
	RateFormatter::render_template( '[%name%]', array( 'name' => '%code%', 'code' => 'USD' ) ),
	'[%code%]'
);
FmtAssert::same(
	'injection: value is not re-expanded twice',
	RateFormatter::render_template( '%a%', array( 'a' => '%b%', 'b' => '%a%' ) ),
	'%b%'
);
FmtAssert::same(
	'injection: html in value stays raw text (caller escapes)',
	RateFormatter::render_template( '%name%', array( 'name' => '<script>x</script>' ) ),
	'<script>x</script>'
);
// Placeholder keys may be passed with or without the % delimiters.
FmtAssert::same(
	'keys with delimiters',
	RateFormatter::render_template( '%code%', array( '%code%' => 'USD' ) ),
	'USD'
);

/* ------------------------------------------------------------------------- */
echo "\n[7] Template sanitising (safe HTML subset)\n";
/* ------------------------------------------------------------------------- */
// Without a kses callback everything is stripped to text.
FmtAssert::same(
	'strips tags when no kses',
	RateFormatter::sanitize_template( '<script>alert(1)</script><b>bold</b>' ),
	'bold'
);
FmtAssert::same(
	'keeps placeholders intact',
	RateFormatter::sanitize_template( '<em>%name%</em>' ),
	'%name%'
);
FmtAssert::same( 'trims whitespace', RateFormatter::sanitize_template( "  %name%  " ), '%name%' );
FmtAssert::same( 'empty stays empty', RateFormatter::sanitize_template( '   ' ), '' );

// A fake kses callback mimicking the plugin's allowed list.
$fake_kses = static function ( string $html ): string {
	// Allow <strong>, <em>, <span class>, <br>; drop anything else.
	$html = preg_replace( '#<(?!\/?(?:strong|em|span|br)\b)[^>]*>#i', '', $html ) ?? '';

	return $html;
};

FmtAssert::same(
	'kses keeps strong/em/span/br',
	RateFormatter::sanitize_template( '<strong>%name%</strong> <em>%rate%</em><br><span class="x">%unit%</span>', $fake_kses ),
	'<strong>%name%</strong> <em>%rate%</em><br><span class="x">%unit%</span>'
);
FmtAssert::check(
	'kses drops script',
	! str_contains( RateFormatter::sanitize_template( '<script>bad()</script>ok', $fake_kses ), 'script' ),
	RateFormatter::sanitize_template( '<script>bad()</script>ok', $fake_kses )
);
FmtAssert::check(
	'kses drops onclick attributes via tag removal',
	! str_contains(
		RateFormatter::sanitize_template( '<img src=x onerror=alert(1)>ok', $fake_kses ),
		'onerror'
	)
);

// Length cap.
$long = str_repeat( 'x', 700 );
FmtAssert::check(
	'template capped at 500 chars',
	500 === mb_strlen( RateFormatter::sanitize_template( $long ) ),
	(string) mb_strlen( RateFormatter::sanitize_template( $long ) )
);

/* ------------------------------------------------------------------------- */
echo "\n[8] Placeholder registry\n";
/* ------------------------------------------------------------------------- */
$declared = RateFormatter::placeholders();

foreach ( array( 'name', 'code', 'symbol', 'rate', 'unit', 'updated', 'updated_date', 'source' ) as $expected ) {
	FmtAssert::check( "declares %{$expected}%", in_array( $expected, $declared, true ) );
}

FmtAssert::check( 'exactly 8 placeholders', 8 === count( $declared ), (string) count( $declared ) );

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", FmtAssert::$passed, FmtAssert::$failed );

exit( FmtAssert::$failed > 0 ? 1 : 0 );
