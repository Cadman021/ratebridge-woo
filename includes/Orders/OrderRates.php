<?php
/**
 * Order line item rate records.
 *
 * Every line item of a product priced by the plugin gets a hidden snapshot of
 * the currency, rate, base price and store unit used at checkout time. This is
 * strictly informational: totals are never touched or recalculated.
 *
 * @package RateBridge
 */

namespace RateBridge\Orders;

use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Product\ProductData;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and renders the `_rbw_*` order item meta.
 */
final class OrderRates {

	/**
	 * Item meta: currency code.
	 */
	public const META_CURRENCY = '_rbw_currency';

	/**
	 * Item meta: rate used (store unit per 1 foreign unit).
	 */
	public const META_RATE = '_rbw_rate';

	/**
	 * Item meta: price in the foreign unit used for this line.
	 */
	public const META_BASE_PRICE = '_rbw_base_price';

	/**
	 * Item meta: timestamp of the rate's last update.
	 */
	public const META_RATE_TIME = '_rbw_rate_time';

	/**
	 * Item meta: store unit at that moment (toman|rial).
	 */
	public const META_UNIT = '_rbw_unit';

	/**
	 * Item meta: whether the sale base price was the one used.
	 */
	public const META_IS_SALE = '_rbw_is_sale';

	/**
	 * Item meta: pricing mode used for the line (`currency` or `formula`).
	 */
	public const META_MODE = '_rbw_mode';

	/**
	 * Item meta: formula name (formula mode only).
	 */
	public const META_FORMULA_NAME = '_rbw_formula_name';

	/**
	 * Item meta: JSON snapshot of every formula variable value used.
	 */
	public const META_FORMULA_VARS = '_rbw_formula_vars';

	/**
	 * All item meta keys written by the plugin.
	 *
	 * @return string[]
	 */
	public static function meta_keys(): array {
		return array(
			self::META_CURRENCY,
			self::META_RATE,
			self::META_BASE_PRICE,
			self::META_RATE_TIME,
			self::META_UNIT,
			self::META_IS_SALE,
			self::META_MODE,
			self::META_FORMULA_NAME,
			self::META_FORMULA_VARS,
		);
	}

	/**
	 * Register every hook.
	 *
	 * @return void
	 */
	public function register(): void {
		// Classic checkout (and any code path that creates order items).
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'on_create_line_item' ), 10, 3 );

		// Items added from the admin order editor ("Add product(s)" / "Add fee").
		add_action( 'woocommerce_new_order_item', array( $this, 'on_new_order_item' ), 10, 2 );

		// Block checkout / Store API: backfill once the order exists.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_order_processed' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_order_processed' ), 10, 1 );

		// Admin order screen: read-only line under the item totals.
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_admin_item_meta' ), 10, 3 );

		// Customer-facing display is opt-in.
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render_customer_item_meta' ), 10, 4 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'hide_internal_meta' ), 10, 2 );

		// The rate line is also rendered on admin order screens and in emails,
		// where the shortcode-enqueued stylesheet is not present.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Snapshot of the rate that produced a product's price.
	 *
	 * Resolves the effective currency through the existing ProductData logic:
	 * a variation's own currency override wins, otherwise the parent's. The sale
	 * base price is used when the line was actually charged the sale price.
	 *
	 * @param WC_Product $product    Product or variation.
	 * @param float|null $line_price Price charged for the unit (null = use product price).
	 * @return array<string, mixed>|null Null when the product is not managed.
	 */
	public function snapshot( WC_Product $product, ?float $line_price = null ): ?array {
		$product_id = (int) $product->get_id();

		if ( $product_id <= 0 ) {
			return null;
		}

		// A purchase variation is identified by its own meta or its parent's.
		$parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		$managed_id = $this->managed_post_id( $product_id, $parent_id );

		if ( 0 === $managed_id ) {
			return null;
		}

		/*
		 * Formula mode: record the formula and every variable value used, so the
		 * order line stays explainable years later even if the formula changes.
		 */
		if ( ProductData::is_formula_mode( $managed_id ) || ProductData::is_formula_mode( $product_id ) ) {
			return $this->formula_snapshot( $product );
		}

		$code = ProductData::currency_code( $product_id );

		if ( '' === $code && $parent_id > 0 ) {
			$code = ProductData::currency_code( $parent_id );
		}

		if ( '' === $code ) {
			return null;
		}

		$currency = ( new CurrencyRepository() )->get_by_code( $code );

		// A deleted/disabled currency has no rate to record.
		if ( ! $currency instanceof Currency || ! $currency->has_rate() ) {
			return null;
		}

		$base_price = ProductData::base_price( $product_id );
		$base_sale  = ProductData::sale_base_price( $product_id );
		$is_sale    = false;

		// Which base price the charged amount came from.
		$charged = null !== $line_price ? $line_price : (float) $product->get_price();

		if ( $base_sale > 0 ) {
			$sale_price = (float) $product->get_sale_price();

			if ( $sale_price > 0 && abs( $sale_price - $charged ) < 0.01 ) {
				$base_price = $base_sale;
				$is_sale    = true;
			}
		}

		if ( $base_price <= 0 ) {
			return null;
		}

		return array(
			'currency'    => $currency,
			'code'        => $currency->code,
			'rate'        => $currency->rate,
			'base_price'  => $base_price,
			'rate_time'   => $this->currency_rate_time( $currency ),
			'unit'        => Settings::store_unit(),
			'is_sale'     => $is_sale,
		);
	}

	/**
	 * Build the snapshot for a product priced by a formula.
	 *
	 * Records EVERY variable value the formula used (rates included), so the
	 * order line can be explained even after the formula or the rates change.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, mixed>|null Null when nothing could be recorded.
	 */
	private function formula_snapshot( WC_Product $product ): ?array {
		$pricer  = new \RateBridge\Pricing\FormulaPricer();
		$formula = $pricer->formula_for( (int) $product->get_id() );

		if ( ! $formula instanceof \RateBridge\Formula\FormulaModel ) {
			return null;
		}

		// Non-strict: record whatever could be resolved, never block checkout.
		$values = $pricer->resolve_variables( $formula, $product, false );

		if ( ! is_array( $values ) ) {
			$values = array();
		}

		$labels = array();

		foreach ( $formula->variables as $variable ) {
			$labels[ (string) $variable['name'] ] = (string) ( $variable['label'] ?? $variable['name'] );
		}

		$rows = array();

		foreach ( $values as $name => $value ) {
			$rows[] = array(
				'name'  => (string) $name,
				'label' => (string) ( $labels[ $name ] ?? $name ),
				'value' => (float) $value,
			);
		}

		return array(
			'kind'         => 'formula',
			'formula_name' => $formula->name,
			'variables'    => $rows,
			'unit'         => Settings::store_unit(),
			'rate_time'    => $this->latest_rate_time( $formula ),
		);
	}

	/**
	 * Newest rate timestamp among the currencies a formula uses.
	 *
	 * @param \RateBridge\Formula\FormulaModel $formula Formula.
	 * @return int 0 when the formula uses no currency.
	 */
	private function latest_rate_time( \RateBridge\Formula\FormulaModel $formula ): int {
		$repository = new CurrencyRepository();
		$latest     = 0;

		foreach ( $formula->currency_codes() as $code ) {
			$currency = $repository->get_by_code( $code );

			if ( $currency instanceof Currency ) {
				$latest = max( $latest, $this->currency_rate_time( $currency ) );
			}
		}

		return $latest;
	}

	/**
	 * Post id that carries the "managed" flag for a purchase.
	 *
	 * @param int $product_id Product/variation id.
	 * @param int $parent_id  Parent id (0 for simple products).
	 * @return int 0 when the product is not managed.
	 */
	private function managed_post_id( int $product_id, int $parent_id ): int {
		if ( ProductData::is_enabled( $product_id ) ) {
			return $product_id;
		}

		if ( $parent_id > 0 && ProductData::is_enabled( $parent_id ) ) {
			return $parent_id;
		}

		return 0;
	}

	/**
	 * Timestamp of a currency's last successful rate update.
	 *
	 * @param Currency $currency Currency.
	 * @return int Unix timestamp (0 when unknown).
	 */
	private function currency_rate_time( Currency $currency ): int {
		if ( null === $currency->last_updated || '' === $currency->last_updated ) {
			return 0;
		}

		$timestamp = strtotime( $currency->last_updated . ' UTC' );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Write the snapshot to a line item, idempotently.
	 *
	 * Never overwrites meta that already exists: a retried webhook or a double
	 * hook firing must keep the values captured at checkout time.
	 *
	 * @param WC_Order_Item          $item       Order item.
	 * @param array<string, mixed>|null $snapshot Snapshot from snapshot().
	 * @return bool Whether anything was written.
	 */
	public function apply_snapshot( WC_Order_Item $item, ?array $snapshot ): bool {
		if ( null === $snapshot ) {
			return false;
		}

		// Already recorded? Never overwrite a checkout-time snapshot.
		if ( '' !== (string) $item->get_meta( self::META_CURRENCY, true )
			|| '' !== (string) $item->get_meta( self::META_FORMULA_NAME, true ) ) {
			return false;
		}

		// Formula snapshot: no currency/rate, only the formula + variable values.
		if ( 'formula' === ( $snapshot['kind'] ?? '' ) ) {
			$item->add_meta_data( self::META_MODE, ProductData::MODE_FORMULA, true );
			$item->add_meta_data( self::META_FORMULA_NAME, (string) $snapshot['formula_name'], true );
			$item->add_meta_data( self::META_FORMULA_VARS, (string) wp_json_encode( $snapshot['variables'] ), true );
			$item->add_meta_data( self::META_UNIT, (string) $snapshot['unit'], true );
			$item->add_meta_data( self::META_RATE_TIME, (int) $snapshot['rate_time'], true );

			return true;
		}

		$item->add_meta_data( self::META_CURRENCY, (string) $snapshot['code'], true );
		$item->add_meta_data( self::META_RATE, wc_format_decimal( (float) $snapshot['rate'], 10 ), true );
		$item->add_meta_data( self::META_BASE_PRICE, wc_format_decimal( (float) $snapshot['base_price'], 4 ), true );
		$item->add_meta_data( self::META_RATE_TIME, (int) $snapshot['rate_time'], true );
		$item->add_meta_data( self::META_UNIT, (string) $snapshot['unit'], true );
		$item->add_meta_data( self::META_IS_SALE, ! empty( $snapshot['is_sale'] ) ? 'yes' : 'no', true );

		return true;
	}

	/**
	 * Classic checkout: the line item is being created.
	 *
	 * WooCommerce already verified the checkout nonce at this point.
	 *
	 * @param WC_Order_Item_Product $item          Line item.
	 * @param string                $cart_item_key Cart key.
	 * @param array<string, mixed>  $values        Cart item values.
	 * @return void
	 */
	public function on_create_line_item( $item, $cart_item_key, $values ): void {
		unset( $cart_item_key );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$product = $item->get_product();

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// `$values['data']` is the cart's product object (already a variation).
		$line_price = isset( $values['line_total'] ) && (int) ( $values['quantity'] ?? 0 ) > 0
			? (float) $values['line_total'] / (int) $values['quantity']
			: null;

		$this->apply_snapshot( $item, $this->snapshot( $product, $line_price ) );
	}

	/**
	 * Items created straight through CRUD (admin order editor).
	 *
	 * @param int        $item_id Item id.
	 * @param WC_Order_Item $item Order item.
	 * @return void
	 */
	public function on_new_order_item( $item_id, $item ): void {
		unset( $item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		if ( '' !== (string) $item->get_meta( self::META_CURRENCY, true ) ) {
			return;
		}

		$product = $item->get_product();

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$quantity = max( 1, (int) $item->get_quantity() );
		$charged  = $quantity > 0 ? (float) $item->get_total() / $quantity : null;

		if ( $this->apply_snapshot( $item, $this->snapshot( $product, $charged ) ) ) {
			$item->save();
		}
	}

	/**
	 * Block checkout: backfill any item that missed the classic hook.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function on_store_api_order_processed( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->backfill_order( $order );
		}
	}

	/**
	 * Classic checkout finished: backfill as a safety net.
	 *
	 * Covers plugins that build items without firing the create hook.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_classic_order_processed( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof WC_Order ) {
			$this->backfill_order( $order );
		}
	}

	/**
	 * Fill in missing snapshots for every product line of an order.
	 *
	 * Idempotent: existing `_rbw_*` meta is never touched, so a repeated call (or
	 * a webhook retry) is a no-op.
	 *
	 * @param WC_Order $order Order.
	 * @return int Number of items backfilled.
	 */
	public function backfill_order( WC_Order $order ): int {
		$filled = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( '' !== (string) $item->get_meta( self::META_CURRENCY, true ) ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );
			$charged  = $quantity > 0 ? (float) $item->get_total() / $quantity : null;

			if ( $this->apply_snapshot( $item, $this->snapshot( $product, $charged ) ) ) {
				$item->save();
				++$filled;
			}
		}

		return $filled;
	}

	/**
	 * Enqueue the rate-line stylesheet on order screens.
	 *
	 * The admin order editor renders the line through
	 * `woocommerce_after_order_itemmeta`, where the frontend shortcode styles are
	 * not loaded.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ): void {
		$hook = (string) $hook_suffix;

		// HPOS order screen + legacy post editor screens.
		$is_order_screen = in_array( $hook, array( 'woocommerce_page_wc-orders', 'post.php', 'post-new.php' ), true );

		if ( ! $is_order_screen ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen && ! in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) && 'woocommerce_page_wc-orders' !== $hook ) {
			return;
		}

		\RateBridge\Frontend\Frontend::enqueue_styles();
	}

	/**
	 * Enqueue the rate-line stylesheet on order-received / My Account pages.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! self::show_to_customer() ) {
			return;
		}

		$is_order_page = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$is_account    = function_exists( 'is_account_page' ) && is_account_page();
		$is_view_order = function_exists( 'is_view_order_page' ) && is_view_order_page();

		if ( $is_order_page || $is_account || $is_view_order ) {
			\RateBridge\Frontend\Frontend::enqueue_styles();
		}
	}

	/**
	 * Whether the admin chose to show the rate to customers.
	 *
	 * @return bool
	 */
	public static function show_to_customer(): bool {
		return (bool) Settings::get( 'show_rate_in_orders', false );
	}

	/**
	 * Read the snapshot back from an order item.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return array<string, mixed>|null Null when the item has no snapshot.
	 */
	public function read_snapshot( WC_Order_Item $item ): ?array {
		$formula_name = (string) $item->get_meta( self::META_FORMULA_NAME, true );

		if ( '' !== $formula_name ) {
			$decoded  = json_decode( (string) $item->get_meta( self::META_FORMULA_VARS, true ), true );
			$rows     = array();

			foreach ( is_array( $decoded ) ? $decoded : array() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['name'] ) ) {
					continue;
				}

				$rows[] = array(
					'name'  => (string) $row['name'],
					'label' => (string) ( $row['label'] ?? $row['name'] ),
					'value' => (float) ( $row['value'] ?? 0 ),
				);
			}

			return array(
				'kind'         => 'formula',
				'formula_name' => $formula_name,
				'variables'    => $rows,
				'rate_time'    => (int) $item->get_meta( self::META_RATE_TIME, true ),
				'unit'         => (string) $item->get_meta( self::META_UNIT, true ),
			);
		}

		$code = (string) $item->get_meta( self::META_CURRENCY, true );

		if ( '' === $code ) {
			return null;
		}

		return array(
			'code'        => $code,
			'rate'        => (float) $item->get_meta( self::META_RATE, true ),
			'base_price'  => (float) $item->get_meta( self::META_BASE_PRICE, true ),
			'rate_time'   => (int) $item->get_meta( self::META_RATE_TIME, true ),
			'unit'        => (string) $item->get_meta( self::META_UNIT, true ),
			'is_sale'     => 'yes' === (string) $item->get_meta( self::META_IS_SALE, true ),
		);
	}

	/**
	 * Persian read-only line for the admin order screen.
	 *
	 * @param int              $item_id Item id.
	 * @param WC_Order_Item    $item    Order item.
	 * @param WC_Product|null  $product Product (third argument of the hook).
	 * @return void
	 */
	public function render_admin_item_meta( $item_id, $item, $product ): void {
		unset( $item_id, $product );

		if ( ! $item instanceof WC_Order_Item ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$snapshot = $this->read_snapshot( $item );

		if ( null === $snapshot ) {
			return;
		}

		printf(
			'<div class="rbw-order-item-meta">%s</div>',
			wp_kses_post( $this->format_line( $snapshot ) )
		);
	}

	/**
	 * Customer-facing line (order received, emails, My Account).
	 *
	 * Renders nothing unless the «نمایش نرخ ارز به مشتری» setting is on.
	 *
	 * @param int           $item_id Item id.
	 * @param WC_Order_Item $item    Order item.
	 * @param WC_Order      $order   Order.
	 * @param bool          $plain   Whether plain text is requested.
	 * @return void
	 */
	public function render_customer_item_meta( $item_id, $item, $order, $plain = false ): void {
		unset( $item_id, $order );

		if ( ! self::show_to_customer() || ! $item instanceof WC_Order_Item ) {
			return;
		}

		$snapshot = $this->read_snapshot( $item );

		if ( null === $snapshot ) {
			return;
		}

		$line = $this->format_line( $snapshot );

		if ( $plain ) {
			echo esc_html( wp_strip_all_tags( $line ) );
			return;
		}

		printf( '<div class="rbw-order-item-meta">%s</div>', wp_kses_post( $line ) );
	}

	/**
	 * Build the localised line, e.g.
	 * «خریداری شده با دلار آمریکا با نرخ 272,400 تومان (قیمت پایه: 510)».
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return string
	 */
	public function format_line( array $snapshot ): string {
		if ( 'formula' === ( $snapshot['kind'] ?? '' ) ) {
			return $this->format_formula_line( $snapshot );
		}

		$currency = ( new CurrencyRepository() )->get_by_code( (string) $snapshot['code'] );

		$name = $currency instanceof Currency && '' !== $currency->name ? $currency->name : (string) $snapshot['code'];

		$unit = \RateBridge\Frontend\RateFormatter::unit_label( (string) $snapshot['unit'] );

		$line = sprintf(
			/* translators: 1: currency name, 2: rate, 3: unit label, 4: base price */
			__( 'Purchased with %1$s at a rate of %2$s %3$s (base price: %4$s)', 'ratebridge-woo' ),
			$name,
			\RateBridge\Frontend\RateFormatter::number( (float) $snapshot['rate'], self::rate_decimals( (float) $snapshot['rate'] ) ),
			$unit,
			\RateBridge\Frontend\RateFormatter::number( (float) $snapshot['base_price'], self::base_decimals( (float) $snapshot['base_price'] ) )
		);

		if ( ! empty( $snapshot['rate_time'] ) ) {
			$line .= ' — ' . sprintf(
				/* translators: %s: formatted date/time */
				__( 'rate updated: %s', 'ratebridge-woo' ),
				wp_date( 'Y-m-d H:i', (int) $snapshot['rate_time'] )
			);
		}

		return $line;
	}

	/**
	 * Decimals to show for a base price: whole numbers stay whole (510, not
	 * 510.0000), fractional quantities such as 4.75 g of gold keep 4 digits.
	 *
	 * @param float $base Base price.
	 * @return int
	 */
	private static function base_decimals( float $base ): int {
		if ( abs( $base - round( $base ) ) < 0.0000001 ) {
			return 0;
		}

		return 4;
	}

	/**
	 * Localised line for a formula-priced item.
	 *
	 * «محاسبه با فرمول قیمت طلا — weight: 8، gold18: 26,051,530 …»
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return string
	 */
	public function format_formula_line( array $snapshot ): string {
		$line = sprintf(
			/* translators: %s: formula name */
			__( 'Calculated with the formula “%s”', 'ratebridge-woo' ),
			(string) $snapshot['formula_name']
		);

		$pairs = array();

		foreach ( (array) ( $snapshot['variables'] ?? array() ) as $row ) {
			$value = (float) $row['value'];
			$text  = \RateBridge\Frontend\RateFormatter::number(
				$value,
				abs( $value - round( $value ) ) < 0.000001 ? 0 : 2
			);

			$pairs[] = sprintf( '%s: %s', (string) $row['label'], $text );
		}

		if ( ! empty( $pairs ) ) {
			$line .= ' — ' . implode( ' · ', array_slice( $pairs, 0, 8 ) );
		}

		if ( ! empty( $snapshot['rate_time'] ) ) {
			$line .= ' — ' . sprintf(
				/* translators: %s: formatted date/time */
				__( 'rate updated: %s', 'ratebridge-woo' ),
				wp_date( 'Y-m-d H:i', (int) $snapshot['rate_time'] )
			);
		}

		return $line;
	}

	/**
	 * Decimals to show for a rate (small rates need more digits).
	 *
	 * @param float $rate Rate.
	 * @return int
	 */
	private static function rate_decimals( float $rate ): int {
		return $rate >= 1000 ? 0 : 4;
	}

	/**
	 * Keep the internal meta out of the customer-facing formatted meta list.
	 *
	 * The keys already start with `_` (hidden in core), but some themes render
	 * every item meta regardless, so they are filtered explicitly.
	 *
	 * @param array<int, object> $formatted Formatted meta data.
	 * @param WC_Order_Item      $item      Order item.
	 * @return array<int, object>
	 */
	public function hide_internal_meta( $formatted, $item ) {
		unset( $item );

		if ( ! is_array( $formatted ) ) {
			return $formatted;
		}

		$hidden = self::meta_keys();

		return array_values(
			array_filter(
				$formatted,
				static fn ( $meta ): bool => ! ( is_object( $meta ) && isset( $meta->key ) && in_array( (string) $meta->key, $hidden, true ) )
			)
		);
	}
}
