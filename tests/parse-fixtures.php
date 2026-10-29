<?php
/**
 * Dependency-free parser test against the real API fixtures.
 *
 * Run from the plugin root:
 *     php tests/parse-fixtures.php
 *
 * No WordPress is loaded. Only the pure parser classes from
 * includes/Providers/Parsing/ are exercised, so this can run anywhere PHP 8.1+
 * is available (CI, a fresh checkout, a developer box).
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

namespace RateBridge\Tests;

use RateBridge\Providers\Parsing\AlanChandParser;
use RateBridge\Providers\Parsing\NetarzParser;
use RateBridge\Providers\Parsing\NerkhsanjParser;
use RateBridge\Providers\Parsing\NavasanParser;
use RateBridge\Providers\Parsing\NumberParser;

// The parsers guard on ABSPATH; define it so they can be included outside WP.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/Providers/Parsing/NumberParser.php';
require_once __DIR__ . '/../includes/Providers/Parsing/NavasanParser.php';
require_once __DIR__ . '/../includes/Providers/Parsing/AlanChandParser.php';
require_once __DIR__ . '/../includes/Providers/Parsing/NetarzParser.php';
require_once __DIR__ . '/../includes/Providers/Parsing/NerkhsanjParser.php';

/**
 * Tiny PASS/FAIL collector.
 */
final class Assert {

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
	 * Assert a rate equals the expected float within a tolerance.
	 *
	 * @param string $label    Description.
	 * @param mixed  $actual   Actual value.
	 * @param float  $expected Expected value.
	 * @return bool
	 */
	public static function rate( string $label, mixed $actual, float $expected ): bool {
		$ok = is_float( $actual ) && abs( $actual - $expected ) < 0.0001;

		return self::check(
			sprintf( '%s => %s', $label, self::fmt( $expected ) ),
			$ok,
			null === $actual ? 'got null (missing/unparsable)' : 'got ' . self::fmt( $actual )
		);
	}

	/**
	 * Format a value for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function fmt( mixed $value ): string {
		if ( null === $value ) {
			return 'null';
		}

		if ( is_float( $value ) ) {
			return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
		}

		return (string) $value;
	}
}

/**
 * Load a fixture file, failing loudly when it is missing or an error envelope.
 *
 * @param string $name File name inside docs/api-samples/.
 * @return string Raw body, or an empty string on failure.
 */
function fixture( string $name ): string {
	$path = __DIR__ . '/../docs/api-samples/' . $name;

	if ( ! is_readable( $path ) ) {
		printf( "  FAIL  fixture missing: docs/api-samples/%s\n", $name );
		++Assert::$failed;
		return '';
	}

	$body    = (string) file_get_contents( $path );
	$decoded = json_decode( $body, true );

	if ( ! is_array( $decoded ) ) {
		printf( "  FAIL  fixture is not valid JSON: %s\n", $name );
		++Assert::$failed;
		return '';
	}

	if ( isset( $decoded['error'] ) ) {
		printf( "  FAIL  fixture is an error payload (%s): %s\n", $name, (string) $decoded['error'] );
		++Assert::$failed;
		return '';
	}

	return $body;
}

echo "RateBridge Woo — parser fixtures\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] NumberParser\n";
/* ------------------------------------------------------------------------- */
Assert::rate( 'string "270000"', NumberParser::to_positive_float( '270000' ), 270000.0 );
Assert::rate( 'int 26051530', NumberParser::to_positive_float( 26051530 ), 26051530.0 );
Assert::rate( 'float 3995.88', NumberParser::to_positive_float( 3995.88 ), 3995.88 );
Assert::rate( 'comma "1,234,567"', NumberParser::to_positive_float( '1,234,567' ), 1234567.0 );
Assert::rate( 'persian digits "۲۷۰۰۰۰"', NumberParser::to_positive_float( '۲۷۰۰۰۰' ), 270000.0 );
Assert::rate( 'arabic-indic "١٢٣٤"', NumberParser::to_positive_float( '١٢٣٤' ), 1234.0 );
Assert::rate( 'padded "  74220  "', NumberParser::to_positive_float( '  74220  ' ), 74220.0 );
Assert::check( 'null rejected', null === NumberParser::to_positive_float( null ) );
Assert::check( 'empty string rejected', null === NumberParser::to_positive_float( '' ) );
Assert::check( 'zero rejected', null === NumberParser::to_positive_float( '0' ) );
Assert::check( 'negative rejected', null === NumberParser::to_positive_float( '-5' ) );
Assert::check( 'garbage rejected', null === NumberParser::to_positive_float( 'n/a' ) );
Assert::check( 'bool rejected', null === NumberParser::to_positive_float( true ) );

/* ------------------------------------------------------------------------- */
echo "\n[2] NavasanProvider (navasan-latest.json)\n";
/* ------------------------------------------------------------------------- */
$navasan_body = fixture( 'navasan-latest.json' );

if ( '' !== $navasan_body ) {
	$map = NavasanParser::parse_to_toman( $navasan_body );

	Assert::check(
		'parsed a substantial map',
		count( $map ) > 250,
		'count=' . count( $map )
	);

	// Values verified directly against the fixture.
	Assert::rate( 'usd', $map['usd'] ?? null, 270000.0 );
	Assert::rate( 'eur', $map['eur'] ?? null, 302460.0 );
	Assert::rate( 'aed', $map['aed'] ?? null, 74220.0 );
	Assert::rate( '18ayar', $map['18ayar'] ?? null, 26051530.0 );
	// Coins and melted gold are quoted in THOUSAND Toman and must be x1000.
	Assert::rate( 'sekkeh (x1000)', $map['sekkeh'] ?? null, 267000000.0 );
	Assert::rate( 'bahar (x1000)', $map['bahar'] ?? null, 255000000.0 );
	Assert::rate( 'nim (x1000)', $map['nim'] ?? null, 141000000.0 );
	Assert::rate( 'rob (x1000)', $map['rob'] ?? null, 76000000.0 );
	Assert::rate( 'gerami (x1000)', $map['gerami'] ?? null, 38000000.0 );
	Assert::rate( 'abshodeh (x1000)', $map['abshodeh'] ?? null, 112850000.0 );

	// Unit multipliers must also apply to buy/sell variants.
	Assert::check( 'unit_multiplier(sekkeh)=1000', 1000.0 === NavasanParser::unit_multiplier( 'sekkeh' ) );
	Assert::check( 'unit_multiplier(sekkeh_sell)=1000', 1000.0 === NavasanParser::unit_multiplier( 'sekkeh_sell' ) );
	Assert::check( 'unit_multiplier(sekkeh_buy)=1000', 1000.0 === NavasanParser::unit_multiplier( 'sekkeh_buy' ) );
	Assert::check( 'unit_multiplier(usd)=1', 1.0 === NavasanParser::unit_multiplier( 'usd' ) );
	Assert::check( 'unit_multiplier(18ayar)=1', 1.0 === NavasanParser::unit_multiplier( '18ayar' ) );
	Assert::rate( 'xau (Toman, not USD)', $map['xau'] ?? null, 1078851150.0 );
	Assert::rate( 'xag (Toman)', $map['xag'] ?? null, 14554470.0 );

	// USD-denominated ounce prices must be excluded.
	Assert::check( 'usd_xau excluded (USD per ounce)', ! isset( $map['usd_xau'] ) );
	Assert::check( 'bub_abshodeh excluded (no value key)', ! isset( $map['bub_abshodeh'] ) );
	Assert::check( 'mob_usd excluded (stale prefix)', ! isset( $map['mob_usd'] ) );

	// No zero/negative/non-finite rates may survive.
	$bad = array_filter(
		$map,
		static fn ( $v ) => ! is_float( $v ) || ! is_finite( $v ) || $v <= 0
	);
	Assert::check( 'no zero/negative/non-finite values', empty( $bad ), implode( ',', array_keys( $bad ) ) );

	// Variants.
	$buy = NavasanParser::parse_to_toman( $navasan_body, NavasanParser::VARIANT_BUY );
	Assert::rate( 'variant buy: usd', $buy['usd'] ?? null, 269600.0 );

	$sell = NavasanParser::parse_to_toman( $navasan_body, NavasanParser::VARIANT_SELL );
	Assert::rate( 'variant sell: usd', $sell['usd'] ?? null, 270000.0 );

	$avg = NavasanParser::parse_to_toman( $navasan_body, NavasanParser::VARIANT_AVERAGE );
	Assert::rate( 'variant average: usd', $avg['usd'] ?? null, ( 269600.0 + 270000.0 ) / 2 );

	// Broken payloads must not throw.
	Assert::check( 'garbage body returns empty map', array() === NavasanParser::parse_to_toman( '<html>nope</html>' ) );
	Assert::check( 'empty json object returns empty map', array() === NavasanParser::parse_to_toman( '{}' ) );
	Assert::check( 'json list returns empty map', array() === NavasanParser::parse_to_toman( '[1,2,3]' ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[3] AlanChandProvider — currency (alanchand-currency.json)\n";
/* ------------------------------------------------------------------------- */
$alan_currency = fixture( 'alanchand-currency.json' );

if ( '' !== $alan_currency ) {
	$map = AlanChandParser::parse_to_toman( $alan_currency, AlanChandParser::TYPE_CURRENCY );

	Assert::check( 'parsed all 44 currencies', 44 === count( $map ), 'count=' . count( $map ) );
	Assert::rate( 'usd (sell)', $map['usd'] ?? null, 270900.0 );
	Assert::rate( 'eur (sell)', $map['eur'] ?? null, 303500.0 );
	Assert::rate( 'aed (sell)', $map['aed'] ?? null, 73760.0 );
	Assert::rate( 'gbp (sell)', $map['gbp'] ?? null, 358700.0 );
	Assert::rate( 'kwd (sell)', $map['kwd'] ?? null, 871600.0 );

	$bad = array_filter( $map, static fn ( $v ) => ! is_float( $v ) || $v <= 0 );
	Assert::check( 'no zero/negative values', empty( $bad ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[4] AlanChandProvider — gold (alanchand-gold.json)\n";
/* ------------------------------------------------------------------------- */
$alan_gold = fixture( 'alanchand-gold.json' );

if ( '' !== $alan_gold ) {
	$map = AlanChandParser::parse_to_toman( $alan_gold, AlanChandParser::TYPE_GOLD );

	Assert::rate( '18ayar (price)', $map['18ayar'] ?? null, 26051530.0 );
	Assert::rate( 'sekkeh (price)', $map['sekkeh'] ?? null, 267000000.0 );
	Assert::rate( 'bahar (price)', $map['bahar'] ?? null, 255000000.0 );
	Assert::rate( 'abshodeh (price)', $map['abshodeh'] ?? null, 112850000.0 );
	Assert::rate( 'sek (price)', $map['sek'] ?? null, 38000000.0 );

	// 7 Toman-priced parts out of 12 items; the 5 ounce items are USD.
	Assert::check( '7 Toman-priced gold parts kept', 7 === count( $map ), 'count=' . count( $map ) );
	Assert::check( 'usd_xau excluded (USD per ounce)', ! isset( $map['usd_xau'] ) );
	Assert::check( 'xag excluded (USD per ounce)', ! isset( $map['xag'] ) );
	Assert::check( 'xpt excluded (USD per ounce)', ! isset( $map['xpt'] ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[5] Type overrides and defensive parsing\n";
/* ------------------------------------------------------------------------- */
Assert::check(
	'bare slug uses built-in map',
	array( 'gold', 'sekkeh' ) === AlanChandParser::split_symbol( 'sekkeh', array( 'sekkeh' => 'gold' ) )
);
Assert::check(
	'explicit override gold:sekkeh',
	array( 'gold', 'sekkeh' ) === AlanChandParser::split_symbol( 'gold:sekkeh', array() )
);
Assert::check(
	'explicit override currency:usd',
	array( 'currency', 'usd' ) === AlanChandParser::split_symbol( 'currency:usd', array() )
);
Assert::check(
	'unknown slug falls back',
	array( 'currency', 'xyz' ) === AlanChandParser::split_symbol( 'xyz', array() )
);
Assert::check(
	'bad type prefix degrades to plain slug',
	array( 'currency', 'bogus:gold:sekkeh' ) === AlanChandParser::split_symbol( 'bogus:gold:sekkeh', array() )
);

// Defensive: mixed-good payloads skip invalid items individually.
$mixed = json_encode(
	array(
		'good'   => array( 'slug' => 'good', 'sell' => 1000, 'buy' => 900 ),
		'null'   => array( 'slug' => 'null', 'sell' => null, 'buy' => null ),
		'zero'   => array( 'slug' => 'zero', 'sell' => 0, 'buy' => 0 ),
		'neg'    => array( 'slug' => 'neg', 'sell' => -10 ),
		'text'   => array( 'slug' => 'text', 'sell' => 'n/a' ),
		'str'    => array( 'slug' => 'str', 'sell' => '1,250,000' ),
		// Keyed as usd_xau so the USD-denominated guard must drop it.
		'usd_xau' => array( 'slug' => 'usd_xau', 'price' => 3995.85 ),
		'scalar' => 'not-an-array',
	)
);

$parsed = AlanChandParser::parse_to_toman( (string) $mixed, AlanChandParser::TYPE_CURRENCY );
Assert::check( 'mixed payload keeps only 2 valid items', 2 === count( $parsed ), 'count=' . count( $parsed ) . ' keys=' . implode( ',', array_keys( $parsed ) ) );
Assert::rate( 'mixed: good', $parsed['good'] ?? null, 1000.0 );
Assert::rate( 'mixed: comma string', $parsed['str'] ?? null, 1250000.0 );
Assert::check( 'mixed: usd_xau dropped', ! isset( $parsed['usd_xau'] ) );
Assert::check( 'mixed: scalar entry skipped', ! isset( $parsed['scalar'] ) );

// Envelope unwrap.
$enveloped = json_encode( array( 'data' => array( 'usd' => array( 'sell' => 500 ) ) ) );
Assert::rate( 'envelope {data:{...}} unwrapped', AlanChandParser::parse_to_toman( (string) $enveloped )['usd'] ?? null, 500.0 );

// Error payload parses to nothing.
Assert::check( 'error payload yields no rates', array() === AlanChandParser::parse_to_toman( '{"error":"توکن یافت نشد."}' ) );

/* ------------------------------------------------------------------------- */
echo "\n[6] Cross-source consistency (Navasan vs AlanChand, within 3%)\n";
/* ------------------------------------------------------------------------- */
if ( '' !== $navasan_body && '' !== $alan_currency && '' !== $alan_gold ) {
	$nav = NavasanParser::parse_to_toman( $navasan_body );
	$cur = AlanChandParser::parse_to_toman( $alan_currency, AlanChandParser::TYPE_CURRENCY );
	$gld = AlanChandParser::parse_to_toman( $alan_gold, AlanChandParser::TYPE_GOLD );

	// array( navasan key, alanchand payload, alanchand slug, label )
	$cross = array(
		array( 'usd', $cur, 'usd', 'USD' ),
		array( 'eur', $cur, 'eur', 'EUR' ),
		array( 'aed', $cur, 'aed', 'AED' ),
		array( '18ayar', $gld, '18ayar', '18K gold' ),
		array( 'sekkeh', $gld, 'sekkeh', 'Full coin' ),
		array( 'bahar', $gld, 'bahar', 'Bahar coin' ),
		array( 'nim', $gld, 'nim', 'Half coin' ),
		array( 'rob', $gld, 'rob', 'Quarter coin' ),
		array( 'gerami', $gld, 'sek', 'Gram coin' ),
		array( 'abshodeh', $gld, 'abshodeh', 'Melted gold' ),
	);

	foreach ( $cross as $pair ) {
		list( $nav_key, $source, $ac_slug, $label ) = $pair;

		$nav_value = $nav[ $nav_key ] ?? null;
		$ac_value  = $source[ $ac_slug ] ?? null;

		if ( null === $nav_value || null === $ac_value ) {
			Assert::check( $label . ' present in both sources', false, 'navasan=' . var_export( $nav_value, true ) . ' alanchand=' . var_export( $ac_value, true ) );
			continue;
		}

		$diff_pct = abs( $nav_value - $ac_value ) / max( $nav_value, 0.0001 ) * 100;

		Assert::check(
			sprintf( '%s within 3%% (%s vs %s, %.2f%%)', $label, number_format( $nav_value ), number_format( $ac_value ), $diff_pct ),
			$diff_pct <= 3.0
		);
	}
}


/* ------------------------------------------------------------------------- */
echo "\n[7] Provider symbol mapping (Phase 5 regression guard)\n";
/* ------------------------------------------------------------------------- */
/*
 * The seeded GOLD18 row once kept a Mock rate (4,526,811) after its provider was
 * switched to AlanChand, so the site showed a mock value under a real source's
 * label. The mapping itself is correct: `gold:18ayar` must resolve to the gold
 * catalogue and to the real ~26M Toman price, never to the small mock number.
 */
if ( '' !== $alan_gold ) {
	$gold_map = AlanChandParser::parse_to_toman( $alan_gold, AlanChandParser::TYPE_GOLD );

	$gold = $gold_map['18ayar'] ?? null;

	Assert::rate( '18ayar resolves through the gold catalogue', $gold, 26051530.0 );

	Assert::check(
		'18K gold is above 20M Toman (not a mock-scale number)',
		is_float( $gold ) && $gold > 20000000.0,
		'got ' . var_export( $gold, true )
	);

	$mock_scale = 4526811.0;

	Assert::check(
		'18K gold differs from the mock-scale value',
		is_float( $gold ) && abs( $gold - $mock_scale ) > 1000000.0,
		'gold=' . var_export( $gold, true ) . ' mock=' . number_format( $mock_scale )
	);
}

/* ------------------------------------------------------------------------- */
/* ------------------------------------------------------------------------- */
echo "\n[8] NetArz parser (netarz-rates.json, netarz-rate-usd.json)\n";
/* ------------------------------------------------------------------------- */

$netarz_list   = fixture( 'netarz-rates.json' );
$netarz_single = fixture( 'netarz-rate-usd.json' );

if ( '' !== $netarz_list ) {
	$map = NetarzParser::parse( $netarz_list );

	Assert::check( 'parsed all 32 codes', 32 === count( $map ), 'count=' . count( $map ) );

	// Values verified directly against the fixture.
	Assert::rate( 'USD mid', $map['USD'] ?? null, 271500.0 );
	Assert::rate( 'EUR mid', $map['EUR'] ?? null, 302600.0 );
	Assert::rate( 'AED mid', $map['AED'] ?? null, 73900.0 );
	Assert::rate( 'GBP mid', $map['GBP'] ?? null, 361300.0 );
	Assert::rate( 'JPY mid', $map['JPY'] ?? null, 1719.0 );

	// unit = 100 rows MUST be divided by 100 (IQD/AMD/PKR).
	Assert::rate( 'IQD per 1 unit (unit=100)', $map['IQD'] ?? null, 179.1 );
	Assert::rate( 'AMD per 1 unit (unit=100)', $map['AMD'] ?? null, 755.0 );
	Assert::rate( 'PKR per 1 unit (unit=100)', $map['PKR'] ?? null, 980.0 );

	Assert::check(
		'the unit=100 rows are the only scaled ones',
		abs( ( $map['IQD'] ?? 0 ) - 17910 / 100 ) < 0.0001 && abs( ( $map['USD'] ?? 0 ) - 271500 ) < 0.0001
	);

	$bad = array_filter( $map, static fn ( $v ) => ! is_float( $v ) || ! is_finite( $v ) || $v <= 0 );
	Assert::check( 'no zero/negative/non-finite values', empty( $bad ), implode( ',', array_keys( $bad ) ) );

	// quote side selection
	$buy = NetarzParser::parse( $netarz_list, NetarzParser::QUOTE_BUY );
	Assert::rate( 'USD buy', $buy['USD'] ?? null, 270100.0 );
	$sell = NetarzParser::parse( $netarz_list, NetarzParser::QUOTE_SELL );
	Assert::rate( 'USD sell', $sell['USD'] ?? null, 272900.0 );

	// meta / freshness / source timestamp
	$fresh = NetarzParser::freshness( $netarz_list );
	Assert::check( 'meta.is_delayed = true (free plan)', true === $fresh['delayed'] );
	Assert::check( 'meta.delayed_minutes = 15', 15 === $fresh['minutes'] );
	Assert::check( 'meta.plan = free', 'free' === $fresh['plan'] );
	Assert::check(
		'meta.as_of parses to 2026-10-29 18:57 UTC',
		'2026-10-29 18:57' === gmdate( 'Y-m-d H:i', NetarzParser::source_timestamp( $netarz_list ) ),
		gmdate( 'Y-m-d H:i', NetarzParser::source_timestamp( $netarz_list ) )
	);

	// broken payloads
	Assert::check( 'garbage body returns empty', array() === NetarzParser::parse( '<html>nope</html>' ) );
	Assert::check( 'empty object returns empty', array() === NetarzParser::parse( '{}' ) );
}

if ( '' !== $netarz_single ) {
	// The single-currency shape wraps ONE object in `data`, not a list.
	$single = NetarzParser::parse( $netarz_single );
	Assert::check( 'single object shape parsed', 1 === count( $single ) && isset( $single['USD'] ) );
	Assert::rate( 'single USD mid', $single['USD'] ?? null, 271500.0 );
}

// Error envelope (documented: errors always live in `error` with a code).
$error_body = '{"error":{"code":"forbidden_domain","message":"domain not allowed"}}';
$err        = NetarzParser::error( $error_body );
Assert::check( 'error envelope parsed', is_array( $err ) && 'forbidden_domain' === $err['code'] );
Assert::check( 'no error on a good payload', null === NetarzParser::error( $netarz_list ) );

/* ------------------------------------------------------------------------- */
echo "\n[9] Nerkhsanj parser (nerkhsanj-live.json)\n";
/* ------------------------------------------------------------------------- */

$nerkh_body = fixture( 'nerkhsanj-live.json' );

if ( '' !== $nerkh_body ) {
	$map = NerkhsanjParser::parse( $nerkh_body );

	Assert::check( 'parsed all 28 rates', 28 === count( $map ), 'count=' . count( $map ) );

	Assert::rate( 'dollar', $map['USD'] ?? null, 271060.0 );
	Assert::rate( 'euro', $map['EUR'] ?? null, 301900.0 );
	Assert::rate( 'yuan', $map['CNY'] ?? null, 40770.0 );
	Assert::rate( 'pound', $map['GBP'] ?? null, 361690.0 );
	Assert::rate( 'aed', $map['AED'] ?? null, 74289.0 );
	Assert::rate( 'lira', $map['TRY'] ?? null, 5460.0 );
	Assert::rate( 'tether', $map['USDT'] ?? null, 271556.0 );
	Assert::rate( 'bitcoin', $map['BTC'] ?? null, 21949402800.0 );

	// Gold and coin: must NOT be off by 1000x.
	Assert::rate( 'gold (18K gram)', $map['GOLD18'] ?? null, 26223500.0 );
	Assert::rate( 'coin (Emami)', $map['COIN'] ?? null, 266785000.0 );
	Assert::rate( 'gold24', $map['GOLD24'] ?? null, 34964300.0 );
	Assert::rate( 'mesghal', $map['MESGHAL'] ?? null, 113604000.0 );
	Assert::rate( 'silver', $map['SILVER'] ?? null, 489280.0 );

	Assert::check( 'gold is in the tens of millions (not 26k)', ( $map['GOLD18'] ?? 0 ) > 20000000 );
	Assert::check( 'coin is in the hundreds of millions', ( $map['COIN'] ?? 0 ) > 200000000 );

	$bad = array_filter( $map, static fn ( $v ) => ! is_float( $v ) || ! is_finite( $v ) || $v <= 0 );
	Assert::check( 'no zero/negative/non-finite values', empty( $bad ), implode( ',', array_keys( $bad ) ) );

	// Meta + freshness.
	$meta = NerkhsanjParser::meta( $nerkh_body );
	Assert::check( 'meta date is Jalali 1405/08/07', '1405/08/07' === $meta['date'] );
	Assert::check( 'meta time is 16:59', '16:59' === $meta['time'] );
	Assert::check( 'meta counts 28 rates', 28 === $meta['count'] );

	// Jalali -> Gregorian conversion.
	Assert::check(
		'jalali 1405/08/07 = 2026-10-28',
		'2026-10-28' === gmdate( 'Y-m-d', NerkhsanjParser::jalali_to_timestamp( 1405, 8, 7 ) ),
		gmdate( 'Y-m-d', NerkhsanjParser::jalali_to_timestamp( 1405, 8, 7 ) )
	);
	Assert::check( 'impossible Jalali date -> 0', 0 === NerkhsanjParser::jalali_to_timestamp( 1405, 13, 1 ) );

	$src = NerkhsanjParser::source_timestamp( $nerkh_body );
	Assert::check( 'source timestamp is a positive unix time', $src > 1700000000, (string) $src );

	// Empty `time` (outside market hours) still yields a usable date.
	$no_time = '{"date":"1405/08/07","time":"","rates":{"dollar":271060}}';
	Assert::check( 'empty time still gives a timestamp', NerkhsanjParser::source_timestamp( $no_time ) > 0 );

	// Defensive parsing: strings, commas, Persian digits, nulls.
	$messy = '{"date":"1405/08/07","time":"10:00","rates":{"dollar":"۲۷۱٬۰۶۰","euro":"301,900","gold":null,"coin":"","aed":-5}}';
	$messy_map = NerkhsanjParser::parse( $messy );
	Assert::rate( 'messy dollar (Persian digits + separators)', $messy_map['USD'] ?? null, 271060.0 );
	Assert::rate( 'messy euro (comma)', $messy_map['EUR'] ?? null, 301900.0 );
	Assert::check( 'null gold skipped', ! isset( $messy_map['GOLD18'] ) );
	Assert::check( 'empty coin skipped', ! isset( $messy_map['COIN'] ) );
	Assert::check( 'negative aed skipped', ! isset( $messy_map['AED'] ) );

	Assert::check( 'garbage body returns empty', array() === NerkhsanjParser::parse( 'nope' ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[10] Cross-source consistency (Navasan / AlanChand / NetArz / Nerkhsanj)\n";
/* ------------------------------------------------------------------------- */
/*
 * The fixtures were captured on different days (Navasan/AlanChand 2026-10-25,
 * NetArz/Nerkhsanj 2026-10-29), so a small drift is expected. Tolerances:
 *   currencies 4%  (day-to-day FX move)
 *   gold/coin  6%  (higher volatility + different quote conventions)
 * Anything beyond that is a unit bug (x10/x1000), not drift.
 */
if ( '' !== $navasan_body && '' !== $alan_currency && '' !== $nerkh_body && '' !== $netarz_list ) {
	$nav = NavasanParser::parse_to_toman( $navasan_body );
	$ac  = AlanChandParser::parse_to_toman( $alan_currency, AlanChandParser::TYPE_CURRENCY );
	$acg = AlanChandParser::parse_to_toman( $alan_gold, AlanChandParser::TYPE_GOLD );
	$nz  = NetarzParser::parse( $netarz_list );
	$nk  = NerkhsanjParser::parse( $nerkh_body );

	$groups = array(
		array( 'USD', 4.0, array( $nav['usd'] ?? null, $ac['usd'] ?? null, $nz['USD'] ?? null, $nk['USD'] ?? null ) ),
		array( 'EUR', 4.0, array( $nav['eur'] ?? null, $ac['eur'] ?? null, $nz['EUR'] ?? null, $nk['EUR'] ?? null ) ),
		array( 'AED', 4.0, array( $nav['aed'] ?? null, $ac['aed'] ?? null, $nz['AED'] ?? null, $nk['AED'] ?? null ) ),
		array( 'GBP', 4.0, array( $nav['gbp'] ?? null, $ac['gbp'] ?? null, $nz['GBP'] ?? null, $nk['GBP'] ?? null ) ),
		array( '18K gold', 6.0, array( $nav['18ayar'] ?? null, $acg['18ayar'] ?? null, $nk['GOLD18'] ?? null ) ),
		array( 'full coin', 6.0, array( $nav['sekkeh'] ?? null, $acg['sekkeh'] ?? null, $nk['COIN'] ?? null ) ),
	);

	foreach ( $groups as [ $label, $tolerance, $values ] ) {
		$values = array_values( array_filter( $values, static fn ( $v ) => is_float( $v ) && $v > 0 ) );

		if ( count( $values ) < 2 ) {
			Assert::check( $label . ' present in at least two sources', false, 'count=' . count( $values ) );
			continue;
		}

		$min = min( $values );
		$max = max( $values );
		$pct = ( $max - $min ) / $min * 100;

		Assert::check(
			sprintf( '%s within %s%% across sources (min %s, max %s, %.2f%%)', $label, $tolerance, number_format( $min ), number_format( $max ), $pct ),
			$pct <= $tolerance
		);

		// Fail loudly on a x10 / x1000 unit mismatch.
		Assert::check(
			sprintf( '%s has no x10/x1000 mismatch (ratio %.2f)', $label, $max / $min ),
			( $max / $min ) < 3.0
		);
	}
}

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", Assert::$passed, Assert::$failed );

exit( Assert::$failed > 0 ? 1 : 0 );
