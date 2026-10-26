<?php
/**
 * Settings access layer.
 *
 * All plugin settings live in a single option array (`rbw_settings`) so that
 * they can be fetched once and cached by the options API.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Typed getters/setters for the plugin settings.
 */
final class Settings {

	/**
	 * Option name holding every plugin setting.
	 */
	public const OPTION = 'rbw_settings';

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Display unit for calculated prices: 'rial' or 'toman'.
			'store_currency_unit'       => 'toman',
			// Rounding mode: none | nearest | ceil | floor.
			'rounding_mode'             => 'nearest',
			// Rounding step, e.g. 1000, 10000, 100000.
			'rounding_step'             => 1000,
			// Default refresh interval (minutes) for currencies that do not override it.
			'default_update_interval'   => 60,
			// Delete all plugin data when the plugin is uninstalled.
			'delete_data_on_uninstall'  => false,
			// Default provider key for newly added currencies.
			'default_provider'          => 'manual',
			// Maximum age (hours) of a rate before products stop being repriced: 0 = disabled.
			'max_rate_age_hours'        => 48,
			// Show the rate line to customers on orders (emails, My Account…). Off by default.
			'show_rate_in_orders'       => false,
			// Product page info block.
			'product_block_enabled'     => false,
			'product_block_position'    => 'after_price',
			'product_block_template'    => '',
			// Date format (token pattern) for absolute dates in the frontend output.
			'date_format'               => 'Y-m-d H:i',
			// Persian digits in the plugin's own output.
			'use_persian_digits'        => false,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist settings, sanitising each known key.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, mixed> The stored settings.
	 */
	public static function update( array $values ): array {
		$current = self::all();
		$clean   = $current;

		if ( isset( $values['store_currency_unit'] ) ) {
			$unit              = sanitize_key( (string) $values['store_currency_unit'] );
			$clean['store_currency_unit'] = in_array( $unit, array( 'rial', 'toman' ), true ) ? $unit : $current['store_currency_unit'];
		}

		if ( isset( $values['rounding_mode'] ) ) {
			$mode               = sanitize_key( (string) $values['rounding_mode'] );
			$clean['rounding_mode'] = in_array( $mode, array( 'none', 'nearest', 'ceil', 'floor' ), true ) ? $mode : $current['rounding_mode'];
		}

		if ( isset( $values['rounding_step'] ) ) {
			$step                    = absint( $values['rounding_step'] );
			$clean['rounding_step'] = max( 1, $step );
		}

		if ( isset( $values['default_update_interval'] ) ) {
			$clean['default_update_interval'] = self::sanitize_interval( $values['default_update_interval'] );
		}

		if ( array_key_exists( 'delete_data_on_uninstall', $values ) ) {
			$clean['delete_data_on_uninstall'] = (bool) $values['delete_data_on_uninstall'];
		}

		if ( isset( $values['default_provider'] ) ) {
			$clean['default_provider'] = sanitize_key( (string) $values['default_provider'] );
		}

		if ( array_key_exists( 'max_rate_age_hours', $values ) ) {
			$clean['max_rate_age_hours'] = self::sanitize_max_rate_age( $values['max_rate_age_hours'] );
		}

		if ( array_key_exists( 'show_rate_in_orders', $values ) ) {
			$clean['show_rate_in_orders'] = self::to_bool( $values['show_rate_in_orders'] );
		}

		if ( array_key_exists( 'product_block_enabled', $values ) ) {
			$clean['product_block_enabled'] = self::to_bool( $values['product_block_enabled'] );
		}

		if ( isset( $values['product_block_position'] ) ) {
			$clean['product_block_position'] = self::sanitize_position( $values['product_block_position'] );
		}

		if ( array_key_exists( 'product_block_template', $values ) ) {
			$clean['product_block_template'] = self::sanitize_template( $values['product_block_template'] );
		}

		if ( isset( $values['date_format'] ) ) {
			$clean['date_format'] = self::sanitize_date_format( $values['date_format'] );
		}

		if ( array_key_exists( 'use_persian_digits', $values ) ) {
			$clean['use_persian_digits'] = self::to_bool( $values['use_persian_digits'] );
		}

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Clamp an interval (minutes) to the plugin minimum.
	 *
	 * @param mixed $value Raw interval in minutes.
	 * @return int
	 */
	public static function sanitize_interval( mixed $value ): int {
		$interval = absint( $value );

		return max( self::min_interval(), $interval );
	}

	/**
	 * Minimum allowed refresh interval in minutes (also the cron interval).
	 *
	 * @return int
	 */
	public static function min_interval(): int {
		/**
		 * Filter the minimum allowed refresh interval in minutes.
		 *
		 * @param int $minutes Minimum interval.
		 */
		return max( 5, (int) apply_filters( 'rbw_min_update_interval', 5 ) );
	}

	/**
	 * Store currency unit selected by the admin.
	 *
	 * @return string Either 'rial' or 'toman'.
	 */
	public static function store_unit(): string {
		return (string) self::get( 'store_currency_unit', 'toman' );
	}

	/**
	 * Maximum age of a rate (hours) before repricing stops. 0 disables the rule.
	 *
	 * @return int
	 */
	public static function max_rate_age_hours(): int {
		return self::sanitize_max_rate_age( self::get( 'max_rate_age_hours', 48 ) );
	}

	/**
	 * Clamp the stale-rate limit (0 = disabled, at most one year).
	 *
	 * @param mixed $value Raw hours.
	 * @return int
	 */
	public static function sanitize_max_rate_age( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 48;
		}

		$hours = (int) $value;

		return max( 0, min( 24 * 365, $hours ) );
	}

	/**
	 * Number of decimals the store currency uses.
	 *
	 * Toman and Rial stores are whole units, so this is 0 unless a site filters it.
	 *
	 * @return int
	 */
	public static function store_decimals(): int {
		/**
		 * Filter the number of decimals used for calculated prices.
		 *
		 * @param int    $decimals   Decimal places.
		 * @param string $store_unit 'rial' or 'toman'.
		 */
		$decimals = (int) apply_filters( 'rbw_store_decimals', 0, self::store_unit() );

		return max( 0, min( 6, $decimals ) );
	}

	/**
	 * Interpret a boolean-ish submitted value.
	 *
	 * Accepts real booleans, 1/0 ints and the '1'/'0'/'yes'/'true' strings the
	 * admin JS sends.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Validate the product-block position.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_position( mixed $value ): string {
		$position = sanitize_key( is_string( $value ) ? $value : '' );

		return array_key_exists( $position, \RateBridge\Frontend\ProductNotice::positions() )
			? $position
			: 'after_price';
	}

	/**
	 * Validate the product-block template.
	 *
	 * Kept as a kses-limited safe subset; an empty value means "use the default".
	 *
	 * @param mixed $value Raw template.
	 * @return string
	 */
	public static function sanitize_template( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$template = trim( $value );

		if ( '' === $template ) {
			return '';
		}

		if ( function_exists( 'wp_kses' ) ) {
			$template = wp_kses(
				$template,
				array(
					'strong' => array(),
					'em'     => array(),
					'b'      => array(),
					'i'      => array(),
					'br'     => array(),
					'span'   => array( 'class' => true ),
				)
			);
		}

		return mb_substr( $template, 0, 500 );
	}

	/**
	 * Validate the date format token pattern.
	 *
	 * Only the documented tokens and a few literal separators survive, so a
	 * stored value can never inject arbitrary `date()` characters.
	 *
	 * @param mixed $value Raw format.
	 * @return string
	 */
	public static function sanitize_date_format( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return 'Y-m-d H:i';
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return 'Y-m-d H:i';
		}

		$clean = preg_replace( '/[^YmdjHis\-\/: .]/', '', $value ) ?? '';

		return '' === $clean ? 'Y-m-d H:i' : mb_substr( $clean, 0, 32 );
	}

	/**
	 * Product page block template with the default applied.
	 *
	 * @return string
	 */
	public static function product_block_template(): string {
		$template = (string) self::get( 'product_block_template', '' );

		return '' === $template ? \RateBridge\Frontend\ProductNotice::DEFAULT_TEMPLATE : $template;
	}

	/**
	 * Rounding mode used by the calculator.
	 *
	 * @return string none|nearest|ceil|floor
	 */
	public static function rounding_mode(): string {
		$mode = (string) self::get( 'rounding_mode', 'nearest' );

		return in_array( $mode, array( 'none', 'nearest', 'ceil', 'floor' ), true ) ? $mode : 'nearest';
	}

	/**
	 * Rounding step in the store unit.
	 *
	 * @return float
	 */
	public static function rounding_step(): float {
		return max( 1.0, (float) self::get( 'rounding_step', 1000 ) );
	}
}
