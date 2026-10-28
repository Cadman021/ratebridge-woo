<?php
/**
 * Shared frontend rendering (shortcodes and the product-page block).
 *
 * Everything here is WordPress-aware but presentation-only: it reads stored
 * rates from the currency repository, never calls a provider API, and returns
 * escaped HTML. Elementor widgets in a later phase can reuse these methods.
 *
 * @package RateBridge
 */

namespace RateBridge\Frontend;

use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Product\ProductData;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the HTML for rates, tables, tickers and product info.
 */
final class Frontend {

	/**
	 * Style handle.
	 */
	public const STYLE = 'rbw-frontend';

	/**
	 * Transient prefix for rendered fragments.
	 */
	public const CACHE_PREFIX = 'rbw_html_';

	/**
	 * Cache lifetime in seconds.
	 */
	public const CACHE_TTL = 60;

	/**
	 * Allowed HTML in a user-supplied template.
	 *
	 * Deliberately tiny: strong, em, span with class, br.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
			'span'   => array( 'class' => true ),
		);
	}

	/**
	 * Whether output should use Persian digits.
	 *
	 * @return bool
	 */
	public static function use_persian_digits(): bool {
		/**
		 * Filter whether the plugin's own output uses Persian digits.
		 *
		 * @param bool $enabled Whether Persian digits are enabled.
		 */
		return (bool) apply_filters( 'rbw_use_persian_digits', (bool) Settings::get( 'use_persian_digits', false ) );
	}

	/**
	 * Unit label for the current store unit.
	 *
	 * @return string
	 */
	public static function unit_label(): string {
		return RateFormatter::unit_label(
			Settings::store_unit(),
			__( 'Toman', 'ratebridge-woo' ),
			__( 'Rial', 'ratebridge-woo' )
		);
	}

	/**
	 * Format a rate for display (digits + decimals + Persian digits).
	 *
	 * @param float $rate Rate.
	 * @return string Plain text, escaped by the caller if needed.
	 */
	public static function format_rate( float $rate ): string {
		$decimals = $rate >= 1000 ? 0 : 4;
		$text     = RateFormatter::number( $rate, $decimals );

		return RateFormatter::maybe_persian( $text, self::use_persian_digits() );
	}

	/**
	 * Format any number for display.
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Decimals.
	 * @return string
	 */
	public static function format_number( float $value, int $decimals = 0 ): string {
		return RateFormatter::maybe_persian( RateFormatter::number( $value, $decimals ), self::use_persian_digits() );
	}

	/**
	 * Localised relative time.
	 *
	 * @param int $timestamp Past timestamp.
	 * @return string
	 */
	public static function time_ago( int $timestamp ): string {
		$parts = RateFormatter::time_ago_parts( $timestamp );

		switch ( $parts['unit'] ) {
			case 'unknown':
				return __( 'unknown', 'ratebridge-woo' );

			case 'now':
				return __( 'just now', 'ratebridge-woo' );

			case 'minute':
				return sprintf(
					/* translators: %s: number of minutes */
					_n( '%s minute ago', '%s minutes ago', $parts['value'], 'ratebridge-woo' ),
					self::format_number( (float) $parts['value'] )
				);

			case 'hour':
				return sprintf(
					/* translators: %s: number of hours */
					_n( '%s hour ago', '%s hours ago', $parts['value'], 'ratebridge-woo' ),
					self::format_number( (float) $parts['value'] )
				);

			case 'day':
				return sprintf(
					/* translators: %s: number of days */
					_n( '%s day ago', '%s days ago', $parts['value'], 'ratebridge-woo' ),
					self::format_number( (float) $parts['value'] )
				);

			case 'month':
				return sprintf(
					/* translators: %s: number of months */
					_n( '%s month ago', '%s months ago', $parts['value'], 'ratebridge-woo' ),
					self::format_number( (float) $parts['value'] )
				);

			case 'year':
				return sprintf(
					/* translators: %s: number of years */
					_n( '%s year ago', '%s years ago', $parts['value'], 'ratebridge-woo' ),
					self::format_number( (float) $parts['value'] )
				);
		}

		return '';
	}

	/**
	 * Display symbol, falling back to the currency code.
	 *
	 * An empty symbol must never produce an empty table cell or a blank
	 * `%symbol%` in a template: the code (e.g. USD) is the sensible stand-in.
	 *
	 * @param Currency $currency Currency.
	 * @return string
	 */
	public static function symbol_or_code( Currency $currency ): string {
		$symbol = trim( $currency->symbol );

		return '' !== $symbol ? $symbol : $currency->code;
	}

	/**
	 * Timestamp of a currency's last successful update.
	 *
	 * @param Currency $currency Currency.
	 * @return int 0 when unknown.
	 */
	public static function currency_timestamp( Currency $currency ): int {
		if ( null === $currency->last_updated || '' === $currency->last_updated ) {
			return 0;
		}

		$timestamp = strtotime( $currency->last_updated . ' UTC' );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Source (provider) label for a currency.
	 *
	 * @param Currency $currency Currency.
	 * @return string
	 */
	public static function source_label( Currency $currency ): string {
		$sources = \RateBridge\Providers\ProviderRegistry::instance()->choices();

		return isset( $sources[ $currency->provider ] )
			? (string) $sources[ $currency->provider ]
			: (string) $currency->provider;
	}

	/**
	 * Template values for one currency.
	 *
	 * @param Currency $currency Currency.
	 * @param string   $template Template (used to decide which values to build).
	 * @return array<string, string>
	 */
	public function template_values( Currency $currency, string $template ): array {
		$values = array();

		if ( str_contains( $template, '%name%' ) ) {
			$values['name'] = $currency->name;
		}

		if ( str_contains( $template, '%code%' ) ) {
			$values['code'] = $currency->code;
		}

		if ( str_contains( $template, '%symbol%' ) ) {
			$values['symbol'] = self::symbol_or_code( $currency );
		}

		if ( str_contains( $template, '%rate%' ) ) {
			$values['rate'] = self::format_rate( $currency->rate );
		}

		if ( str_contains( $template, '%unit%' ) ) {
			$values['unit'] = self::unit_label();
		}

		if ( str_contains( $template, '%updated%' ) ) {
			$values['updated'] = self::time_ago( self::currency_timestamp( $currency ) );
		}

		if ( str_contains( $template, '%updated_date%' ) ) {
			$values['updated_date'] = self::format_date(
				self::currency_timestamp( $currency ),
				(string) Settings::get( 'date_format', 'Y-m-d H:i' )
			);
		}

		if ( str_contains( $template, '%source%' ) ) {
			$values['source'] = self::source_label( $currency );
		}

		return $values;
	}

	/**
	 * Format a timestamp using the configured date format.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $format    Token format.
	 * @return string
	 */
	public static function format_date( int $timestamp, string $format ): string {
		if ( $timestamp <= 0 ) {
			return __( 'unknown', 'ratebridge-woo' );
		}

		// wp_date() honours the site timezone; RateFormatter::format_date() is the
		// testable fallback when WordPress is unavailable (CLI tests).
		if ( function_exists( 'wp_date' ) ) {
			$php_format = self::tokens_to_php( $format );
			$formatted  = wp_date( $php_format, $timestamp );
		} else {
			$formatted = RateFormatter::format_date( $timestamp, $format );
		}

		return RateFormatter::maybe_persian( (string) $formatted, self::use_persian_digits() );
	}

	/**
	 * Translate the documented token set to a PHP date() format.
	 *
	 * Only the tokens the plugin documents are translated, so a site admin cannot
	 * inject arbitrary PHP format characters.
	 *
	 * @param string $format Token format.
	 * @return string
	 */
	private static function tokens_to_php( string $format ): string {
		$map = array(
			'Y' => 'Y',
			'm' => 'm',
			'd' => 'd',
			'j' => 'j',
			'H' => 'H',
			'i' => 'i',
			's' => 's',
		);

		$out    = '';
		$length = strlen( $format );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $format[ $i ];

			if ( '\\' === $char && $i + 1 < $length ) {
				$out .= '\\' . $format[ ++$i ];
				continue;
			}

			$out .= $map[ $char ] ?? '\\' . $char;
		}

		return '' === $out ? 'Y-m-d H:i' : $out;
	}

	/**
	 * Render a template for one currency.
	 *
	 * The template itself survives as the kses-approved subset; every placeholder
	 * value is escaped before substitution, so currency names (admin input) can
	 * never inject markup.
	 *
	 * @param Currency $currency Currency.
	 * @param string   $template Template.
	 * @return string
	 */
	public function render( Currency $currency, string $template ): string {
		$template = RateFormatter::sanitize_template(
			$template,
			static fn ( string $html ): string => wp_kses( $html, self::allowed_html() )
		);

		if ( '' === $template ) {
			return '';
		}

		$values = $this->template_values( $currency, $template );
		$values = array_map( 'esc_html', $values );

		return wp_kses( RateFormatter::render_template( $template, $values ), self::allowed_html() );
	}

	/**
	 * Resolve a list of currency codes to enabled currencies with a rate.
	 *
	 * @param string[] $codes Currency codes (empty = all enabled).
	 * @return Currency[]
	 */
	public static function resolve_currencies( array $codes = array() ): array {
		$repository = new CurrencyRepository();
		$codes      = array_values( array_filter( array_map( 'strtoupper', array_map( 'trim', $codes ) ) ) );

		$all = $repository->get_enabled();

		if ( empty( $codes ) ) {
			return array_values( array_filter( $all, static fn ( Currency $c ): bool => $c->has_rate() ) );
		}

		$indexed = array();

		foreach ( $all as $currency ) {
			$indexed[ $currency->code ] = $currency;
		}

		$out = array();

		foreach ( $codes as $code ) {
			if ( isset( $indexed[ $code ] ) && $indexed[ $code ]->has_rate() ) {
				$out[] = $indexed[ $code ];
			}
		}

		return $out;
	}

	/**
	 * Register (not enqueue) the frontend stylesheets.
	 *
	 * Registering is what makes `get_style_depends()` work for the Elementor
	 * widgets: Elementor enqueues the handles itself, both on the front end and
	 * in the editor preview.
	 *
	 * @return void
	 */
	public static function register_styles(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE,
				RBW_PLUGIN_URL . 'assets/frontend/css/frontend.css',
				array(),
				RBW_VERSION
			);
		}

		$rtl = self::STYLE . '-rtl';

		if ( ! wp_style_is( $rtl, 'registered' ) ) {
			wp_register_style(
				$rtl,
				RBW_PLUGIN_URL . 'assets/frontend/css/frontend-rtl.css',
				array( self::STYLE ),
				RBW_VERSION
			);
		}
	}

	/**
	 * Every enabled currency, including those still waiting for a rate.
	 *
	 * Used by the admin-side widget controls so an editor can pick a currency
	 * that has no rate yet; the render path filters those out.
	 *
	 * @return Currency[]
	 */
	public static function resolve_currencies_all(): array {
		return ( new CurrencyRepository() )->get_enabled();
	}

	/**
	 * Enqueue the frontend stylesheet (only from a rendering callback).
	 *
	 * @return void
	 */
	public static function enqueue_styles(): void {
		self::register_styles();

		if ( wp_style_is( self::STYLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( self::STYLE );

		if ( is_rtl() ) {
			wp_enqueue_style( self::STYLE . '-rtl' );
		}
	}

	/**
	 * Render + cache an HTML fragment.
	 *
	 * @param string   $key     Cache key components (already sanitised).
	 * @param callable $builder Builder returning HTML.
	 * @return string
	 */
	public static function cached( string $key, callable $builder ): string {
		/*
		 * The key carries the current cache generation, so invalidation works
		 * with an external object cache too (bumping the counter makes every old
		 * key unreachable instead of trying to DELETE rows that are not there).
		 */
		$transient = \RateBridge\Core\CacheGeneration::key(
			self::CACHE_PREFIX,
			$key,
			\RateBridge\Core\CacheGeneration::get( 'frontend' )
		);

		$cached    = get_transient( $transient );

		if ( is_string( $cached ) && '' !== $cached ) {
			self::enqueue_styles();

			return $cached;
		}

		$html = (string) call_user_func( $builder );

		if ( '' !== $html ) {
			set_transient( $transient, $html, self::CACHE_TTL );
		}

		self::enqueue_styles();

		return $html;
	}

	/**
	 * Drop every cached fragment (called when rates/currencies change).
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		/*
		 * 1. Bump the generation: this is what actually invalidates the cache,
		 *    and it works with Redis/Memcached where the transients are not rows
		 *    in wp_options.
		 */
		\RateBridge\Core\CacheGeneration::bump( 'frontend' );

		/*
		 * 2. Best-effort row cleanup for sites without an external object cache,
		 *    so old fragments do not linger in the options table until they
		 *    expire. Never required for correctness.
		 */
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
			)
		);
	}

	/**
	 * Whether the current visitor may see diagnostics (admin only).
	 *
	 * @return bool
	 */
	public static function can_see_warnings(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Small visible warning, shown to shop managers only.
	 *
	 * @param string $message Message text.
	 * @return string
	 */
	public static function warning( string $message ): string {
		if ( ! self::can_see_warnings() ) {
			return '';
		}

		return sprintf(
			'<span class="rbw-warning-note" role="status">%s</span>',
			esc_html( $message )
		);
	}

	/**
	 * The product currently being viewed (for the product shortcodes/block).
	 *
	 * @param int $post_id Explicit product id (0 = current product).
	 * @return WC_Product|null
	 */
	public static function current_product( int $post_id = 0 ): ?WC_Product {
		if ( $post_id > 0 ) {
			$product = wc_get_product( $post_id );

			return $product instanceof WC_Product ? $product : null;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}

		$product = wc_get_product( get_queried_object_id() );

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Wrap a rendered fragment with an optional pre/post label and an icon.
	 *
	 * Shared by the Elementor widgets so the label/icon markup is identical for
	 * every widget type.
	 *
	 * @param string $inner    Already-escaped inner HTML.
	 * @param string $before   Plain-text label before the value.
	 * @param string $after    Plain-text label after the value.
	 * @param string $icon     Already-escaped icon markup ('' = none).
	 * @param string $base     Base CSS class.
	 * @param string $align    ''|left|center|right → adds `is-align-<x>`.
	 * @return string
	 */
	public static function wrap( string $inner, string $before = '', string $after = '', string $icon = '', string $base = 'rbw-widget', string $align = '' ): string {
		if ( '' === $inner ) {
			return '';
		}

		$classes = $base;
		$align   = strtolower( trim( $align ) );

		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$classes .= ' is-align-' . $align;
		}

		/*
		 * The outer element carries `rbw-widget-box`: a block-level box that owns
		 * the Elementor style controls (padding, background, border, shadow) and
		 * the `--rbw-accent` variable. Without it the padding was applied to an
		 * inline-flex wrapper and only shifted the surrounding layout.
		 */
		$html = sprintf( '<span class="%s rbw-widget-box">', esc_attr( $classes ) );

		if ( '' !== $icon ) {
			$html .= sprintf( '<span class="rbw-widget-icon" aria-hidden="true">%s</span>', $icon );
		}

		if ( '' !== trim( $before ) ) {
			$html .= sprintf( '<span class="rbw-widget-before">%s</span>', esc_html( $before ) );
		}

		$html .= sprintf( '<span class="rbw-widget-value">%s</span>', $inner );

		if ( '' !== trim( $after ) ) {
			$html .= sprintf( '<span class="rbw-widget-after">%s</span>', esc_html( $after ) );
		}

		return $html . '</span>';
	}

	/**
	 * Timestamp of the most recent successful rate update among enabled currencies.
	 *
	 * Powers the "site rates update date" widget.
	 *
	 * @return int 0 when no enabled currency has a rate yet.
	 */
	public static function latest_rate_timestamp(): int {
		$latest = 0;

		foreach ( ( new CurrencyRepository() )->get_enabled() as $currency ) {
			if ( ! $currency->has_rate() ) {
				continue;
			}

			$timestamp = self::currency_timestamp( $currency );

			if ( $timestamp > $latest ) {
				$latest = $timestamp;
			}
		}

		return $latest;
	}

	/**
	 * Last price-calculation timestamp of a product (variation aware).
	 *
	 * @param WC_Product $product Product.
	 * @return int 0 when the product was never calculated.
	 */
	public static function product_calc_timestamp( WC_Product $product ): int {
		$product_id = (int) $product->get_id();
		$timestamp  = (int) ProductData::to_decimal( get_post_meta( $product_id, ProductData::META_LAST_CALC, true ) );

		if ( $timestamp <= 0 && $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			$timestamp = (int) ProductData::to_decimal( get_post_meta( $parent_id, ProductData::META_LAST_CALC, true ) );
		}

		return max( 0, $timestamp );
	}

	/**
	 * Currency that prices a product (variation override aware).
	 *
	 * @param WC_Product $product Product.
	 * @return Currency|null Null when the product is not managed.
	 */
	public static function product_currency( WC_Product $product ): ?Currency {
		$product_id = (int) $product->get_id();
		$parent_id  = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		$managed = ProductData::is_enabled( $product_id )
			|| ( $parent_id > 0 && ProductData::is_enabled( $parent_id ) );

		if ( ! $managed ) {
			return null;
		}

		$code = ProductData::currency_code( $product_id );

		if ( '' === $code && $parent_id > 0 ) {
			$code = ProductData::currency_code( $parent_id );
		}

		if ( '' === $code ) {
			return null;
		}

		$currency = ( new CurrencyRepository() )->get_by_code( $code );

		return $currency instanceof Currency ? $currency : null;
	}

	/**
	 * Effective value of one formula variable for a product.
	 *
	 * Shared by `[rbw_formula_var]` and the Elementor “formula variable” widget.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $name    Variable name.
	 * @return array{name:string,label:string,type:string,value:float,formatted:string}|null
	 *         Null when the product has no formula or no such variable.
	 */
	public static function formula_variable_value( WC_Product $product, string $name ): ?array {
		$pricer  = new \RateBridge\Pricing\FormulaPricer();
		$formula = $pricer->formula_for( (int) $product->get_id() );

		if ( ! $formula instanceof \RateBridge\Formula\FormulaModel ) {
			return null;
		}

		$definition = null;

		foreach ( $formula->variables as $variable ) {
			if ( (string) $variable['name'] === $name ) {
				$definition = $variable;
				break;
			}
		}

		if ( null === $definition ) {
			return null;
		}

		$values = $pricer->resolve_variables( $formula, $product, false );

		if ( ! is_array( $values ) || ! array_key_exists( $name, $values ) ) {
			return null;
		}

		$value = (float) $values[ $name ];
		$type  = (string) $definition['type'];

		// Rates and money amounts use the store precision; plain numbers do not.
		$is_amount = \RateBridge\Formula\FormulaModel::TYPE_CURRENCY === $type || ! empty( $definition['is_money'] );
		$decimals  = $is_amount ? Settings::store_decimals() : ( abs( $value - round( $value ) ) < 0.000001 ? 0 : 2 );

		return array(
			'name'      => $name,
			'label'     => (string) ( $definition['label'] ?? $name ),
			'type'      => $type,
			'value'     => $value,
			'formatted' => self::format_number( $value, $decimals ),
		);
	}
}
