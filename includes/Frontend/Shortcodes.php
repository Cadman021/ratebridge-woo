<?php
/**
 * Frontend shortcodes.
 *
 * Registered on `init` but the expensive work only runs when a shortcode is
 * actually rendered, so a site that never uses them pays nothing beyond four
 * function registrations.
 *
 * @package RateBridge
 */

namespace RateBridge\Frontend;

use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Product\ProductData;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `[rbw_rate]`, `[rbw_rates]`, `[rbw_ticker]`, `[rbw_product_rate]`
 * and `[rbw_product_updated]`.
 */
final class Shortcodes {

	/**
	 * Columns `[rbw_rates]` understands.
	 *
	 * @return array<string, string> Column key => Persian header.
	 */
	public static function columns(): array {
		return array(
			'name'    => __( 'Name', 'ratebridge-woo' ),
			'code'    => __( 'Code', 'ratebridge-woo' ),
			'symbol'  => __( 'Symbol', 'ratebridge-woo' ),
			'rate'    => __( 'Rate', 'ratebridge-woo' ),
			'updated' => __( 'Last update', 'ratebridge-woo' ),
			'source'  => __( 'Source', 'ratebridge-woo' ),
		);
	}

	/**
	 * Register the shortcodes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'rbw_rate', array( $this, 'shortcode_rate' ) );
		add_shortcode( 'rbw_rates', array( $this, 'shortcode_rates' ) );
		add_shortcode( 'rbw_ticker', array( $this, 'shortcode_ticker' ) );
		add_shortcode( 'rbw_product_rate', array( $this, 'shortcode_product_rate' ) );
		add_shortcode( 'rbw_product_updated', array( $this, 'shortcode_product_updated' ) );
	}

	/**
	 * Normalise a comma/space separated currency list.
	 *
	 * @param mixed $raw Raw attribute.
	 * @return string[]
	 */
	private function parse_codes( mixed $raw ): array {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$parts = preg_split( '/[\s,]+/', sanitize_text_field( $raw ) );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$codes = array();

		foreach ( $parts as $part ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $part ) ?? '' );

			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * `[rbw_rate currency="USD" template="..." show_updated="no"]`
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_rate( $atts ): string {
		$atts = shortcode_atts(
			array(
				'currency'     => '',
				'template'     => '%name%: %rate% %unit%',
				'show_updated' => 'no',
				'class'        => '',
			),
			(is_array( $atts ) ? $atts : array()),
			'rbw_rate'
		);

		$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $atts['currency'] ) ?? '' );

		if ( '' === $code ) {
			return Frontend::warning( __( 'The [rbw_rate] shortcode needs a currency attribute, for example currency="USD".', 'ratebridge-woo' ) );
		}

		$template = (string) $atts['template'];

		if ( 'yes' === strtolower( (string) $atts['show_updated'] ) && ! str_contains( $template, '%updated%' ) ) {
			$template .= ' — %updated%';
		}

		$cache_key = wp_json_encode( array( 'rate', $code, $template, $atts['class'], Frontend::use_persian_digits(), Settings::store_unit() ) );

		return Frontend::cached(
			(string) $cache_key,
			function () use ( $code, $template, $atts ): string {
				$currencies = Frontend::resolve_currencies( array( $code ) );

				if ( empty( $currencies ) ) {
					return Frontend::warning(
						sprintf(
							/* translators: %s: currency code */
							__( 'The currency %s does not exist, is disabled, or has no rate yet.', 'ratebridge-woo' ),
							$code
						)
					);
				}

				$frontend = new self();
				$html     = $frontend->render_rate_item( $currencies[0], $template );

				if ( '' === $html ) {
					return '';
				}

				$class = $this->sanitize_class( (string) $atts['class'] );

				return sprintf(
					'<span class="rbw-rate%s">%s</span>',
					'' !== $class ? ' ' . esc_attr( $class ) : '',
					$html
				);
			}
		);
	}

	/**
	 * Render one currency through a template (already escaped).
	 *
	 * Public API shared with the Elementor widgets.
	 *
	 * @param Currency $currency Currency.
	 * @param string   $template Template.
	 * @return string
	 */
	public function render_rate_item( Currency $currency, string $template ): string {
		$frontend = new Frontend();

		return $frontend->render( $currency, $template );
	}

	/**
	 * Build the ticker markup from resolved currencies.
	 *
	 * Shared by `[rbw_ticker]` and the Elementor ticker widget — one
	 * implementation, one CSS contract.
	 *
	 * @param Currency[] $currencies Currencies to show.
	 * @param string     $template   Item template.
	 * @param int        $speed      Duration in seconds (5–600).
	 * @param bool       $pause      Pause on hover.
	 * @param string     $class      Extra CSS classes.
	 * @return string
	 */
	public function render_ticker( array $currencies, string $template, int $speed = 30, bool $pause = true, string $class = '' ): string {
		if ( empty( $currencies ) ) {
			return '';
		}

		$speed = min( 600, max( 5, $speed ) );
		$items = array();

		foreach ( $currencies as $currency ) {
			$items[] = sprintf(
				'<span class="rbw-ticker-item">%s</span>',
				$this->render_rate_item( $currency, $template )
			);
		}

		$items_html = implode( '<span class="rbw-ticker-sep" aria-hidden="true"></span>', $items );

		$classes = trim(
			'rbw-ticker' .
			( $pause ? ' rbw-ticker-pause' : '' ) .
			( '' !== $class ? ' ' . $this->sanitize_class( $class ) : '' )
		);

		/*
		 * The track is duplicated so the CSS translate loop is seamless.
		 * aria-hidden on the copy keeps screen readers from reading the rates
		 * twice.
		 */
		return sprintf(
			'<div class="%1$s" style="%2$s"><div class="rbw-ticker-track"><div class="rbw-ticker-group">%3$s</div><div class="rbw-ticker-group" aria-hidden="true">%3$s</div></div></div>',
			esc_attr( $classes ),
			esc_attr( sprintf( '--rbw-ticker-duration:%ds;', $speed ) ),
			$items_html
		);
	}

	/**
	 * Sanitise an extra CSS class.
	 *
	 * @param string $class Raw class attribute.
	 * @return string
	 */
	private function sanitize_class( string $class ): string {
		if ( '' === $class ) {
			return '';
		}

		$parts = preg_split( '/\s+/', $class );
		$clean = array();

		foreach ( (array) $parts as $part ) {
			$part = sanitize_html_class( (string) $part );

			if ( '' !== $part ) {
				$clean[] = $part;
			}
		}

		return implode( ' ', array_slice( $clean, 0, 4 ) );
	}

	/**
	 * `[rbw_rates currencies="USD,EUR" columns="name,symbol,rate" title="..."]`
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode_rates( $atts ): string {
		$atts = shortcode_atts(
			array(
				'currencies' => '',
				'columns'    => 'name,rate,updated',
				'title'      => '',
				'class'      => '',
			),
			(is_array( $atts ) ? $atts : array()),
			'rbw_rates'
		);

		$codes   = $this->parse_codes( $atts['currencies'] );
		$columns = $this->parse_columns( (string) $atts['columns'] );
		$title   = sanitize_text_field( (string) $atts['title'] );

		if ( empty( $columns ) ) {
			$columns = array( 'name', 'rate', 'updated' );
		}

		$cache_key = wp_json_encode( array( 'rates', $codes, $columns, $title, $atts['class'], Frontend::use_persian_digits(), Settings::store_unit() ) );

		return Frontend::cached(
			(string) $cache_key,
			function () use ( $codes, $columns, $title, $atts ): string {
				$currencies = Frontend::resolve_currencies( $codes );

				if ( empty( $currencies ) ) {
					return Frontend::warning(
						__( 'No enabled currency with a rate is available for the currencies table.', 'ratebridge-woo' )
					);
				}

				return $this->render_table( $currencies, $columns, $title, (string) $atts['class'] );
			}
		);
	}

	/**
	 * Parse and validate the `columns` attribute.
	 *
	 * @param string $raw Raw attribute.
	 * @return string[]
	 */
	private function parse_columns( string $raw ): array {
		$allowed = array_keys( self::columns() );
		$parts   = preg_split( '/[\s,]+/', strtolower( $raw ) );
		$out     = array();

		foreach ( (array) $parts as $part ) {
			$part = sanitize_key( (string) $part );

			if ( in_array( $part, $allowed, true ) && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Build the responsive currencies table.
	 *
	 * @param Currency[] $currencies Currencies.
	 * @param string[]   $columns    Column keys.
	 * @param string     $title      Optional caption.
	 * @param string     $class      Extra CSS class.
	 * @return string
	 */
	public function render_table( array $currencies, array $columns, string $title = '', string $class = '' ): string {
		$headers = self::columns();
		$unit    = Frontend::unit_label();

		$html = sprintf(
			'<div class="rbw-rates-wrap%s">',
			'' !== $class ? ' ' . esc_attr( $this->sanitize_class( $class ) ) : ''
		);

		if ( '' !== $title ) {
			$html .= sprintf( '<div class="rbw-rates-title">%s</div>', esc_html( $title ) );
		}

		$html .= '<div class="rbw-rates-scroll" role="region" tabindex="0">';
		$html .= '<table class="rbw-rates"><thead><tr>';

		foreach ( $columns as $column ) {
			$label = $headers[ $column ] ?? $column;

			if ( 'rate' === $column ) {
				$label = sprintf(
					/* translators: %s: unit label (Toman/Rial) */
					__( 'Rate (%s)', 'ratebridge-woo' ),
					$unit
				);
			}

			$html .= sprintf(
				'<th scope="col" class="rbw-col-%s">%s</th>',
				esc_attr( $column ),
				esc_html( $label )
			);
		}

		$html .= '</tr></thead><tbody>';

		foreach ( $currencies as $currency ) {
			$html .= '<tr>';

			foreach ( $columns as $column ) {
				$html .= sprintf(
					'<td class="rbw-col-%s" data-label="%s">%s</td>',
					esc_attr( $column ),
					esc_attr( $headers[ $column ] ?? $column ),
					$this->cell( $currency, $column )
				);
			}

			$html .= '</tr>';
		}

		$html .= '</tbody></table></div></div>';

		return $html;
	}

	/**
	 * Render one table cell (already escaped).
	 *
	 * @param Currency $currency Currency.
	 * @param string   $column   Column key.
	 * @return string
	 */
	private function cell( Currency $currency, string $column ): string {
		switch ( $column ) {
			case 'name':
				return sprintf(
					'<span class="rbw-name">%s</span>',
					esc_html( $currency->name )
				);

			case 'code':
				return sprintf( '<code class="rbw-code">%s</code>', esc_html( $currency->code ) );

			case 'symbol':
				return sprintf( '<span class="rbw-symbol">%s</span>', esc_html( Frontend::symbol_or_code( $currency ) ) );

			case 'rate':
				return sprintf(
					'<span class="rbw-value" dir="ltr">%s</span>',
					esc_html( Frontend::format_rate( $currency->rate ) )
				);

			case 'updated':
				$timestamp = Frontend::currency_timestamp( $currency );

				return $timestamp > 0
					? sprintf(
						'<time class="rbw-updated" datetime="%s">%s</time>',
						esc_attr( gmdate( 'c', $timestamp ) ),
						esc_html( Frontend::time_ago( $timestamp ) )
					)
					: '<span class="rbw-updated rbw-muted">—</span>';

			case 'source':
				return sprintf( '<span class="rbw-source">%s</span>', esc_html( Frontend::source_label( $currency ) ) );
		}

		return '';
	}

	/**
	 * `[rbw_ticker currencies="USD,EUR" speed="30" pause_on_hover="yes"]`
	 *
	 * Pure CSS animation, no JavaScript. With `prefers-reduced-motion` the track
	 * is simply a wrapped list.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode_ticker( $atts ): string {
		$atts = shortcode_atts(
			array(
				'currencies'     => '',
				'speed'          => '30',
				'pause_on_hover' => 'yes',
				'template'       => '%name% <span class="rbw-ticker-rate">%rate%</span> %unit%',
				'class'          => '',
			),
			(is_array( $atts ) ? $atts : array()),
			'rbw_ticker'
		);

		$codes = $this->parse_codes( $atts['currencies'] );
		$speed = min( 600, max( 5, absint( $atts['speed'] ) ?: 30 ) );
		$pause = 'yes' === strtolower( (string) $atts['pause_on_hover'] );

		$template = (string) $atts['template'];

		$cache_key = wp_json_encode( array( 'ticker', $codes, $speed, $pause, $template, $atts['class'], Frontend::use_persian_digits(), Settings::store_unit() ) );

		return Frontend::cached(
			(string) $cache_key,
			function () use ( $codes, $speed, $pause, $template, $atts ): string {
				$currencies = Frontend::resolve_currencies( $codes );

				if ( empty( $currencies ) ) {
					return Frontend::warning( __( 'No enabled currency with a rate is available for the ticker.', 'ratebridge-woo' ) );
				}

				return $this->render_ticker( $currencies, $template, $speed, $pause, (string) $atts['class'] );
			}
		);
	}

	/**
	 * `[rbw_product_rate id=""]` — the current rate of the product's currency.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode_product_rate( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => '',
				'template' => '%rate% %unit%',
			),
			(is_array( $atts ) ? $atts : array()),
			'rbw_product_rate'
		);

		$product = Frontend::current_product( absint( $atts['id'] ) );

		if ( null === $product ) {
			return '';
		}

		$currency = Frontend::product_currency( $product );

		if ( ! $currency instanceof Currency || ! $currency->has_rate() ) {
			return '';
		}

		$template  = (string) $atts['template'];
		$cache_key = wp_json_encode( array( 'prate', $product->get_id(), $currency->code, $currency->rate, $template, Frontend::use_persian_digits(), Settings::store_unit() ) );

		return Frontend::cached(
			(string) $cache_key,
			fn (): string => sprintf(
				'<span class="rbw-product-rate">%s</span>',
				( new Frontend() )->render( $currency, $template )
			)
		);
	}

	/**
	 * `[rbw_product_updated id=""]` — last price calculation / rate update.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode_product_updated( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'     => '',
				'format' => 'relative',
			),
			(is_array( $atts ) ? $atts : array()),
			'rbw_product_updated'
		);

		$product = Frontend::current_product( absint( $atts['id'] ) );

		if ( null === $product ) {
			return '';
		}

		$currency = Frontend::product_currency( $product );

		if ( ! $currency instanceof Currency ) {
			return '';
		}

		// Prefer the moment the price was calculated; fall back to the rate time.
		$product_id = (int) $product->get_id();
		$calc_time  = (int) ProductData::to_decimal( get_post_meta( $product_id, ProductData::META_LAST_CALC, true ) );

		if ( $calc_time <= 0 && $product->is_type( 'variation' ) ) {
			$calc_time = (int) ProductData::to_decimal( get_post_meta( (int) $product->get_parent_id(), ProductData::META_LAST_CALC, true ) );
		}

		if ( $calc_time <= 0 ) {
			$calc_time = Frontend::currency_timestamp( $currency );
		}

		if ( $calc_time <= 0 ) {
			return '';
		}

		$absolute  = 'absolute' === strtolower( (string) $atts['format'] );
		$cache_key = wp_json_encode( array( 'pupdated', $product->get_id(), $calc_time, $absolute, Frontend::use_persian_digits() ) );

		return Frontend::cached(
			(string) $cache_key,
			static function () use ( $calc_time, $absolute ): string {
				$text = $absolute
					? Frontend::format_date( $calc_time, (string) Settings::get( 'date_format', 'Y-m-d H:i' ) )
					: Frontend::time_ago( $calc_time );

				return sprintf(
					'<span class="rbw-product-updated"><time datetime="%s">%s</time></span>',
					esc_attr( gmdate( 'c', $calc_time ) ),
					esc_html( $text )
				);
			}
		);
	}
}
