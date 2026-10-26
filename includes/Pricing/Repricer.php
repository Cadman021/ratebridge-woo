<?php
/**
 * Product repricing: keep WooCommerce prices in sync with the stored rates.
 *
 * @package RateBridge
 */

namespace RateBridge\Pricing;

use RateBridge\Core\Logger;
use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Product\ProductData;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Recalculates regular/sale prices for products managed by the plugin.
 */
final class Repricer {

	/**
	 * Re-entrancy depth counter.
	 *
	 * Incremented for the whole repricing pass: every `save()` (including the
	 * parent sync of a variable product) fires `woocommerce_update_product`,
	 * whose listener must not start another pass.
	 *
	 * @var int
	 */
	private static int $saving = 0;

	/**
	 * Repository used to resolve currencies.
	 *
	 * @var CurrencyRepository
	 */
	private CurrencyRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRepository|null $repository Optional repository.
	 */
	public function __construct( ?CurrencyRepository $repository = null ) {
		$this->repository = $repository ?? new CurrencyRepository();
	}

	/**
	 * Whether a product save is currently being performed by this class.
	 *
	 * @return bool
	 */
	public static function is_saving(): bool {
		return self::$saving > 0;
	}

	/**
	 * Recalculate and save the prices of one product (and its variations).
	 *
	 * The result array always has this shape:
	 *
	 *     array(
	 *         'status'     => 'repriced'|'skipped'|'unchanged'|'failed',
	 *         'reason'     => machine code (empty on success),
	 *         'message'    => translated human message,
	 *         'price'      => regular price written (or null),
	 *         'sale_price' => sale price written (or null),
	 *         'breakdown'  => calculator breakdown,
	 *         'variations' => per-variation results,
	 *     )
	 *
	 * @param WC_Product      $product  Product to reprice.
	 * @param Currency|null   $currency Pre-resolved currency (optional).
	 * @return array<string, mixed>
	 */
	public function reprice_product( WC_Product $product, ?Currency $currency = null ): array {
		/*
		 * The guard covers the WHOLE pass, not just each save(): a variable
		 * product calls WC_Product_Variable::sync(), which saves the parent and
		 * would otherwise re-enter this method through
		 * `woocommerce_update_product` -> recursion.
		 */
		self::$saving++;

		try {
			return $this->do_reprice_product( $product, $currency );
		} finally {
			self::$saving--;
		}
	}

	/**
	 * Actual repricing body (see reprice_product()).
	 *
	 * @param WC_Product    $product  Product.
	 * @param Currency|null $currency Pre-resolved currency.
	 * @return array<string, mixed>
	 */
	private function do_reprice_product( WC_Product $product, ?Currency $currency ): array {
		$product_id = (int) $product->get_id();

		if ( $product->is_type( 'variation' ) ) {
			return $this->result( 'skipped', 'not_a_parent', __( 'Variations are repriced through their parent product.', 'ratebridge-woo' ) );
		}

		if ( ! ProductData::is_enabled( $product_id ) ) {
			return $this->result( 'skipped', 'not_managed', __( 'This product is not priced by a currency.', 'ratebridge-woo' ) );
		}

		$code     = ProductData::currency_code( $product_id );
		$currency = $currency ?? ( '' !== $code ? $this->repository->get_by_code( $code ) : null );

		$skip = $this->currency_skip_reason( $currency );

		if ( '' !== $skip['reason'] ) {
			return $this->result( 'skipped', $skip['reason'], $skip['message'] );
		}

		/** @var Currency $currency */
		if ( $product->is_type( 'variable' ) ) {
			return $this->reprice_variable( $product, $currency );
		}

		return $this->reprice_simple( $product, $currency, null );
	}

	/**
	 * Check the currency for repricing blockers (disabled, no rate, stale).
	 *
	 * @param Currency|null $currency Currency or null.
	 * @return array{reason:string,message:string}
	 */
	public function currency_skip_reason( ?Currency $currency ): array {
		if ( ! $currency instanceof Currency ) {
			return array(
				'reason'  => 'currency_missing',
				'message' => __( 'The currency of this product no longer exists.', 'ratebridge-woo' ),
			);
		}

		if ( ! $currency->is_enabled ) {
			return array(
				'reason'  => 'currency_disabled',
				'message' => __( 'The currency is disabled.', 'ratebridge-woo' ),
			);
		}

		if ( ! $currency->has_rate() ) {
			return array(
				'reason'  => 'no_rate',
				'message' => __( 'The currency has no rate yet.', 'ratebridge-woo' ),
			);
		}

		if ( $this->is_stale( $currency ) ) {
			return array(
				'reason'  => 'stale_rate',
				'message' => __( 'The exchange rate is older than the configured maximum age.', 'ratebridge-woo' ),
			);
		}

		return array(
			'reason'  => '',
			'message' => '',
		);
	}

	/**
	 * Whether a currency rate is older than the configured maximum age.
	 *
	 * Manual-provider currencies are never considered stale unless the admin
	 * explicitly set a maximum age (that is: the setting applies to everyone but
	 * manual rows have no automatic refresh, so the notice wording differs).
	 *
	 * @param Currency $currency Currency.
	 * @return bool
	 */
	public function is_stale( Currency $currency ): bool {
		$max_hours = Settings::max_rate_age_hours();

		if ( $max_hours <= 0 ) {
			return false;
		}

		if ( '' === (string) $currency->last_updated ) {
			// A manual rate typed by the admin has no timestamp: trust it.
			return false;
		}

		$timestamp = strtotime( $currency->last_updated . ' UTC' );

		if ( false === $timestamp ) {
			return false;
		}

		return ( time() - $timestamp ) > $max_hours * HOUR_IN_SECONDS;
	}

	/**
	 * Reprice a simple product.
	 *
	 * @param WC_Product    $product  Product.
	 * @param Currency      $currency Currency.
	 * @param int|null      $parent_id Unused for simple products.
	 * @return array<string, mixed>
	 */
	private function reprice_simple( WC_Product $product, Currency $currency, ?int $parent_id = null ): array {
		unset( $parent_id );

		$product_id = (int) $product->get_id();
		$data       = ProductData::read( $product_id );

		if ( $data['base_price'] <= 0 ) {
			return $this->result( 'skipped', 'empty_base_price', __( 'The base price is empty, so the price was left unchanged.', 'ratebridge-woo' ) );
		}

		$previous = $this->previous_price( $product_id, $product );
		$base      = $data['base_price'] * $currency->rate;
		$rules     = ProductData::resolve_rules( $product_id, $currency, $base );
		$settings  = ProductData::calculator_settings( $product_id, $rules['profit'], $rules['commission'], $rules['extra'], $previous );
		$result    = PriceCalculator::calculate( $data['base_price'], $currency->rate, $settings );

		if ( ! $result['ok'] ) {
			return $this->result(
				'failed',
				(string) $result['error'],
				$this->error_message( (string) $result['error'] ),
				null,
				null,
				is_array( $result['breakdown'] ) ? $result['breakdown'] : array()
			);
		}

		$regular = $this->filter_price( (float) $result['price'], $product_id, $data['base_price'], $currency, $settings );

		if ( $regular <= 0 ) {
			return $this->result(
				'failed',
				PriceCalculator::ERROR_NON_POSITIVE_RESULT,
				$this->error_message( PriceCalculator::ERROR_NON_POSITIVE_RESULT ),
				null,
				null,
				is_array( $result['breakdown'] ) ? $result['breakdown'] : array()
			);
		}

		$result['price'] = $regular;
		$result['breakdown']['final'] = $regular;
		$sale    = null;

		if ( $data['sale_price'] > 0 ) {
			$sale_base     = $data['sale_price'] * $currency->rate;
			$sale_rules    = ProductData::resolve_rules( $product_id, $currency, $sale_base );
			$sale_settings = ProductData::calculator_settings(
				$product_id,
				$sale_rules['profit'],
				$sale_rules['commission'],
				$sale_rules['extra'],
				0.0
			);

			// Never write a sale price above the regular one.
			$sale_settings['min_price']  = 0.0;
			$sale_settings['fluctuation'] = 0.0;

			$sale_result = PriceCalculator::calculate( $data['sale_price'], $currency->rate, $sale_settings );

			if ( $sale_result['ok'] && $sale_result['price'] > 0 && $sale_result['price'] < $regular ) {
				$sale = (float) $sale_result['price'];
			}
		}

		return $this->apply_to_product(
			$product,
			$regular,
			$sale,
			$currency,
			$result,
			(bool) $result['rules']['fluctuation_kept_previous']
		);
	}

	/**
	 * Reprice a variable product: parent settings, one calculation per variation.
	 *
	 * @param WC_Product     $product  Variable product.
	 * @param Currency       $currency Parent currency.
	 * @return array<string, mixed>
	 */
	private function reprice_variable( WC_Product $product, Currency $currency ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return $this->result( 'failed', 'not_variable', __( 'The product could not be loaded as a variable product.', 'ratebridge-woo' ) );
		}

		$parent_id  = (int) $product->get_id();
		$variations = $this->get_children( $product );
		$summary    = array(
			'repriced' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);
		$details    = array();

		foreach ( $variations as $variation ) {
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$variation_id = (int) $variation->get_id();
			$var_code     = ProductData::currency_code( $variation_id );
			$var_currency = $currency;

			// A variation may override the parent currency.
			if ( '' !== $var_code && $var_code !== $currency->code ) {
				$override = $this->repository->get_by_code( $var_code );

				if ( $override instanceof Currency ) {
					$var_currency = $override;
				}
			}

			$skip = $this->currency_skip_reason( $var_currency );

			if ( '' !== $skip['reason'] ) {
				++$summary['skipped'];
				$details[ $variation_id ] = $this->result( 'skipped', $skip['reason'], $skip['message'] );
				continue;
			}

			$base_price = ProductData::base_price( $variation_id );

			if ( $base_price <= 0 ) {
				/*
				 * Inherit the parent's base price only when the parent really
				 * acts as a template: a variable product keeps its base price on
				 * the parent, so every variation without its own value is meant
				 * to share it.
				 */
				$base_price = ProductData::base_price( $parent_id );
			}

			if ( $base_price <= 0 ) {
				++$summary['skipped'];
				$details[ $variation_id ] = $this->result( 'skipped', 'empty_base_price', __( 'The base price is empty, so the price was left unchanged.', 'ratebridge-woo' ) );
				continue;
			}

			$previous = $this->previous_price( $variation_id, $variation );
			$base     = $base_price * $var_currency->rate;

			/*
			 * Variation settings inherit the parent's mode/min/fluctuation (they
			 * live on the parent) and may add their own override values; the
			 * variation meta falls back to the parent when empty.
			 */
			$settings_source = $this->inherited_settings_id( $variation_id, $parent_id );
			$rules           = ProductData::resolve_rules( $settings_source, $var_currency, $base );
			$settings        = ProductData::calculator_settings( $settings_source, $rules['profit'], $rules['commission'], $rules['extra'], $previous );
			$result          = PriceCalculator::calculate( $base_price, $var_currency->rate, $settings );

			if ( ! $result['ok'] ) {
				++$summary['failed'];
				$details[ $variation_id ] = $this->result(
					'failed',
					(string) $result['error'],
					$this->error_message( (string) $result['error'] ),
					null,
					null,
					is_array( $result['breakdown'] ) ? $result['breakdown'] : array()
				);
				continue;
			}

			$regular = $this->filter_price( (float) $result['price'], $variation_id, $base_price, $var_currency, $settings );

			if ( $regular <= 0 ) {
				++$summary['failed'];
				$details[ $variation_id ] = $this->result(
					'failed',
					PriceCalculator::ERROR_NON_POSITIVE_RESULT,
					$this->error_message( PriceCalculator::ERROR_NON_POSITIVE_RESULT )
				);
				continue;
			}

			$result['price'] = $regular;
			$result['breakdown']['final'] = $regular;

			$sale    = null;

			/*
			 * Only the variation's own sale base price counts. Falling back to
			 * the parent's value is wrong (the parent's base price is not the
			 * variation's) and used to write a sale price orders of magnitude
			 * below the regular one.
			 */
			$sale_base_price = ProductData::sale_base_price( $variation_id );

			if ( $sale_base_price > 0 ) {
				$sale_base     = $sale_base_price * $var_currency->rate;
				$sale_rules    = ProductData::resolve_rules( $settings_source, $var_currency, $sale_base );
				$sale_settings = ProductData::calculator_settings( $settings_source, $sale_rules['profit'], $sale_rules['commission'], $sale_rules['extra'], 0.0 );
				$sale_settings['min_price']   = 0.0;
				$sale_settings['fluctuation'] = 0.0;
				$sale_result   = PriceCalculator::calculate( $sale_base_price, $var_currency->rate, $sale_settings );

				if ( $sale_result['ok'] ? ( $sale_result['price'] > 0 && $sale_result['price'] < $regular ) : false ) {
					$sale = (float) $sale_result['price'];
				}
			}

			$applied = $this->apply_to_product(
				$variation,
				$regular,
				$sale,
				$var_currency,
				$result,
				(bool) $result['rules']['fluctuation_kept_previous']
			);

			$details[ $variation_id ] = $applied;

			if ( 'unchanged' === $applied['status'] ) {
				++$summary['skipped'];
			} elseif ( 'failed' === $applied['status'] ) {
				++$summary['failed'];
			} else {
				++$summary['repriced'];
			}
		}

		/*
		 * A variable product has no price of its own: its range comes from the
		 * variations. A leftover regular/sale price on the parent (e.g. from
		 * when the product was still simple) pollutes the range and the
		 * "starting from" label, so it is cleared here.
		 */
		$parent_regular = (string) $product->get_regular_price( 'edit' );
		$parent_sale    = (string) $product->get_sale_price( 'edit' );

		if ( '' !== $parent_regular || '' !== $parent_sale ) {
			$product->set_regular_price( '' );
			$product->set_sale_price( '' );
			$product->save();
		}

		/*
		 * Refresh the parent's price range and caches after the variations were
		 * saved through CRUD. WC_Product_Variable::sync() is the documented
		 * (and existing) API; it recomputes _min_variation_price,
		 * _max_variation_price and the `_price` meta of the parent.
		 */
		WC_Product_Variable::sync( $product, true );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $parent_id );
		}

		$status = 'repriced';

		if ( 0 === $summary['repriced'] ) {
			$status = $summary['failed'] > 0 ? 'failed' : 'skipped';
		}

		return $this->result(
			$status,
			0 === $summary['repriced'] && $summary['failed'] > 0 ? 'variation_failed' : '',
			$this->summarise_variations( $summary ),
			null,
			null,
			array(),
			$details
		);
	}

	/**
	 * The price used as the fluctuation baseline.
	 *
	 * The plugin's own `_rbw_last_price` wins, because that is the number the
	 * fluctuation rule is supposed to protect: an edit made in quick edit (or
	 * any other manual price write) must not silently become the new baseline.
	 * When the plugin has never written a price, fall back to the product's
	 * current regular price so the very first calculation can already respect
	 * the margin.
	 *
	 * @param int        $post_id Product or variation id.
	 * @param WC_Product $product Product object.
	 * @return float
	 */
	private function previous_price( int $post_id, WC_Product $product ): float {
		$stored = ProductData::to_decimal( get_post_meta( $post_id, ProductData::META_LAST_PRICE, true ) );

		if ( $stored > 0 ) {
			return $stored;
		}

		$current = $product->get_regular_price( 'edit' );

		return is_numeric( $current ) ? max( 0.0, (float) $current ) : 0.0;
	}

	/**
	 * Post id whose settings should be used: the variation if it overrides
	 * anything, otherwise the parent.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $parent_id    Parent id.
	 * @return int
	 */
	private function inherited_settings_id( int $variation_id, int $parent_id ): int {
		$has_own = (float) get_post_meta( $variation_id, ProductData::META_PROFIT_VALUE, true ) > 0
			|| (float) get_post_meta( $variation_id, ProductData::META_COMMISSION_VALUE, true ) > 0
			|| (float) get_post_meta( $variation_id, ProductData::META_EXTRA_AMOUNT, true ) > 0;

		return $has_own ? $variation_id : $parent_id;
	}

	/**
	 * Variations of a variable product, loaded through CRUD.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @return WC_Product_Variation[]
	 */
	private function get_children( WC_Product_Variable $product ): array {
		$ids = $product->get_children();

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$out = array();

		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );

			if ( $variation instanceof WC_Product_Variation ) {
				$out[] = $variation;
			}
		}

		return $out;
	}

	/**
	 * Write the calculated prices to a product through the CRUD API.
	 *
	 * @param WC_Product          $product   Product or variation.
	 * @param float               $regular   Regular price.
	 * @param float|null          $sale      Sale price (null = clear).
	 * @param Currency            $currency  Currency used.
	 * @param array<string,mixed> $result    Calculator result.
	 * @param bool                $kept_previous Whether the fluctuation rule kept the old price.
	 * @return array<string, mixed>
	 */
	private function apply_to_product( WC_Product $product, float $regular, ?float $sale, Currency $currency, array $result, bool $kept_previous ): array {
		$id = (int) $product->get_id();

		// Never write a price lower than the minimum even if a rule changed it.
		$min_price = ProductData::to_decimal( get_post_meta( $id, ProductData::META_MIN_PRICE, true ) );

		if ( $min_price > 0 && $regular < $min_price ) {
			$regular = $min_price;
		}

		$current_regular = '' === $product->get_regular_price( 'edit' ) ? null : (float) $product->get_regular_price( 'edit' );
		$current_sale    = '' === (string) $product->get_sale_price( 'edit' ) ? null : (float) $product->get_sale_price( 'edit' );

		$regular_price = wc_format_decimal( $regular, wc_get_price_decimals() );
		$sale_price    = null === $sale ? '' : wc_format_decimal( $sale, wc_get_price_decimals() );

		$regular_unchanged = null !== $current_regular && abs( $current_regular - (float) $regular_price ) < 0.000001;
		$sale_unchanged    = ( null === $current_sale && '' === $sale_price )
			|| ( null !== $current_sale && '' !== $sale_price && abs( $current_sale - (float) $sale_price ) < 0.000001 );

		if ( $regular_unchanged && $sale_unchanged ) {
			$this->write_audit_meta( $id, (float) $regular_price, $currency );

			return $this->result(
				'unchanged',
				$kept_previous ? 'fluctuation' : 'no_change',
				$kept_previous
					? __( 'Change is inside the fluctuation margin, the price was kept.', 'ratebridge-woo' )
					: __( 'The price is already up to date.', 'ratebridge-woo' ),
				(float) $regular_price,
				null === $sale ? null : (float) $sale,
				is_array( $result['breakdown'] ?? null ) ? $result['breakdown'] : array()
			);
		}

		$product->set_regular_price( (string) $regular_price );
		$product->set_sale_price( $sale_price );

		// `set_price` mirrors what the editor would do, so the synced `_price`
		// meta stays consistent for sorting and the price range display.
		$product->set_price( (string) ( '' !== $sale_price ? $sale_price : $regular_price ) );
		$product->save();

		$this->write_audit_meta( $id, (float) $regular_price, $currency );

		return $this->result(
			'repriced',
			'',
			__( 'The price was recalculated.', 'ratebridge-woo' ),
			(float) $regular_price,
			null === $sale ? null : (float) $sale,
			is_array( $result['breakdown'] ?? null ) ? $result['breakdown'] : array()
		);
	}

	/**
	 * Run a calculated price through the `rbw_calculated_price` filter.
	 *
	 * The preview endpoint applies the same filter, so the number the admin
	 * sees is the number that gets stored.
	 *
	 * @param float                $price     Calculated price.
	 * @param int                  $post_id   Product or variation id.
	 * @param float                $base      Base price in the foreign unit.
	 * @param Currency             $currency  Currency used.
	 * @param array<string, mixed> $settings  Calculator settings.
	 * @return float Filtered price (0 or less when the filter rejected it).
	 */
	private function filter_price( float $price, int $post_id, float $base, Currency $currency, array $settings ): float {
		/** This filter is documented in includes/Admin/AjaxController.php */
		$filtered = (float) apply_filters( 'rbw_calculated_price', $price, $base, $currency, $post_id, $settings );

		return is_finite( $filtered ) ? $filtered : 0.0;
	}

	/**
	 * Store the audit meta used by the products column and the fluctuation rule.
	 *
	 * @param int      $post_id  Product id.
	 * @param float    $price    Price written.
	 * @param Currency $currency Currency used.
	 * @return void
	 */
	private function write_audit_meta( int $post_id, float $price, Currency $currency ): void {
		update_post_meta( $post_id, ProductData::META_LAST_PRICE, wc_format_decimal( $price, wc_get_price_decimals() ) );
		update_post_meta( $post_id, ProductData::META_LAST_CALC, time() );
		update_post_meta( $post_id, ProductData::META_LAST_RATE, $currency->rate );
	}

	/**
	 * Reprice every managed product of one currency in a single batch.
	 *
	 * @param string $currency_code Currency code.
	 * @param int    $page          1-based page number.
	 * @param int    $per_page      Batch size.
	 * @param bool   $dry_run       Only count (used by the unit migration preview).
	 * @return array<string, mixed> {
	 *     @type int  $counts   Number of products examined.
	 *     @type int  $pages    Total pages.
	 *     @type array $summary repriced|skipped|unchanged|failed counters.
	 * }
	 */
	public function reprice_batch( string $currency_code, int $page = 1, int $per_page = 50, bool $dry_run = false ): array {
		$currency = $this->repository->get_by_code( strtoupper( $currency_code ) );
		$args     = self::managed_query_args( $currency_code, $page, $per_page );
		$products = wc_get_products( $args );

		$summary = array(
			'repriced'  => 0,
			'skipped'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);

		if ( $dry_run ) {
			return array(
				'counts'  => count( $products ),
				'pages'   => 1,
				'summary' => $summary,
			);
		}

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$result = $this->reprice_product( $product, $currency );

			switch ( $result['status'] ) {
				case 'repriced':
					++$summary['repriced'];
					break;
				case 'failed':
					++$summary['failed'];
					break;
				case 'unchanged':
					++$summary['unchanged'];
					break;
				default:
					++$summary['skipped'];
					break;
			}
		}

		return array(
			'counts'  => count( $products ),
			'pages'   => 1,
			'summary' => $summary,
		);
	}

	/**
	 * wc_get_products() arguments for "managed products of this currency".
	 *
	 * Variable products are included when the parent uses the currency **or**
	 * when at least one variation overrides the currency, so a variation-only
	 * assignment is never missed.
	 *
	 * @param string $currency_code Currency code.
	 * @param int    $page          Page number.
	 * @param int    $per_page      Batch size.
	 * @return array<string, mixed>
	 */
	public static function managed_query_args( string $currency_code, int $page = 1, int $per_page = 50 ): array {
		$code = strtoupper( $currency_code );

		return array(
			'status'     => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'limit'      => $per_page,
			'page'       => max( 1, $page ),
			'paginate'   => false,
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'return'     => 'objects',
			'type'       => array( 'simple', 'variable' ),
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => ProductData::META_ENABLED,
					'value' => 'yes',
				),
				array(
					'key'     => ProductData::META_CURRENCY,
					'value'   => $code,
					'compare' => '=',
				),
			),
		);
	}

	/**
	 * Product IDs managed by a currency (used by the batch job).
	 *
	 * @param string $currency_code Currency code.
	 * @return int[]
	 */
	public function managed_product_ids( string $currency_code ): array {
		$args = self::managed_query_args( $currency_code, 1, -1 );
		$args['return'] = 'ids';
		$args['fields'] = 'ids';

		$ids = wc_get_products( $args );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Log a batch summary with the existing Logger.
	 *
	 * @param string              $currency_code Currency code.
	 * @param array<string, int>  $summary       Counters.
	 * @param string              $trigger       'async'|'unit'|'manual'.
	 * @return void
	 */
	public static function log_summary( string $currency_code, array $summary, string $trigger = 'async' ): void {
		$message = sprintf(
			/* translators: 1: currency code, 2: repriced count, 3: skipped count, 4: unchanged count, 5: failed count */
			__( '%1$s: repriced %2$d, skipped %3$d, unchanged-by-fluctuation %4$d, failed %5$d.', 'ratebridge-woo' ),
			$currency_code,
			(int) ( $summary['repriced'] ?? 0 ),
			(int) ( $summary['skipped'] ?? 0 ),
			(int) ( $summary['unchanged'] ?? 0 ),
			(int) ( $summary['failed'] ?? 0 )
		);

		Logger::add(
			array(
				'status'     => (int) ( $summary['failed'] ?? 0 ) > 0 ? 'error' : 'success',
				'message'    => $message,
				'currencies' => array( $currency_code ),
				'trigger'    => $trigger,
			)
		);
	}

	/**
	 * Build a result array.
	 *
	 * @param string              $status     repriced|skipped|unchanged|failed.
	 * @param string              $reason     Machine reason code.
	 * @param string              $message    Human message.
	 * @param float|null          $price      Regular price.
	 * @param float|null          $sale_price Sale price.
	 * @param array<string,mixed> $breakdown  Calculator breakdown.
	 * @param array<string,mixed> $variations Per-variation results.
	 * @return array<string, mixed>
	 */
	private function result(
		string $status,
		string $reason = '',
		string $message = '',
		?float $price = null,
		?float $sale_price = null,
		array $breakdown = array(),
		array $variations = array()
	): array {
		return array(
			'status'     => $status,
			'reason'     => $reason,
			'message'    => $message,
			'price'      => $price,
			'sale_price' => $sale_price,
			'breakdown'  => $breakdown,
			'variations' => $variations,
		);
	}

	/**
	 * Persian message for a calculator reason code.
	 *
	 * @param string $code Reason code.
	 * @return string
	 */
	private function error_message( string $code ): string {
		return match ( $code ) {
			PriceCalculator::ERROR_INVALID_RATE        => __( 'The currency rate is not valid.', 'ratebridge-woo' ),
			PriceCalculator::ERROR_INVALID_BASE        => __( 'The base price is not valid.', 'ratebridge-woo' ),
			PriceCalculator::ERROR_INVALID_SETTING     => __( 'A pricing setting (profit, commission or extra) is out of range.', 'ratebridge-woo' ),
			PriceCalculator::ERROR_NON_FINITE          => __( 'The calculation produced a non-finite number.', 'ratebridge-woo' ),
			PriceCalculator::ERROR_NON_POSITIVE_RESULT => __( 'The calculation produced a price of zero or less.', 'ratebridge-woo' ),
			default                                    => __( 'The price could not be calculated.', 'ratebridge-woo' ),
		};
	}

	/**
	 * Human summary for a variable product.
	 *
	 * @param array<string, int> $summary Counters.
	 * @return string
	 */
	private function summarise_variations( array $summary ): string {
		if ( 0 === $summary['repriced'] && 0 === $summary['skipped'] && 0 === $summary['failed'] ) {
			return __( 'This variable product has no variations.', 'ratebridge-woo' );
		}

		return sprintf(
			/* translators: 1: repriced variations, 2: skipped variations, 3: failed variations */
			__( 'Variations: repriced %1$d, skipped %2$d, failed %3$d.', 'ratebridge-woo' ),
			(int) $summary['repriced'],
			(int) $summary['skipped'],
			(int) $summary['failed']
		);
	}
}
