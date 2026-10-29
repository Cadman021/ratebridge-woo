<?php
/**
 * Tests for the pure Gregorian ↔ Jalali converter.
 *
 * Run from the plugin root:
 *     php tests/jalali.php
 *
 * No WordPress: the class is pure PHP. Known date pairs are the authority here —
 * each Gregorian date below is a real Iranian new-year boundary or a date that
 * appears in the plugin's own fixtures.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/Core/Jalali.php';

use RateBridge\Core\Jalali;

/**
 * PASS/FAIL collector.
 */
final class JalaliAssert {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Assert two values are identical.
	 *
	 * @param string $label    Description.
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
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
			wp_json_like( $expected ),
			wp_json_like( $actual )
		);
	}
}

/**
 * Readable rendering for failure output.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_like( mixed $value ): string {
	if ( is_array( $value ) ) {
		return '[' . implode( ', ', array_map( 'wp_json_like', $value ) ) . ']';
	}

	return var_export( $value, true );
}

echo "RateBridge Woo — Jalali date conversion\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Known Gregorian → Jalali pairs\n";
/* ------------------------------------------------------------------------- */

$pairs = array(
	// Gregorian          => Jalali.
	array( array( 2026, 10, 29 ), array( 1405, 8, 7 ) ),  // The Nerkhsanj fixture date.
	array( array( 2026, 10, 28 ), array( 1405, 8, 6 ) ),
	array( array( 2026, 3, 21 ), array( 1405, 1, 1 ) ),   // Nowruz 1405.
	array( array( 2025, 3, 21 ), array( 1404, 1, 1 ) ),   // Nowruz 1404.
	array( array( 2024, 3, 20 ), array( 1403, 1, 1 ) ),   // Nowruz 1403 (leap year).
	array( array( 2016, 3, 20 ), array( 1395, 1, 1 ) ),   // Nowruz 1395.
	array( array( 2027, 3, 21 ), array( 1406, 1, 1 ) ),   // Nowruz 1406.
	array( array( 2000, 1, 1 ), array( 1378, 10, 11 ) ),
	array( array( 1979, 2, 11 ), array( 1357, 11, 22 ) ),  // The Islamic Revolution date.
	array( array( 2026, 12, 31 ), array( 1405, 10, 10 ) ),
);

foreach ( $pairs as [ $greg, $jalal ] ) {
	$label = sprintf(
		'%04d-%02d-%02d → %d/%02d/%02d',
		$greg[0],
		$greg[1],
		$greg[2],
		$jalal[0],
		$jalal[1],
		$jalal[2]
	);

	JalaliAssert::same( $label, $jalal, Jalali::from_gregorian( $greg[0], $greg[1], $greg[2] ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[2] Known Jalali → Gregorian pairs (the inverse)\n";
/* ------------------------------------------------------------------------- */

foreach ( $pairs as [ $greg, $jalal ] ) {
	$label = sprintf(
		'%d/%02d/%02d → %04d-%02d-%02d',
		$jalal[0],
		$jalal[1],
		$jalal[2],
		$greg[0],
		$greg[1],
		$greg[2]
	);

	JalaliAssert::same( $label, $greg, Jalali::to_gregorian( $jalal[0], $jalal[1], $jalal[2] ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[3] Leap years\n";
/* ------------------------------------------------------------------------- */

// Official Iranian calendar: 1403 and 1408 are leap, 1404/1405/1406 are not.
$leap = array(
	1403 => true,
	1404 => false,
	1405 => false,
	1406 => false,
	1407 => false,
	1408 => true,
	1399 => true,
	1395 => true,   // 1395 is a leap year.
	1375 => true,
	1370 => true,
);

foreach ( $leap as $year => $expected ) {
	JalaliAssert::same(
		sprintf( '%d is %s leap', $year, $expected ? '' : 'not' ),
		$expected,
		Jalali::is_leap_year( $year )
	);
}

JalaliAssert::same( 'leap year has 366 days', 366, Jalali::days_in_jalali_year( 1403 ) );
JalaliAssert::same( 'common year has 365 days', 365, Jalali::days_in_jalali_year( 1404 ) );
JalaliAssert::same( 'Esfand in a leap year has 30 days', 30, Jalali::month_days( 12, 1403 ) );
JalaliAssert::same( 'Esfand in a common year has 29 days', 29, Jalali::month_days( 12, 1404 ) );
JalaliAssert::same( 'Farvardin has 31 days', 31, Jalali::month_days( 1, 1404 ) );
JalaliAssert::same( 'Shahrivar has 31 days', 31, Jalali::month_days( 6, 1404 ) );
JalaliAssert::same( 'Mehr has 30 days', 30, Jalali::month_days( 7, 1404 ) );
JalaliAssert::same( 'invalid month has 0 days', 0, Jalali::month_days( 13, 1404 ) );

// 1403/12/30 exists, 1404/12/30 does not.
JalaliAssert::same(
	'1403/12/30 is 2025-03-20',
	array( 2025, 3, 20 ),
	Jalali::to_gregorian( 1403, 12, 30 )
);

JalaliAssert::same(
	'1404/12/29 is the last day of 1404 → 2026-03-20',
	array( 2026, 3, 20 ),
	Jalali::to_gregorian( 1404, 12, 29 )
);

/* ------------------------------------------------------------------------- */
echo "\n[4] Round-trip over 10 Gregorian years\n";
/* ------------------------------------------------------------------------- */

$mismatch = 0;
$start    = gmmktime( 0, 0, 0, 1, 1, 2020 );

for ( $d = 0; $d < 3653; $d++ ) {
	$ts = $start + $d * 86400;

	[ $y, $m, $day ] = array_map( 'intval', explode( '-', gmdate( 'Y-n-j', $ts ) ) );

	[ $jy, $jm, $jd ] = Jalali::from_gregorian( $y, $m, $day );

	if ( array( $y, $m, $day ) !== Jalali::to_gregorian( $jy, $jm, $jd ) ) {
		++$mismatch;
	}
}

JalaliAssert::same( 'every day of 2020-2029 round-trips exactly', 0, $mismatch );

/* ------------------------------------------------------------------------- */
echo "\n[5] format() with the plugin token set\n";
/* ------------------------------------------------------------------------- */

JalaliAssert::same( 'Y/m/d', '1405/08/07', Jalali::format( 2026, 10, 29, 'Y/m/d' ) );
JalaliAssert::same( 'Y-m-d', '1405-08-07', Jalali::format( 2026, 10, 29, 'Y-m-d' ) );
JalaliAssert::same( 'j brings no leading zero (m still pads)', '1405/08/7', Jalali::format( 2026, 10, 29, 'Y/m/j' ) );
JalaliAssert::same( 'two-digit month stays zero-padded', '1405-08-07', Jalali::format( 2026, 10, 29, 'Y-m-d' ) );
JalaliAssert::same( 'literal text is preserved', '1405/08/07 — ساخته شده', Jalali::format( 2026, 10, 29, 'Y/m/d — ساخته شده' ) );

// The hour/minute tokens are handled by the caller (they never change in Jalali),
// so format() must leave them untouched rather than eating them.
JalaliAssert::same( 'unknown tokens pass through', '1405/08/07 H:i', Jalali::format( 2026, 10, 29, 'Y/m/d H:i' ) );

// Escaped characters survive.
JalaliAssert::same( 'escaped token is literal', '1405 Y', Jalali::format( 2026, 10, 29, 'Y \\Y' ) );

/* ------------------------------------------------------------------------- */
echo "\n[6] Julian Day Number helpers\n";
/* ------------------------------------------------------------------------- */

JalaliAssert::same(
	'known JDN for 2000-01-01 (2451545)',
	2451545,
	Jalali::gregorian_to_jdn( 2000, 1, 1 )
);

JalaliAssert::same(
	'JDN round-trips',
	array( 2026, 10, 29 ),
	Jalali::jdn_to_gregorian( Jalali::gregorian_to_jdn( 2026, 10, 29 ) )
);

JalaliAssert::same(
	'proleptic JDN for 1970-01-01 (2440588)',
	2440588,
	Jalali::gregorian_to_jdn( 1970, 1, 1 )
);

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", JalaliAssert::$passed, JalaliAssert::$failed );

exit( JalaliAssert::$failed > 0 ? 1 : 0 );
