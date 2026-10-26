<?php
/**
 * Pure price calculation engine.
 *
 * Deliberately free of WordPress and WooCommerce so `tests/price-calculator.php`
 * can run it from the command line. Every user-facing label is therefore
 * provided by the caller; this class returns reason codes, never HTML.
 *
 * Order of operations (documented in README.md):
 *   1. value      = base_price x rate
 *   2. profit     = percent of value                  OR fixed amount
 *   3. commission = percent of (value + profit)       OR fixed amount
 *   4. extra      = percent of (value + profit)       OR fixed amount
 *   5. total      = value + profit + commission + extra
 *   6. min price  = max(total, min_price)
 *   7. rounding   = none | nearest | ceil | floor with a step
 *   8. fluctuation= keep the previous price when the move is smaller than the margin
 *
 * Float precision: `bcmath` is used when the extension is loaded (all money
 * steps then run at the store scale + 4 decimal digits). Without bcmath every
 * step is snapped with `round()`, which keeps the accumulated error far below
 * one unit of the smallest store denomination. Large numbers (gold coin
 * ~2.67e8 Toman, bitcoin ~2e10 Toman) stay exact under bcmath and well within
 * float64 precision (2^53 ~ 9e15) otherwise.
 *
 * @package RateBridge
 */

namespace RateBridge\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless calculator: one call, one result.
 */
final class PriceCalculator {

	/**
	 * Extra decimal digits kept internally (on top of the store precision).
	 */
	public const INTERNAL_SCALE = 4;

	/**
	 * Maximum accepted percentage (1000%).
	 */
	public const MAX_PERCENT = 1000.0;

	/**
	 * Mode: use the currency-level profit/commission/extra values.
	 */
	public const MODE_NONE = 'none';

	/**
	 * Mode: add the product value to the currency value (same type).
	 */
	public const MODE_ADD = 'add';

	/**
	 * Mode: replace the currency value with the product value.
	 */
	public const MODE_REPLACE = 'replace';

	/**
	 * Type: percentage of the running total.
	 */
	public const TYPE_PERCENT = 'percent';

	/**
	 * Type: fixed amount in the store unit.
	 */
	public const TYPE_FIXED = 'fixed';

	/**
	 * Rounding modes.
	 */
	public const ROUND_NONE    = 'none';
	public const ROUND_NEAREST = 'nearest';
	public const ROUND_CEIL    = 'ceil';
	public const ROUND_FLOOR   = 'floor';

	/**
	 * Reason codes returned in an error result.
	 */
	public const ERROR_INVALID_RATE        = 'invalid_rate';
	public const ERROR_INVALID_BASE        = 'invalid_base_price';
	public const ERROR_INVALID_SETTING     = 'invalid_setting';
	public const ERROR_NON_FINITE          = 'non_finite';
	public const ERROR_NON_POSITIVE_RESULT = 'non_positive_result';

	/**
	 * Calculate the final price and the full breakdown.
	 *
	 * @param float                $base_price Base price in the foreign unit.
	 * @param float                $rate       Store-unit amount per 1 foreign unit.
	 * @param array<string, mixed> $settings   {
	 *     @type array  $profit       Rule array( 'type' => percent|fixed, 'value' => float ).
	 *     @type array  $commission   Rule array( 'type' => percent|fixed, 'value' => float ).
	 *     @type array  $extra        Rule array( 'type' => percent|fixed, 'value' => float ).
	 *     @type float  $min_price    Minimum price in the store unit (0 = off).
	 *     @type string $rounding_mode none|nearest|ceil|floor.
	 *     @type float  $rounding_step Step for nearest/ceil/floor.
	 *     @type int    $decimals     Store decimals (0 for Toman/Rial).
	 *     @type float  $fluctuation  Keep the previous price when the move is smaller.
	 *     @type float  $previous_price Last calculated price (0 = none).
	 * }
	 * @return array<string, mixed> {
	 *     @type bool   $ok        Whether a price could be calculated.
	 *     @type string $error     Reason code when !ok.
	 *     @type float  $price     Final store price when ok.
	 *     @type array  $breakdown Step-by-step amounts.
	 *     @type array  $rules     Flags describing which rules fired.
	 * }
	 */
	public static function calculate( float $base_price, float $rate, array $settings = array() ): array {
		$decimals = self::to_decimals( $settings['decimals'] ?? 0 );

		$breakdown = array(
			'base_price'   => $base_price,
			'rate'         => $rate,
			'base_value'   => 0.0,
			'profit'       => 0.0,
			'commission'   => 0.0,
			'extra'        => 0.0,
			'before_min'   => 0.0,
			'min_price'    => 0.0,
			'after_min'    => 0.0,
			'before_round' => 0.0,
			'rounded'      => 0.0,
			'previous'     => 0.0,
			'final'        => 0.0,
		);

		$rules = array(
			'min_price_applied'         => false,
			'rounding_applied'          => false,
			'fluctuation_kept_previous' => false,
			'profit_converted'          => false,
			'commission_converted'      => false,
			'extra_converted'           => false,
		);

		if ( ! is_finite( $rate ) || $rate <= 0 ) {
			return self::error( self::ERROR_INVALID_RATE, $breakdown, $rules );
		}

		if ( ! is_finite( $base_price ) || $base_price <= 0 ) {
			return self::error( self::ERROR_INVALID_BASE, $breakdown, $rules );
		}

		$profit     = self::sanitize_rule( $settings['profit'] ?? array() );
		$commission = self::sanitize_rule( $settings['commission'] ?? array() );
		$extra      = self::sanitize_rule( $settings['extra'] ?? array() );

		if ( null === $profit || null === $commission || null === $extra ) {
			return self::error( self::ERROR_INVALID_SETTING, $breakdown, $rules );
		}

		$min_price     = self::sanitize_amount( $settings['min_price'] ?? 0.0 );
		$fluctuation   = self::sanitize_amount( $settings['fluctuation'] ?? 0.0 );
		$previous      = self::sanitize_previous_price( $settings['previous_price'] ?? 0.0 );
		$rounding_mode = self::to_rounding_mode( $settings['rounding_mode'] ?? self::ROUND_NONE );
		$rounding_step = self::sanitize_amount( $settings['rounding_step'] ?? 0.0 );

		// 1. Base conversion. Snap to the internal scale (see the note above).
		$value = self::snap( self::mul( $base_price, $rate, $decimals ), $decimals );

		// 2. Profit: percent applies to the converted base value.
		$profit_amount = self::rule_amount( $profit, $value );
		$after_profit  = self::snap( self::add( $value, $profit_amount, $decimals ), $decimals );

		// 3. Commission: percent applies to (value + profit).
		$commission_amount = self::rule_amount( $commission, $after_profit );

		// 4. Extra: fixed amount (percent form applies to the same base).
		$extra_amount = self::rule_amount( $extra, $after_profit );

		// 5. Total.
		$total = self::snap(
			self::add(
				self::add( $after_profit, $commission_amount, $decimals ),
				$extra_amount,
				$decimals
			),
			$decimals
		);

		$breakdown['base_value'] = $value;
		$breakdown['profit']     = $profit_amount;
		$breakdown['commission'] = $commission_amount;
		$breakdown['extra']      = $extra_amount;
		$breakdown['before_min'] = $total;
		$breakdown['min_price']  = $min_price;

		if ( ! self::is_finite_number( $total ) ) {
			return self::error( self::ERROR_NON_FINITE, $breakdown, $rules );
		}

		// 6. Minimum price.
		if ( $min_price > 0 && $total < $min_price ) {
			$total                      = $min_price;
			$rules['min_price_applied'] = true;
		}

		$breakdown['after_min'] = $total;

		// 7. Rounding (step based), then snap to the store precision.
		$rounded = self::round_to_step( $total, $rounding_mode, $rounding_step );

		if ( self::ROUND_NONE !== $rounding_mode && $rounding_step > 0 ) {
			$rules['rounding_applied'] = true;
		}

		$final                     = self::snap( $rounded, $decimals );
		$breakdown['before_round'] = $total;
		$breakdown['rounded']      = $final;
		$breakdown['previous']     = $previous;

		// 8. Fluctuation margin.
		if ( $previous > 0 && $fluctuation > 0 && $final > 0 && abs( $final - $previous ) < $fluctuation ) {
			$final                              = $previous;
			$rules['fluctuation_kept_previous'] = true;
		}

		$breakdown['final'] = $final;

		if ( ! self::is_finite_number( $final ) || $final <= 0 ) {
			return self::error( self::ERROR_NON_POSITIVE_RESULT, $breakdown, $rules );
		}

		return array(
			'ok'        => true,
			'error'     => '',
			'price'     => $final,
			'breakdown' => $breakdown,
			'rules'     => $rules,
		);
	}

	/**
	 * Merge a product-level override with the currency-level rule.
	 *
	 * `none`    → use the currency rule untouched.
	 * `replace` → use the product rule only.
	 * `add`     → currency value + product value when the types match. When they
	 *             differ, both sides are first expressed as a fixed store amount
	 *             for `$base_value` and added (`converted` = true).
	 *
	 * Public so the product editor (live preview) and the Repricer share exactly
	 * the same merge logic.
	 *
	 * @param array{type:string,value:float} $currency_rule Currency rule.
	 * @param array{type:string,value:float} $product_rule  Product rule.
	 * @param string                         $mode          none|add|replace.
	 * @param float                          $base_value    Value the percentage applies to.
	 * @return array{type:string,value:float,converted:bool}
	 */
	public static function merge_rules( array $currency_rule, array $product_rule, string $mode, float $base_value ): array {
		$mode = self::to_mode( $mode );

		if ( self::MODE_REPLACE === $mode ) {
			return array(
				'type'      => $product_rule['type'],
				'value'     => $product_rule['value'],
				'converted' => false,
			);
		}

		if ( self::MODE_ADD === $mode ) {
			if ( $currency_rule['type'] === $product_rule['type'] ) {
				return array(
					'type'      => $currency_rule['type'],
					'value'     => self::snap( $currency_rule['value'] + $product_rule['value'], self::INTERNAL_SCALE ),
					'converted' => false,
				);
			}

			// Types differ: convert both to the amount they add to $base_value.
			return array(
				'type'      => self::TYPE_FIXED,
				'value'     => self::snap(
					self::rule_amount( $currency_rule, $base_value ) + self::rule_amount( $product_rule, $base_value ),
					self::INTERNAL_SCALE
				),
				'converted' => true,
			);
		}

		return array(
			'type'      => $currency_rule['type'],
			'value'     => $currency_rule['value'],
			'converted' => false,
		);
	}

	/**
	 * Resolve the amount a rule adds for a given base value.
	 *
	 * @param array{type:string,value:float} $rule       Rule.
	 * @param float                          $base_value Value the percentage applies to.
	 * @return float
	 */
	public static function rule_amount( array $rule, float $base_value ): float {
		if ( self::TYPE_PERCENT === $rule['type'] ) {
			if ( ! is_finite( $base_value ) || $base_value <= 0 ) {
				return 0.0;
			}

			return self::snap( $base_value * $rule['value'] / 100.0, self::INTERNAL_SCALE );
		}

		return self::snap( $rule['value'], self::INTERNAL_SCALE );
	}

	/**
	 * Sanitize a profit/commission/extra rule.
	 *
	 * @param mixed $rule Raw rule (array with type/value) or a bare value.
	 * @return array{type:string,value:float}|null Null when invalid.
	 */
	public static function sanitize_rule( mixed $rule ): ?array {
		if ( is_scalar( $rule ) ) {
			$rule = array(
				'type'  => self::TYPE_FIXED,
				'value' => $rule,
			);
		}

		if ( ! is_array( $rule ) ) {
			return array(
				'type'  => self::TYPE_FIXED,
				'value' => 0.0,
			);
		}

		$type = self::to_type( $rule['type'] ?? self::TYPE_PERCENT );
		$raw  = $rule['value'] ?? 0;

		if ( is_string( $raw ) ) {
			$raw = self::clean_number_string( $raw );
		}

		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		$value = (float) $raw;

		if ( ! is_finite( $value ) || $value < 0 ) {
			return null;
		}

		if ( self::TYPE_PERCENT === $type && $value > self::MAX_PERCENT ) {
			return null;
		}

		return array(
			'type'  => $type,
			'value' => self::snap( $value, self::INTERNAL_SCALE ),
		);
	}

	/**
	 * Sanitize a non-negative money amount (min price, fluctuation, step…).
	 *
	 * Invalid/negative input becomes 0 (treated as "not set").
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_amount( mixed $value ): float {
		if ( is_string( $value ) ) {
			$value = self::clean_number_string( $value );
		}

		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		$value = (float) $value;

		if ( ! is_finite( $value ) || $value <= 0 ) {
			return 0.0;
		}

		return $value;
	}

	/**
	 * Round an amount according to the mode and step.
	 *
	 * @param float  $amount Amount to round.
	 * @param string $mode   none|nearest|ceil|floor.
	 * @param float  $step   Step (must be > 0 to have any effect).
	 * @return float
	 */
	public static function round_to_step( float $amount, string $mode, float $step ): float {
		$mode = self::to_rounding_mode( $mode );

		if ( self::ROUND_NONE === $mode || ! is_finite( $step ) || $step <= 0 || ! is_finite( $amount ) ) {
			return $amount;
		}

		$units = $amount / $step;

		$rounded = match ( $mode ) {
			self::ROUND_CEIL  => ceil( $units - 1e-9 ),
			self::ROUND_FLOOR => floor( $units + 1e-9 ),
			default           => floor( $units + 0.5 + 1e-9 ),
		};

		return self::snap( $rounded * $step, self::INTERNAL_SCALE );
	}

	/**
	 * Normalise a mode string.
	 *
	 * @param mixed $mode Raw mode.
	 * @return string
	 */
	public static function to_mode( mixed $mode ): string {
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : '';

		return in_array( $mode, array( self::MODE_ADD, self::MODE_REPLACE ), true ) ? $mode : self::MODE_NONE;
	}

	/**
	 * Normalise a rule type.
	 *
	 * @param mixed $type Raw type.
	 * @return string
	 */
	public static function to_type( mixed $type ): string {
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';

		return self::TYPE_FIXED === $type ? self::TYPE_FIXED : self::TYPE_PERCENT;
	}

	/**
	 * Normalise a rounding mode.
	 *
	 * @param mixed $mode Raw mode.
	 * @return string
	 */
	public static function to_rounding_mode( mixed $mode ): string {
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : '';

		return in_array( $mode, array( self::ROUND_NEAREST, self::ROUND_CEIL, self::ROUND_FLOOR ), true )
			? $mode
			: self::ROUND_NONE;
	}

	/**
	 * Clamp decimals to a sane range.
	 *
	 * @param mixed $decimals Raw decimals.
	 * @return int
	 */
	public static function to_decimals( mixed $decimals ): int {
		$decimals = is_numeric( $decimals ) ? (int) $decimals : 0;

		return max( 0, min( 6, $decimals ) );
	}

	/**
	 * Clean a decimal string: thousand separators, spaces, Persian digits.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private static function clean_number_string( string $value ): string {
		$value = str_replace( array( ',', ' ', "\xc2\xa0", '٫', '−' ), array( '', '', '', '.', '-' ), trim( $value ) );

		return self::normalize_digits( $value );
	}

	/**
	 * Convert Persian/Arabic-Indic digits to ASCII digits.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private static function normalize_digits( string $value ): string {
		$search  = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$replace = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

		return str_replace( $search, $replace, $value );
	}

	/**
	 * The previously stored price (must be positive to be used).
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function sanitize_previous_price( mixed $value ): float {
		$amount = self::sanitize_amount( $value );

		return $amount > 0 ? $amount : 0.0;
	}

	/**
	 * Build an error result.
	 *
	 * @param string              $code      Reason code.
	 * @param array<string,mixed> $breakdown Breakdown computed so far.
	 * @param array<string,bool>  $rules     Rule flags.
	 * @return array<string, mixed>
	 */
	private static function error( string $code, array $breakdown, array $rules ): array {
		return array(
			'ok'        => false,
			'error'     => $code,
			'price'     => null,
			'breakdown' => $breakdown,
			'rules'     => $rules,
		);
	}

	/**
	 * Multiplication at the internal scale.
	 *
	 * @param float $a        Left term.
	 * @param float $b        Right term.
	 * @param int   $decimals Store decimals.
	 * @return float
	 */
	private static function mul( float $a, float $b, int $decimals ): float {
		if ( self::has_bcmath() ) {
			return (float) bcmul( self::bc( $a ), self::bc( $b ), $decimals + self::INTERNAL_SCALE );
		}

		return $a * $b;
	}

	/**
	 * Addition at the internal scale.
	 *
	 * @param float $a        Left term.
	 * @param float $b        Right term.
	 * @param int   $decimals Store decimals.
	 * @return float
	 */
	private static function add( float $a, float $b, int $decimals ): float {
		if ( self::has_bcmath() ) {
			return (float) bcadd( self::bc( $a ), self::bc( $b ), $decimals + self::INTERNAL_SCALE );
		}

		return $a + $b;
	}

	/**
	 * Whether bcmath is available.
	 *
	 * @return bool
	 */
	private static function has_bcmath(): bool {
		return function_exists( 'bcadd' ) && function_exists( 'bcmul' );
	}

	/**
	 * Render a float for bcmath (never in scientific notation).
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function bc( float $value ): string {
		return sprintf( '%.' . ( self::INTERNAL_SCALE + 6 ) . 'F', $value );
	}

	/**
	 * Snap a value to the store precision + the internal scale.
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Store decimals.
	 * @return float
	 */
	private static function snap( float $value, int $decimals ): float {
		if ( ! is_finite( $value ) ) {
			return $value;
		}

		return round( $value, $decimals + self::INTERNAL_SCALE );
	}

	/**
	 * Finite check that also rejects NaN.
	 *
	 * @param float $value Value.
	 * @return bool
	 */
	private static function is_finite_number( float $value ): bool {
		return is_finite( $value ) && ! is_nan( $value );
	}
}
