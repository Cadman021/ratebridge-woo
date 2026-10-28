<?php
/**
 * Dependency-free test suite for the price calculation engine.
 *
 * Run from the plugin root:
 *     php tests/price-calculator.php
 *
 * No WordPress and no WooCommerce are loaded: PriceCalculator is pure PHP, so
 * every case below runs anywhere PHP 8.1+ is available.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

// PriceCalculator guards on ABSPATH; define it so it can be included outside WP.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/Pricing/PriceCalculator.php';

use RateBridge\Pricing\PriceCalculator;

/**
 * Tiny PASS/FAIL collector.
 */
final class CalcAssert {

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
	 * Assert a calculated price equals the expected value.
	 *
	 * @param string           $label    Description.
	 * @param array<string,mixed> $result Result from PriceCalculator::calculate().
	 * @param float            $expected Expected final price.
	 * @return bool
	 */
	public static function price( string $label, array $result, float $expected ): bool {
		$actual = $result['price'] ?? null;

		$ok = ( $result['ok'] ?? false ) === true
			&& is_float( $actual )
			&& abs( $actual - $expected ) < 0.005;

		return self::check(
			sprintf( '%s => %s', $label, self::fmt( $expected ) ),
			$ok,
			sprintf(
				'got %s%s',
				self::fmt( $actual ),
				( $result['ok'] ?? false ) ? '' : ' error=' . ( $result['error'] ?? '?' )
			)
		);
	}

	/**
	 * Assert a result is an error with a given reason code.
	 *
	 * @param string              $label    Description.
	 * @param array<string,mixed> $result   Result from PriceCalculator::calculate().
	 * @param string              $code     Expected reason code.
	 * @return bool
	 */
	public static function error( string $label, array $result, string $code ): bool {
		return self::check(
			sprintf( '%s => error %s', $label, $code ),
			false === ( $result['ok'] ?? null ) && null === ( $result['price'] ?? null ) && ( $result['error'] ?? '' ) === $code,
			'got ok=' . wp_json_encode_min( $result['ok'] ?? null ) . ' error=' . (string) ( $result['error'] ?? '?' )
		);
	}

	/**
	 * Format a number for display.
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
 * Minimal JSON encoder (kept local so this file never needs WordPress).
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_min( mixed $value ): string {
	return (string) json_encode( $value );
}

/**
 * Default settings for a Toman store with "nearest 1000" rounding disabled.
 *
 * @param array<string, mixed> $overrides Overrides.
 * @return array<string, mixed>
 */
function base_settings( array $overrides = array() ): array {
	return array_merge(
		array(
			'profit'         => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 0.0,
			),
			'commission'     => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 0.0,
			),
			'extra'          => array(
				'type'  => PriceCalculator::TYPE_FIXED,
				'value' => 0.0,
			),
			'min_price'      => 0.0,
			'rounding_mode'  => PriceCalculator::ROUND_NONE,
			'rounding_step'  => 1000.0,
			'decimals'       => 0,
			'fluctuation'    => 0.0,
			'previous_price' => 0.0,
		),
		$overrides
	);
}

echo "RateBridge Woo — price calculator\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Plain conversion\n";
/* ------------------------------------------------------------------------- */
CalcAssert::price( 'USD 100 x 600,000', PriceCalculator::calculate( 100.0, 600000.0, base_settings() ), 60000000.0 );
CalcAssert::price( 'USD 12.5 x 600,000', PriceCalculator::calculate( 12.5, 600000.0, base_settings() ), 7500000.0 );
CalcAssert::price( 'GOLD18 4.75 g x 26,051,530', PriceCalculator::calculate( 4.75, 26051530.0, base_settings() ), 123744767.5 );

/* ------------------------------------------------------------------------- */
echo "\n[2] Percent profit\n";
/* ------------------------------------------------------------------------- */
$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings(
		array(
			'profit' => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 10.0,
			),
		)
	)
);
CalcAssert::price( 'USD 100 + 10% profit', $r, 66000000.0 );
CalcAssert::check( '  breakdown.profit = 6,000,000', abs( ( $r['breakdown']['profit'] ?? 0 ) - 6000000.0 ) < 0.005, (string) ( $r['breakdown']['profit'] ?? 'x' ) );

$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings(
		array(
			'profit' => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 15.5,
			),
		)
	)
);
CalcAssert::price( 'USD 100 + 15.5% profit', $r, 69300000.0 );

/* ------------------------------------------------------------------------- */
echo "\n[3] Fixed profit\n";
/* ------------------------------------------------------------------------- */
$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings(
		array(
			'profit' => array(
				'type'  => PriceCalculator::TYPE_FIXED,
				'value' => 500000.0,
			),
		)
	)
);
CalcAssert::price( 'USD 100 + 500,000 fixed profit', $r, 60500000.0 );

/* ------------------------------------------------------------------------- */
echo "\n[4] Percent commission on (value + profit)\n";
/* ------------------------------------------------------------------------- */
$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings(
		array(
			'profit'     => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 10.0,
			),
			'commission' => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 5.0,
			),
		)
	)
);
// value 60,000,000; profit 6,000,000 -> 66,000,000; commission 3,300,000 -> 69,300,000.
CalcAssert::price( 'USD 100 + 10% + 5% of (value+profit)', $r, 69300000.0 );
CalcAssert::check( '  breakdown.commission = 3,300,000', abs( ( $r['breakdown']['commission'] ?? 0 ) - 3300000.0 ) < 0.005, (string) ( $r['breakdown']['commission'] ?? 'x' ) );

/* ------------------------------------------------------------------------- */
echo "\n[5] Fixed commission\n";
/* ------------------------------------------------------------------------- */
CalcAssert::price(
	'USD 100 + 250,000 fixed commission',
	PriceCalculator::calculate(
		100.0,
		600000.0,
		base_settings(
			array(
				'commission' => array(
					'type'  => PriceCalculator::TYPE_FIXED,
					'value' => 250000.0,
				),
			)
		)
	),
	60250000.0
);

/* ------------------------------------------------------------------------- */
echo "\n[6] Extra amount\n";
/* ------------------------------------------------------------------------- */
CalcAssert::price(
	'USD 100 + 1,000,000 extra',
	PriceCalculator::calculate(
		100.0,
		600000.0,
		base_settings(
			array(
				'extra' => array(
					'type'  => PriceCalculator::TYPE_FIXED,
					'value' => 1000000.0,
				),
			)
		)
	),
	61000000.0
);

/* ------------------------------------------------------------------------- */
echo "\n[7] Combined profit + commission + extra\n";
/* ------------------------------------------------------------------------- */
$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings(
		array(
			'profit'     => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 10.0,
			),
			'commission' => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 5.0,
			),
			'extra'      => array(
				'type'  => PriceCalculator::TYPE_FIXED,
				'value' => 1000000.0,
			),
		)
	)
);
// 60,000,000 + 6,000,000 = 66,000,000; +5% = 3,300,000 -> 69,300,000; +1,000,000 = 70,300,000.
CalcAssert::price( 'USD 100 + 10% + 5% + 1,000,000', $r, 70300000.0 );
CalcAssert::check( '  breakdown chain is complete', isset( $r['breakdown']['base_value'], $r['breakdown']['profit'], $r['breakdown']['commission'], $r['breakdown']['extra'], $r['breakdown']['before_min'], $r['breakdown']['after_min'], $r['breakdown']['rounded'], $r['breakdown']['final'] ) );

/* ------------------------------------------------------------------------- */
echo "\n[8] Minimum price kicks in\n";
/* ------------------------------------------------------------------------- */
$r = PriceCalculator::calculate(
	1.0,
	600000.0,
	base_settings( array( 'min_price' => 5000000.0 ) )
);
CalcAssert::price( 'USD 1 with min price 5,000,000', $r, 5000000.0 );
CalcAssert::check( '  rules.min_price_applied', true === ( $r['rules']['min_price_applied'] ?? null ) );
CalcAssert::check( '  breakdown.before_min = 600,000', abs( ( $r['breakdown']['before_min'] ?? 0 ) - 600000.0 ) < 0.005 );

$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings( array( 'min_price' => 5000000.0 ) )
);
CalcAssert::price( 'min price below the total leaves it alone', $r, 60000000.0 );
CalcAssert::check( '  rules.min_price_applied false', false === ( $r['rules']['min_price_applied'] ?? null ) );

/* ------------------------------------------------------------------------- */
echo "\n[9] Rounding modes with step 1000\n";
/* ------------------------------------------------------------------------- */
// 12.3456 USD * 600,000 = 7,407,360.
$amount = array(
	'base' => 12.3456,
	'rate' => 600000.0,
);

CalcAssert::price(
	'none   7,407,360',
	PriceCalculator::calculate( $amount['base'], $amount['rate'], base_settings( array( 'rounding_mode' => 'none' ) ) ),
	7407360.0
);
CalcAssert::price(
	'nearest 7,407,000',
	PriceCalculator::calculate( $amount['base'], $amount['rate'], base_settings( array( 'rounding_mode' => 'nearest' ) ) ),
	7407000.0
);
CalcAssert::price(
	'ceil    7,408,000',
	PriceCalculator::calculate( $amount['base'], $amount['rate'], base_settings( array( 'rounding_mode' => 'ceil' ) ) ),
	7408000.0
);
CalcAssert::price(
	'floor   7,407,000',
	PriceCalculator::calculate( $amount['base'], $amount['rate'], base_settings( array( 'rounding_mode' => 'floor' ) ) ),
	7407000.0
);

// An exact multiple must stay put in every mode (float noise must not bump it).
foreach ( array( 'nearest', 'ceil', 'floor' ) as $mode ) {
	CalcAssert::price(
		"exact multiple stays 7,407,000 ({$mode})",
		PriceCalculator::calculate( 12.345, 600000.0, base_settings( array( 'rounding_mode' => $mode ) ) ),
		7407000.0
	);
}

// nearest uses half-up semantics: the exact .5 must go up.
// (round_to_step() is asserted directly because a float product rarely lands
// exactly on .5 after the conversion.)
CalcAssert::check(
	'nearest half rounds up: 7,407,500 -> 7,408,000',
	7408000.0 === PriceCalculator::round_to_step( 7407500.0, 'nearest', 1000.0 ),
	(string) PriceCalculator::round_to_step( 7407500.0, 'nearest', 1000.0 )
);
CalcAssert::check(
	'nearest just below half stays down: 7,407,499.9 -> 7,407,000',
	7407000.0 === PriceCalculator::round_to_step( 7407499.9, 'nearest', 1000.0 ),
	(string) PriceCalculator::round_to_step( 7407499.9, 'nearest', 1000.0 )
);
CalcAssert::price(
	'nearest just below half (end to end)',
	PriceCalculator::calculate( 12.345833, 600000.0, base_settings( array( 'rounding_mode' => 'nearest' ) ) ),
	7407000.0
);

// Rounding is applied after the minimum price (documented step order).
CalcAssert::price(
	'min price 5,000,000 then ceil 1000 keeps 5,000,000',
	PriceCalculator::calculate(
		1.0,
		600000.0,
		base_settings(
			array(
				'min_price'     => 5000000.0,
				'rounding_mode' => 'ceil',
			)
		)
	),
	5000000.0
);

/* ------------------------------------------------------------------------- */
echo "\n[10] Fluctuation margin keeps the old price\n";
/* ------------------------------------------------------------------------- */
$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings(
		array(
			'previous_price' => 60000500.0,
			'fluctuation'    => 100000.0,
		)
	)
);
CalcAssert::price( 'move of 50,000 < margin 100,000 keeps 60,000,500', $r, 60000500.0 );
CalcAssert::check( '  rules.fluctuation_kept_previous', true === ( $r['rules']['fluctuation_kept_previous'] ?? null ) );

$r = PriceCalculator::calculate(
	100.0,
	600000.0,
	base_settings(
		array(
			'previous_price' => 61000000.0,
			'fluctuation'    => 100000.0,
		)
	)
);
CalcAssert::price( 'move of 1,000,000 > margin passes through', $r, 60000000.0 );
CalcAssert::check( '  rules.fluctuation_kept_previous false', false === ( $r['rules']['fluctuation_kept_previous'] ?? null ) );

// No previous price / no margin: nothing to compare against.
CalcAssert::price(
	'no previous price ignores the margin',
	PriceCalculator::calculate( 100.0, 600000.0, base_settings( array( 'fluctuation' => 1000000.0 ) ) ),
	60000000.0
);

/* ------------------------------------------------------------------------- */
echo "\n[11] Product modes: none / add / replace\n";
/* ------------------------------------------------------------------------- */
$currency_profit = array(
	'type'  => PriceCalculator::TYPE_PERCENT,
	'value' => 10.0,
);
$product_percent = array(
	'type'  => PriceCalculator::TYPE_PERCENT,
	'value' => 5.0,
);
$product_fixed   = array(
	'type'  => PriceCalculator::TYPE_FIXED,
	'value' => 1000000.0,
);

$none = PriceCalculator::merge_rules( $currency_profit, $product_percent, 'none', 60000000.0 );
CalcAssert::check( 'none: keeps the currency rule (10%)', PriceCalculator::TYPE_PERCENT === $none['type'] && 10.0 === $none['value'], wp_json_encode_min( $none ) );

$replace = PriceCalculator::merge_rules( $currency_profit, $product_percent, 'replace', 60000000.0 );
CalcAssert::check( 'replace: uses the product rule (5%)', PriceCalculator::TYPE_PERCENT === $replace['type'] && 5.0 === $replace['value'], wp_json_encode_min( $replace ) );

$add_same = PriceCalculator::merge_rules( $currency_profit, $product_percent, 'add', 60000000.0 );
CalcAssert::check( 'add, same type: 10% + 5% = 15%', PriceCalculator::TYPE_PERCENT === $add_same['type'] && 15.0 === $add_same['value'] && false === $add_same['converted'], wp_json_encode_min( $add_same ) );

$add_mixed = PriceCalculator::merge_rules( $currency_profit, $product_fixed, 'add', 60000000.0 );
// 10% of 60,000,000 = 6,000,000, plus the fixed 1,000,000 = 7,000,000.
CalcAssert::check(
	'add, mixed types: converted to a fixed 7,000,000',
	PriceCalculator::TYPE_FIXED === $add_mixed['type'] && abs( 7000000.0 - $add_mixed['value'] ) < 0.005 && true === $add_mixed['converted'],
	wp_json_encode_min( $add_mixed )
);

// End to end: 10% + 1,000,000 fixed profit = 7,000,000 on a 60,000,000 base.
CalcAssert::price(
	'add, mixed types end to end',
	PriceCalculator::calculate(
		100.0,
		600000.0,
		base_settings( array( 'profit' => $add_mixed ) )
	),
	67000000.0
);

/* ------------------------------------------------------------------------- */
echo "\n[12] Invalid input is rejected\n";
/* ------------------------------------------------------------------------- */
CalcAssert::error( 'rate 0', PriceCalculator::calculate( 100.0, 0.0, base_settings() ), PriceCalculator::ERROR_INVALID_RATE );
CalcAssert::error( 'rate negative', PriceCalculator::calculate( 100.0, -5.0, base_settings() ), PriceCalculator::ERROR_INVALID_RATE );
CalcAssert::error( 'rate NAN', PriceCalculator::calculate( 100.0, NAN, base_settings() ), PriceCalculator::ERROR_INVALID_RATE );
CalcAssert::error( 'rate INF', PriceCalculator::calculate( 100.0, INF, base_settings() ), PriceCalculator::ERROR_INVALID_RATE );
CalcAssert::error( 'base 0', PriceCalculator::calculate( 0.0, 600000.0, base_settings() ), PriceCalculator::ERROR_INVALID_BASE );
CalcAssert::error( 'base negative', PriceCalculator::calculate( -1.0, 600000.0, base_settings() ), PriceCalculator::ERROR_INVALID_BASE );
CalcAssert::error( 'base NAN', PriceCalculator::calculate( NAN, 600000.0, base_settings() ), PriceCalculator::ERROR_INVALID_BASE );
CalcAssert::error( 'base INF', PriceCalculator::calculate( INF, 600000.0, base_settings() ), PriceCalculator::ERROR_INVALID_BASE );
CalcAssert::error(
	'percent over 1000%',
	PriceCalculator::calculate(
		100.0,
		600000.0,
		base_settings(
			array(
				'profit' => array(
					'type'  => PriceCalculator::TYPE_PERCENT,
					'value' => 1001.0,
				),
			)
		)
	),
	PriceCalculator::ERROR_INVALID_SETTING
);
CalcAssert::error(
	'negative fixed profit',
	PriceCalculator::calculate(
		100.0,
		600000.0,
		base_settings(
			array(
				'profit' => array(
					'type'  => PriceCalculator::TYPE_FIXED,
					'value' => -100.0,
				),
			)
		)
	),
	PriceCalculator::ERROR_INVALID_SETTING
);
CalcAssert::error( 'tiny base rounds down to 0', PriceCalculator::calculate( 1e-9, 1.0, base_settings() ), PriceCalculator::ERROR_NON_POSITIVE_RESULT );

// Sanitisers.
CalcAssert::check( 'sanitize_rule: 1200% rejected', null === PriceCalculator::sanitize_rule( array( 'type' => 'percent', 'value' => 1200 ) ) );
CalcAssert::check( 'sanitize_rule: 1000% accepted', null !== PriceCalculator::sanitize_rule( array( 'type' => 'percent', 'value' => 1000 ) ) );
CalcAssert::check( 'sanitize_rule: garbage rejected', null === PriceCalculator::sanitize_rule( 'abc' ) );
CalcAssert::check( 'sanitize_rule: "1,500" string accepted as 1500 fixed', 1500.0 === ( PriceCalculator::sanitize_rule( '1,500' )['value'] ?? null ) );
CalcAssert::check( 'sanitize_amount: negative -> 0', 0.0 === PriceCalculator::sanitize_amount( -10 ) );
CalcAssert::check( 'sanitize_amount: persian digits', 250000.0 === PriceCalculator::sanitize_amount( '۲۵۰۰۰۰' ) );
CalcAssert::check( 'sanitize_amount: NAN -> 0', 0.0 === PriceCalculator::sanitize_amount( NAN ) );

/* ------------------------------------------------------------------------- */
echo "\n[13] Large values keep precision\n";
/* ------------------------------------------------------------------------- */
// Gold coin (full Emami) ~ 267,000,000 Toman for one coin.
$coin = PriceCalculator::calculate(
	1.0,
	267000000.0,
	base_settings(
		array(
			'profit'     => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 7.0,
			),
			'commission' => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 1.5,
			),
			'extra'      => array(
				'type'  => PriceCalculator::TYPE_FIXED,
				'value' => 350000.0,
			),
		)
	)
);
// 267,000,000 -> +7% = 18,690,000 -> 285,690,000 -> +1.5% = 4,285,350 -> 289,975,350 -> +350,000 = 290,325,350.
CalcAssert::price( 'gold coin 267,000,000 +7% +1.5% +350,000', $coin, 290325350.0 );
CalcAssert::check(
	'  no float drift on the large total',
	abs( ( $coin['breakdown']['final'] ?? 0 ) - 290325350.0 ) < 0.005,
	(string) ( $coin['breakdown']['final'] ?? 'x' )
);

// Bitcoin ~ 2 x 10^10 Toman for 0.5 BTC at 4 x 10^10.
$btc = PriceCalculator::calculate(
	0.5,
	40000000000.0,
	base_settings(
		array(
			'profit'        => array(
				'type'  => PriceCalculator::TYPE_PERCENT,
				'value' => 2.5,
			),
			'rounding_mode' => 'nearest',
			'rounding_step' => 1000.0,
		)
	)
);
// 20,000,000,000 -> +2.5% = 500,000,000 -> 20,500,000,000 (already a multiple of 1000).
CalcAssert::price( 'bitcoin 2e10 +2.5% rounded to 1000', $btc, 20500000000.0 );
CalcAssert::check(
	'  large value reported as float',
	is_float( $btc['price'] ?? null ),
	gettype( $btc['price'] ?? null )
);
CalcAssert::check(
	'  large value stays integral',
	abs( ( $btc['price'] ?? 0 ) - round( (float) ( $btc['price'] ?? 0 ) ) ) < 0.005,
	(string) ( $btc['price'] ?? 'x' )
);

// 6 decimals store (a hypothetical non-Toman store) keeps sub-unit precision.
$decimals = PriceCalculator::calculate(
	3.0,
	2.5,
	base_settings( array( 'decimals' => 2 ) )
);
CalcAssert::price( 'decimals=2: 3 x 2.5 = 7.50', $decimals, 7.5 );

/* ------------------------------------------------------------------------- */
echo "\n[14] Formula mode post-processing (min price / rounding / fluctuation)\n";
/* ------------------------------------------------------------------------- */
/*
 * Formula prices reuse the same three steps as the currency mode, in the same
 * order: minimum price -> rounding -> fluctuation margin. PriceCalculator is
 * the shared implementation (`round_to_step()`), so the steps are asserted
 * here at the primitive level (FormulaPricer calls exactly these).
 */

// Step 6: minimum price raises a small result.
CalcAssert::price(
	'formula result 500,000 raised to min 1,000,000',
	PriceCalculator::calculate(
		1.0,
		500000.0,
		base_settings( array( 'min_price' => 1000000.0 ) )
	),
	1000000.0
);

// Step 7: rounding with step 1000 after the minimum.
CalcAssert::price(
	'formula 7,407,360 rounded nearest 1000',
	PriceCalculator::calculate(
		12.3456,
		600000.0,
		base_settings( array( 'rounding_mode' => 'nearest', 'rounding_step' => 1000.0 ) )
	),
	7407000.0
);
CalcAssert::price(
	'formula 7,407,360 rounded up to 1000',
	PriceCalculator::calculate(
		12.3456,
		600000.0,
		base_settings( array( 'rounding_mode' => 'ceil', 'rounding_step' => 1000.0 ) )
	),
	7408000.0
);

// Step 8: fluctuation keeps the previous price when the move is small.
CalcAssert::price(
	'formula move 50,000 < margin 100,000 keeps previous',
	PriceCalculator::calculate(
		100.0,
		600000.0,
		base_settings(
			array(
				'previous_price' => 60000500.0,
				'fluctuation'    => 100000.0,
			)
		)
	),
	60000500.0
);

// The rounding helper itself (used by FormulaPricer::post_process).
CalcAssert::check(
	'round_to_step(nearest, 1000) on 2,564,512.61 -> 2,565,000',
	2565000.0 === PriceCalculator::round_to_step( 2564512.61, 'nearest', 1000.0 ),
	(string) PriceCalculator::round_to_step( 2564512.61, 'nearest', 1000.0 )
);
CalcAssert::check(
	'round_to_step(floor, 1000) on 2,564,512.61 -> 2,564,000',
	2564000.0 === PriceCalculator::round_to_step( 2564512.61, 'floor', 1000.0 ),
	(string) PriceCalculator::round_to_step( 2564512.61, 'floor', 1000.0 )
);
CalcAssert::check(
	'round_to_step(none) leaves the value alone',
	2564512.61 === PriceCalculator::round_to_step( 2564512.61, 'none', 1000.0 )
);
CalcAssert::check(
	'a formula result <= 0 must never become a price',
	! PriceCalculator::calculate(
		1.0,
		0.0,
		base_settings()
	)['ok']
);

// The gold reference case, end to end with step 1000 (the store default).
CalcAssert::price(
	'gold 256,451,261.32 rounded to the nearest 1000 = 256,451,000',
	PriceCalculator::calculate(
		1.0,
		256451261.32,
		base_settings( array( 'rounding_mode' => 'nearest', 'rounding_step' => 1000.0 ) )
	),
	256451000.0
);

/* ------------------------------------------------------------------------- */
echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", CalcAssert::$passed, CalcAssert::$failed );

exit( CalcAssert::$failed > 0 ? 1 : 0 );
