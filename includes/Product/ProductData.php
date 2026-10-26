<?php
/**
 * Product meta keys and value normalisation.
 *
 * Everything that reads or writes the `_rbw_*` product/variation meta lives
 * here so the admin fields, the repricer and the AJAX preview agree on the
 * keys and the sanitisation rules.
 *
 * @package RateBridge
 */

namespace RateBridge\Product;

use RateBridge\Core\Settings;
use RateBridge\Pricing\PriceCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys + sanitised settings objects for a managed product.
 */
final class ProductData {

	/**
	 * "Managed by the currency" flag.
	 */
	public const META_ENABLED = '_rbw_enabled';

	/**
	 * Currency CODE (not the numeric id, so the row can be recreated).
	 */
	public const META_CURRENCY = '_rbw_currency';

	/**
	 * Base price expressed in the foreign currency/commodity.
	 */
	public const META_BASE_PRICE = '_rbw_base_price';

	/**
	 * Optional sale base price, in the same foreign unit.
	 */
	public const META_SALE_BASE_PRICE = '_rbw_sale_base_price';

	/**
	 * Optional minimum final price in the store unit.
	 */
	public const META_MIN_PRICE = '_rbw_min_price';

	/**
	 * Optional fluctuation margin in the store unit.
	 */
	public const META_FLUCTUATION = '_rbw_fluctuation';

	/**
	 * Profit override mode: none|add|replace.
	 */
	public const META_PROFIT_MODE = '_rbw_profit_mode';

	/**
	 * Profit override fields (variations use `<slot>_mode` too).
	 */
	public const META_PROFIT_TYPE   = '_rbw_profit_type';
	public const META_PROFIT_VALUE  = '_rbw_profit_value';
	public const META_COMMISSION_TYPE  = '_rbw_commission_type';
	public const META_COMMISSION_VALUE = '_rbw_commission_value';
	public const META_EXTRA_AMOUNT     = '_rbw_extra_amount';

	/**
	 * Cache/audit meta written after every calculation.
	 */
	public const META_LAST_PRICE = '_rbw_last_price';
	public const META_LAST_CALC  = '_rbw_last_calc';
	public const META_LAST_RATE  = '_rbw_last_rate';

	/**
	 * All keys written by the plugin (used by uninstall.php).
	 *
	 * @return string[]
	 */
	public static function all_meta_keys(): array {
		return array(
			self::META_ENABLED,
			self::META_CURRENCY,
			self::META_BASE_PRICE,
			self::META_SALE_BASE_PRICE,
			self::META_MIN_PRICE,
			self::META_FLUCTUATION,
			self::META_PROFIT_MODE,
			self::META_PROFIT_TYPE,
			self::META_PROFIT_VALUE,
			self::META_COMMISSION_TYPE,
			self::META_COMMISSION_VALUE,
			self::META_EXTRA_AMOUNT,
			self::META_LAST_PRICE,
			self::META_LAST_CALC,
			self::META_LAST_RATE,
		);
	}

	/**
	 * Meta keys that hold a store-unit money amount (unit-migration sensitive).
	 *
	 * @return string[]
	 */
	public static function store_amount_meta_keys(): array {
		return array(
			self::META_MIN_PRICE,
			self::META_FLUCTUATION,
			self::META_LAST_PRICE,
		);
	}

	/**
	 * Meta keys holding a fixed (non-percent) product-level override amount.
	 *
	 * @return string[]
	 */
	public static function fixed_override_meta_keys(): array {
		return array(
			self::META_PROFIT_VALUE,
			self::META_COMMISSION_VALUE,
			self::META_EXTRA_AMOUNT,
		);
	}

	/**
	 * Whether a post/product id is managed by the plugin.
	 *
	 * @param int $post_id Product or variation id.
	 * @return bool
	 */
	public static function is_enabled( int $post_id ): bool {
		return 'yes' === get_post_meta( $post_id, self::META_ENABLED, true );
	}

	/**
	 * Currency code stored on a product ('' when not set).
	 *
	 * @param int $post_id Product or variation id.
	 * @return string
	 */
	public static function currency_code( int $post_id ): string {
		return strtoupper( trim( (string) get_post_meta( $post_id, self::META_CURRENCY, true ) ) );
	}

	/**
	 * Sanitised base price (0 when empty/invalid).
	 *
	 * @param int $post_id Product or variation id.
	 * @return float
	 */
	public static function base_price( int $post_id ): float {
		return self::to_decimal( get_post_meta( $post_id, self::META_BASE_PRICE, true ) );
	}

	/**
	 * Sanitised sale base price (0 when empty/invalid).
	 *
	 * @param int $post_id Product or variation id.
	 * @return float
	 */
	public static function sale_base_price( int $post_id ): float {
		return self::to_decimal( get_post_meta( $post_id, self::META_SALE_BASE_PRICE, true ) );
	}

	/**
	 * Read the pricing settings of a product/variation.
	 *
	 * @param int $post_id Product or variation id.
	 * @return array<string, mixed> {
	 *     @type bool   $enabled      Managed flag.
	 *     @type string $currency     Currency code.
	 *     @type float  $base_price   Base price in the foreign unit.
	 *     @type float  $sale_price   Sale base price (0 = none).
	 *     @type float  $min_price    Minimum final price (0 = none).
	 *     @type float  $fluctuation  Fluctuation margin (0 = none).
	 *     @type string $profit_mode  none|add|replace.
	 *     @type array  $profit       Override rule.
	 *     @type array  $commission   Override rule.
	 *     @type array  $extra        Override rule.
	 *     @type float  $previous     Last calculated regular price (0 = none).
	 *     @type float  $previous_sale Last calculated sale price (0 = none).
	 * }
	 */
	public static function read( int $post_id ): array {
		$enabled = self::is_enabled( $post_id );

		return array(
			'enabled'       => $enabled,
			'currency'      => self::currency_code( $post_id ),
			'base_price'    => self::base_price( $post_id ),
			'sale_price'    => self::sale_base_price( $post_id ),
			'min_price'     => self::to_decimal( get_post_meta( $post_id, self::META_MIN_PRICE, true ) ),
			'fluctuation'   => self::to_decimal( get_post_meta( $post_id, self::META_FLUCTUATION, true ) ),
			'profit_mode'   => PriceCalculator::to_mode( get_post_meta( $post_id, self::META_PROFIT_MODE, true ) ),
			'profit'        => self::read_rule( $post_id, self::META_PROFIT_TYPE, self::META_PROFIT_VALUE ),
			'commission'    => self::read_rule( $post_id, self::META_COMMISSION_TYPE, self::META_COMMISSION_VALUE ),
			// The extra amount is always a fixed store amount (no type meta).
			'extra'         => array(
				'type'  => PriceCalculator::TYPE_FIXED,
				'value' => self::to_decimal( get_post_meta( $post_id, self::META_EXTRA_AMOUNT, true ) ),
			),
			'previous'      => self::to_decimal( get_post_meta( $post_id, self::META_LAST_PRICE, true ) ),
			'previous_sale' => 0.0,
		);
	}

	/**
	 * Read one override rule (type + value meta pair).
	 *
	 * @param int    $post_id  Post id.
	 * @param string $type_key Meta key holding the type.
	 * @param string $value_key Meta key holding the value.
	 * @param string $default  Default type when the meta is empty.
	 * @return array{type:string,value:float}
	 */
	private static function read_rule( int $post_id, string $type_key, string $value_key, string $default = 'percent' ): array {
		$type = (string) get_post_meta( $post_id, $type_key, true );

		return array(
			'type'  => PriceCalculator::to_type( '' === $type ? $default : $type ),
			'value' => self::to_decimal( get_post_meta( $post_id, $value_key, true ) ),
		);
	}

	/**
	 * Parse a posted decimal (handles Persian digits, separators, spaces).
	 *
	 * @param mixed $value Raw value.
	 * @return float Non-negative finite float, 0 for empty/invalid.
	 */
	public static function to_decimal( mixed $value ): float {
		if ( is_array( $value ) || is_object( $value ) || is_bool( $value ) ) {
			return 0.0;
		}

		if ( is_string( $value ) ) {
			$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
			$value   = str_replace( $persian, array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), (string) $value );
			$value   = str_replace( array( ',', ' ', "\xc2\xa0", '٫' ), '', trim( $value ) );
		}

		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		$value = (float) $value;

		return is_finite( $value ) && $value > 0 ? $value : 0.0;
	}

	/**
	 * Settings array consumable by PriceCalculator for one product post.
	 *
	 * @param int $post_id        Product or variation id.
	 * @param array{type:string,value:float} $profit     Resolved profit rule.
	 * @param array{type:string,value:float} $commission Resolved commission rule.
	 * @param array{type:string,value:float} $extra      Resolved extra rule.
	 * @param float $previous     Previous calculated price.
	 * @param array<string, mixed> $overrides Additional calculator overrides (rounding…).
	 * @return array<string, mixed>
	 */
	public static function calculator_settings(
		int $post_id,
		array $profit,
		array $commission,
		array $extra,
		float $previous = 0.0,
		array $overrides = array()
	): array {
		$data = self::read( $post_id );

		return array_merge(
			array(
				'profit'         => $profit,
				'commission'     => $commission,
				'extra'          => $extra,
				'min_price'      => $data['min_price'],
				'rounding_mode'  => Settings::rounding_mode(),
				'rounding_step'  => Settings::rounding_step(),
				'decimals'       => Settings::store_decimals(),
				'fluctuation'    => $data['fluctuation'],
				'previous_price' => $previous,
			),
			$overrides
		);
	}

	/**
	 * Resolve the effective profit/commission/extra rules for a product post.
	 *
	 * Merges the product-level override with the currency-level values using the
	 * product's mode, so the preview and the repricer produce identical numbers.
	 *
	 * @param int      $post_id     Product or variation id.
	 * @param \RateBridge\Currency\Currency $currency Currency.
	 * @param float    $base_value  Converted base value (base_price x rate).
	 * @return array{profit:array,commission:array,extra:array,converted:array<string,bool>}
	 */
	public static function resolve_rules( int $post_id, \RateBridge\Currency\Currency $currency, float $base_value ): array {
		$data = self::read( $post_id );
		$mode = (string) $data['profit_mode'];

		$profit = PriceCalculator::merge_rules( $currency->profit_rule(), $data['profit'], $mode, $base_value );
		$after  = $base_value + PriceCalculator::rule_amount( $profit, $base_value );

		$commission = PriceCalculator::merge_rules( $currency->commission_rule(), $data['commission'], $mode, $after );
		$extra      = PriceCalculator::merge_rules( $currency->extra_rule(), $data['extra'], $mode, $after );

		return array(
			'profit'     => array(
				'type'  => $profit['type'],
				'value' => $profit['value'],
			),
			'commission' => array(
				'type'  => $commission['type'],
				'value' => $commission['value'],
			),
			'extra'      => array(
				'type'  => $extra['type'],
				'value' => $extra['value'],
			),
			'converted'  => array(
				'profit'     => (bool) $profit['converted'],
				'commission' => (bool) $commission['converted'],
				'extra'      => (bool) $extra['converted'],
			),
		);
	}
}
