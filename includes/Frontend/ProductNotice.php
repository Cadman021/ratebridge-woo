<?php
/**
 * Optional "currency info" block on single product pages.
 *
 * Off by default. Reuses the same Frontend renderer as the shortcodes so the
 * Elementor widgets of a later phase can call the very same code.
 *
 * @package RateBridge
 */

namespace RateBridge\Frontend;

use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Product\ProductData;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the product page notice.
 */
final class ProductNotice {

	/**
	 * Template placeholder default.
	 */
	public const DEFAULT_TEMPLATE = 'نرخ %name%: %rate% %unit% — آخرین بروزرسانی: %updated%';

	/**
	 * Supported positions => hook name.
	 *
	 * @return array<string, string>
	 */
	public static function positions(): array {
		return array(
			'after_price'      => 'woocommerce_single_product_summary',
			'after_excerpt'    => 'woocommerce_single_product_summary',
			'after_add_to_cart' => 'woocommerce_single_product_summary',
		);
	}

	/**
	 * Priority per position on `woocommerce_single_product_summary`.
	 *
	 * WooCommerce prints the price at 10, the excerpt at 20 and the add-to-cart
	 * form at 30, so the notice slots in right after each of them.
	 *
	 * @param string $position Position key.
	 * @return int
	 */
	private static function priority( string $position ): int {
		switch ( $position ) {
			case 'after_excerpt':
				return 25;
			case 'after_add_to_cart':
				return 35;
			case 'after_price':
			default:
				return 15;
		}
	}

	/**
	 * Register the hook for the configured position.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! (bool) Settings::get( 'product_block_enabled', false ) ) {
			return;
		}

		$position = (string) Settings::get( 'product_block_position', 'after_price' );

		if ( ! array_key_exists( $position, self::positions() ) ) {
			$position = 'after_price';
		}

		add_action(
			(string) self::positions()[ $position ],
			array( $this, 'render' ),
			self::priority( $position )
		);
	}

	/**
	 * Render the notice on a single product page.
	 *
	 * @return void
	 */
	public function render(): void {
		$html = $this->html();

		if ( '' === $html ) {
			return;
		}

		Frontend::enqueue_styles();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html/wp_kses in Frontend::render().
	}

	/**
	 * The notice markup (empty when it should not be shown).
	 *
	 * @return string
	 */
	public function html(): string {
		if ( ! (bool) Settings::get( 'product_block_enabled', false ) ) {
			return '';
		}

		$product = Frontend::current_product();

		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$currency = $this->resolve_currency( $product );

		if ( ! $currency instanceof Currency || ! $currency->has_rate() ) {
			return '';
		}

		// An empty stored template means "use the default" (see Settings).
		$template = Settings::product_block_template();
		$cache_key = wp_json_encode(
			array(
				'product-block',
				$product->get_id(),
				$currency->code,
				$currency->rate,
				$currency->last_updated,
				$template,
				Frontend::use_persian_digits(),
				Settings::store_unit(),
			)
		);

		return Frontend::cached(
			(string) $cache_key,
			function () use ( $currency, $template ): string {
				$inner = ( new Frontend() )->render( $currency, $template );

				if ( '' === $inner ) {
					return '';
				}

				return sprintf(
					'<div class="rbw-product-notice" role="status">%s</div>',
					$inner
				);
			}
		);
	}

	/**
	 * Resolve the currency to display for a product.
	 *
	 * Uses the product's own currency, falling back to the parent's. For variable
	 * products whose variations use **different** currencies the block renders
	 * nothing: a single rate would be misleading (documented limitation).
	 *
	 * @param WC_Product $product Product.
	 * @return Currency|null
	 */
	private function resolve_currency( WC_Product $product ): ?Currency {
		$parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		$managed = ProductData::is_enabled( (int) $product->get_id() )
			|| ( $parent_id > 0 && ProductData::is_enabled( $parent_id ) );

		if ( ! $managed ) {
			return null;
		}

		$codes = $this->product_currency_codes( $product );

		// More than one distinct currency: stay silent rather than mislead.
		if ( count( $codes ) > 1 ) {
			return null;
		}

		$code = (string) reset( $codes );

		if ( '' === $code ) {
			return null;
		}

		$currency = Frontend::product_currency( $product );

		if ( $currency instanceof Currency && $currency->code === $code ) {
			return $currency;
		}

		$resolved = Frontend::resolve_currencies( array( $code ) );

		return isset( $resolved[0] ) ? $resolved[0] : null;
	}

	/**
	 * Every distinct currency code involved in a product.
	 *
	 * @param WC_Product $product Product.
	 * @return string[] Code => code (unique).
	 */
	private function product_currency_codes( WC_Product $product ): array {
		$codes = array();

		$own = ProductData::currency_code( (int) $product->get_id() );

		if ( '' !== $own ) {
			$codes[ $own ] = $own;
		}

		if ( $product->is_type( 'variable' ) ) {
			$parent = ProductData::currency_code( (int) $product->get_id() );

			if ( '' !== $parent ) {
				$codes[ $parent ] = $parent;
			}

			foreach ( (array) $product->get_children() as $child_id ) {
				$child_code = ProductData::currency_code( (int) $child_id );

				if ( '' !== $child_code ) {
					$codes[ $child_code ] = $child_code;
				}
			}
		} elseif ( $product->is_type( 'variation' ) ) {
			$parent_code = ProductData::currency_code( (int) $product->get_parent_id() );

			if ( '' !== $parent_code ) {
				$codes[ $parent_code ] = $parent_code;
			}
		}

		return array_values( $codes );
	}
}
