<?php
/**
 * Import/Export admin controller: downloads (admin-post) and the bulk-edit panel.
 *
 * AJAX endpoints live in `AjaxController` (single dispatcher for all admin-ajax
 * actions); this class owns the file downloads and the WooCommerce bulk-edit
 * integration.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

use RateBridge\Admin\AjaxController;
use RateBridge\Admin\SettingsPage;
use RateBridge\Core\Logger;
use RateBridge\Core\Settings;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Pricing\RepriceJob;
use RateBridge\Product\ProductData;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Wires downloads + bulk edit.
 */
final class AdminController {

	/**
	 * admin-post action: package download.
	 */
	public const ACTION_PACKAGE = 'rbw_export_package';

	/**
	 * admin-post action: products CSV download.
	 */
	public const ACTION_PRODUCTS = 'rbw_export_products';

	/**
	 * admin-post action: restore a bucket of backups.
	 */
	public const ACTION_RESTORE = 'rbw_restore_backup';

	/**
	 * Nonce action for the downloads.
	 */
	public const DOWNLOAD_NONCE = 'rbw_download';

	/**
	 * User meta: last bulk-edit result (consumed by the notice).
	 */
	public const BULK_NOTICE_META = 'rbw_bulk_edit_notice';

	/**
	 * Post ids touched during the current bulk request.
	 *
	 * @var int[]
	 */
	private array $bulk_touched = array();

	/**
	 * Whether the bulk shutdown hook is registered.
	 *
	 * @var bool
	 */
	private bool $bulk_shutdown_registered = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_PACKAGE, array( $this, 'download_package' ) );
		add_action( 'admin_post_' . self::ACTION_PRODUCTS, array( $this, 'download_products' ) );

		// Bulk edit (classic products list only).
		add_action( 'woocommerce_product_bulk_edit_start', array( $this, 'bulk_edit_fields' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_edit_save' ) );
		add_action( 'admin_notices', array( $this, 'render_bulk_notice' ) );

		// Temp file housekeeping.
		add_action( 'rbw_cleanup_tmp', array( $this, 'cleanup_tmp' ) );
	}

	/**
	 * Housekeeping callback.
	 *
	 * @return void
	 */
	public function cleanup_tmp(): void {
		( new ProductsCsvService() )->cleanup_tmp();
	}

	/* --------------------------------------------------------------------- */
	/* Downloads                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Download the JSON package.
	 *
	 * @return void
	 */
	public function download_package(): void {
		$this->verify_download_request( SettingsPage::CAPABILITY );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$sections = isset( $_GET['sections'] ) ? (array) wp_unslash( $_GET['sections'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$sections = array_values( array_intersect( Package::sections(), array_map( 'sanitize_key', array_map( 'strval', $sections ) ) ) );

		$json = ( new Exporter() )->to_json( $sections );
		$name = 'ratebridge-export-' . gmdate( 'Ymd-Hi' ) . '.json';

		Logger::add(
			array(
				'status'     => 'success',
				'message'    => sprintf(
					/* translators: %s: comma separated sections */
					__( 'Package exported (%s).', 'ratebridge-woo' ),
					implode( ', ', $sections )
				),
				'currencies' => array(),
				'trigger'    => 'export',
			)
		);

		$this->send_download( $name, 'application/json', $json );
	}

	/**
	 * Download the products CSV.
	 *
	 * @return void
	 */
	public function download_products(): void {
		$this->verify_download_request( SettingsPage::CAPABILITY );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ratebridge-woo' ), 403 );
		}

		( new ProductsCsvService() )->stream_export();
		exit;
	}

	/**
	 * Shared nonce + capability check for admin-post downloads.
	 *
	 * @param string $capability Required capability.
	 * @return void
	 */
	private function verify_download_request( string $capability ): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::DOWNLOAD_NONCE ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page.', 'ratebridge-woo' ), 403 );
		}

		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ratebridge-woo' ), 403 );
		}
	}

	/**
	 * Stream a download with the correct headers.
	 *
	 * @param string $filename File name.
	 * @param string $type     MIME type.
	 * @param string $body     Body.
	 * @return void
	 */
	private function send_download( string $filename, string $type, string $body ): void {
		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a download, not HTML.

		exit;
	}

	/**
	 * URL for the package download.
	 *
	 * @param string[] $sections Sections.
	 * @return string
	 */
	public static function package_url( array $sections = array() ): string {
		$args = array(
			'action'   => self::ACTION_PACKAGE,
			'_wpnonce' => wp_create_nonce( self::DOWNLOAD_NONCE ),
		);

		if ( ! empty( $sections ) ) {
			$args['sections'] = $sections;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * URL for the products CSV download.
	 *
	 * @return string
	 */
	public static function products_url(): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION_PRODUCTS,
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD_NONCE ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Bulk edit                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Render the RateBridge section of the bulk-edit panel.
	 *
	 * @return void
	 */
	public function bulk_edit_fields(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$currencies = array();

		foreach ( ( new \RateBridge\Currency\CurrencyRepository() )->get_all() as $currency ) {
			$currencies[ $currency->code ] = sprintf( '%s — %s', $currency->code, $currency->name );
		}

		$formulas = ( new FormulaRepository() )->choices();
		$unit     = SettingsPage::unit_label();

		echo '<div class="inline-edit-group rbw-bulk-group">';
		echo '<h4 class="rbw-bulk-title">' . esc_html__( 'RateBridge', 'ratebridge-woo' ) . '</h4>';
		echo '<p class="rbw-bulk-note">' . esc_html__( 'Only the fields you change are applied. Variation prices are never touched by bulk edit.', 'ratebridge-woo' ) . '</p>';

		// Pricing mode.
		$this->bulk_select(
			'rbw_bulk_mode',
			__( 'Pricing method', 'ratebridge-woo' ),
			array(
				''         => __( '— No change —', 'ratebridge-woo' ),
				'currency' => __( 'Currency × base price', 'ratebridge-woo' ),
				'formula'  => __( 'Formula', 'ratebridge-woo' ),
				'off'      => __( 'Turn currency pricing off', 'ratebridge-woo' ),
			)
		);

		// Currency.
		$this->bulk_select(
			'rbw_bulk_currency',
			__( 'Currency', 'ratebridge-woo' ),
			array( '' => __( '— No change —', 'ratebridge-woo' ) ) + $currencies
		);

		// Formula.
		$this->bulk_select(
			'rbw_bulk_formula',
			__( 'Formula', 'ratebridge-woo' ),
			array( '' => __( '— No change —', 'ratebridge-woo' ) ) + array_map( 'strval', $formulas )
		);

		// Base price: operation + value.
		$this->bulk_operation(
			'rbw_bulk_base_price',
			__( 'Base price', 'ratebridge-woo' ),
			__( 'Value', 'ratebridge-woo' )
		);

		// Min price.
		$this->bulk_select(
			'rbw_bulk_min_price_op',
			sprintf(
				/* translators: %s: unit */
				__( 'Minimum price (%s)', 'ratebridge-woo' ),
				$unit
			),
			array(
				''      => __( '— No change —', 'ratebridge-woo' ),
				'set'   => __( 'Set to', 'ratebridge-woo' ),
				'clear' => __( 'Clear', 'ratebridge-woo' ),
			)
		);
		echo '<input type="text" name="rbw_bulk_min_price" class="text rbw-bulk-number" dir="ltr" value="" />';

		// Fluctuation margin.
		$this->bulk_select(
			'rbw_bulk_fluctuation_op',
			sprintf(
				/* translators: %s: unit */
				__( 'Fluctuation margin (%s)', 'ratebridge-woo' ),
				$unit
			),
			array(
				''      => __( '— No change —', 'ratebridge-woo' ),
				'set'   => __( 'Set to', 'ratebridge-woo' ),
				'clear' => __( 'Clear', 'ratebridge-woo' ),
			)
		);
		echo '<input type="text" name="rbw_bulk_fluctuation" class="text rbw-bulk-number" dir="ltr" value="" />';

		// Profit / commission / extra.
		$this->bulk_select(
			'rbw_bulk_rule_mode',
			__( 'Profit/commission mode', 'ratebridge-woo' ),
			array(
				''        => __( '— No change —', 'ratebridge-woo' ),
				'none'    => __( 'Use the currency values', 'ratebridge-woo' ),
				'add'     => __( 'Add to the currency values', 'ratebridge-woo' ),
				'replace' => __( 'Replace the currency values', 'ratebridge-woo' ),
			)
		);

		foreach ( array(
			'profit'     => __( 'Profit', 'ratebridge-woo' ),
			'commission' => __( 'Commission', 'ratebridge-woo' ),
			'extra'      => __( 'Extra amount', 'ratebridge-woo' ),
		) as $slot => $label ) {
			$types = 'extra' === $slot
				? array( '' => __( '— No change —', 'ratebridge-woo' ), 'fixed' => __( 'Set (amount)', 'ratebridge-woo' ) )
				: array(
					''        => __( '— No change —', 'ratebridge-woo' ),
					'percent' => __( 'Percent', 'ratebridge-woo' ),
					'fixed'   => __( 'Fixed amount', 'ratebridge-woo' ),
				);

			echo '<div class="rbw-bulk-row">';
			printf( '<label class="rbw-bulk-label">%s</label>', esc_html( $label ) );
			printf( '<select name="rbw_bulk_%s_type">', esc_attr( $slot ) );

			foreach ( $types as $value => $text ) {
				printf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( $text ) );
			}

			echo '</select>';
			printf(
				'<input type="text" name="rbw_bulk_%s_value" class="text rbw-bulk-number" dir="ltr" value="" />',
				esc_attr( $slot )
			);
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render a labelled select for the bulk panel.
	 *
	 * @param string                $name    Field name.
	 * @param string                $label   Label.
	 * @param array<string, string> $options Options.
	 * @return void
	 */
	private function bulk_select( string $name, string $label, array $options ): void {
		echo '<div class="rbw-bulk-row">';
		printf( '<label class="rbw-bulk-label" for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $label ) );
		printf( '<select name="%s" id="%s">', esc_attr( $name ), esc_attr( $name ) );

		foreach ( $options as $value => $text ) {
			printf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( (string) $text ) );
		}

		echo '</select></div>';
	}

	/**
	 * Render the base-price operation selector + value input.
	 *
	 * @param string $name  Field name prefix.
	 * @param string $label Label.
	 * @param string $value_label Label of the value input.
	 * @return void
	 */
	private function bulk_operation( string $name, string $label, string $value_label ): void {
		echo '<div class="rbw-bulk-row">';
		printf( '<label class="rbw-bulk-label" for="%1$s_op">%2$s</label>', esc_attr( $name ), esc_html( $label ) );
		printf( '<select name="%s_op" id="%s_op">', esc_attr( $name ), esc_attr( $name ) );

		$operations = array(
			''                  => __( '— No change —', 'ratebridge-woo' ),
			BulkChange::OP_SET              => __( 'Set to', 'ratebridge-woo' ),
			BulkChange::OP_INCREASE_PERCENT => __( 'Increase by %', 'ratebridge-woo' ),
			BulkChange::OP_INCREASE_AMOUNT  => __( 'Increase by amount', 'ratebridge-woo' ),
			BulkChange::OP_DECREASE_PERCENT => __( 'Decrease by %', 'ratebridge-woo' ),
			BulkChange::OP_DECREASE_AMOUNT  => __( 'Decrease by amount', 'ratebridge-woo' ),
		);

		foreach ( $operations as $value => $text ) {
			printf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( $text ) );
		}

		echo '</select>';
		printf(
			'<input type="text" name="%s" class="text rbw-bulk-number" dir="ltr" value="" placeholder="%s" />',
			esc_attr( $name ),
			esc_attr( $value_label )
		);
		echo '</div>';
	}

	/**
	 * Apply the bulk edit to one product.
	 *
	 * WooCommerce fires this once per product inside the native bulk save. Costs
	 * are only computed here; repricing is queued once for the whole request.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public function bulk_edit_save( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the bulk nonce.
		$request = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_array( $request ) ) {
			return;
		}

		$product_id = (int) $product->get_id();
		$type       = $product->get_type();

		if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			$this->touched_note( $product_id, 'unsupported_type' );
			return;
		}

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			$this->touched_note( $product_id, 'forbidden' );
			return;
		}

		if ( ! ProductData::is_enabled( $product_id ) && '' === $this->str( $request, 'rbw_bulk_mode' ) ) {
			// Nothing to change on a product the plugin does not manage.
			return;
		}

		$changed = false;

		// 1. Mode.
		$mode = sanitize_key( $this->str( $request, 'rbw_bulk_mode' ) );

		if ( '' !== $mode ) {
			if ( 'off' === $mode ) {
				// Turn the plugin off but keep the WooCommerce price untouched.
				foreach ( ProductData::all_meta_keys() as $key ) {
					delete_post_meta( $product_id, $key );
				}

				$this->mark_touched( $product_id );
				$this->bulk_count( 'disabled' );
				return;
			}

			if ( 'currency' === $mode ) {
				$currency = strtoupper( sanitize_text_field( $this->str( $request, 'rbw_bulk_currency' ) ) );

				if ( '' === $currency && '' === ProductData::currency_code( $product_id ) ) {
					$this->touched_note( $product_id, 'currency_required' );
					return;
				}

				update_post_meta( $product_id, ProductData::META_ENABLED, 'yes' );
				update_post_meta( $product_id, ProductData::META_MODE, ProductData::MODE_CURRENCY );
				delete_post_meta( $product_id, ProductData::META_FORMULA_ID );
				delete_post_meta( $product_id, ProductData::META_FORMULA_VARS );

				if ( '' !== $currency ) {
					update_post_meta( $product_id, ProductData::META_CURRENCY, $currency );
				}

				$changed = true;
			}

			if ( 'formula' === $mode ) {
				$formula_id = absint( $this->str( $request, 'rbw_bulk_formula' ) );

				if ( 0 === $formula_id && 0 === ProductData::formula_id( $product_id ) ) {
					$this->touched_note( $product_id, 'formula_required' );
					return;
				}

				update_post_meta( $product_id, ProductData::META_ENABLED, 'yes' );
				update_post_meta( $product_id, ProductData::META_MODE, ProductData::MODE_FORMULA );

				if ( $formula_id > 0 ) {
					update_post_meta( $product_id, ProductData::META_FORMULA_ID, (string) $formula_id );
				}

				$changed = true;
			}
		}

		// 2. Currency switch without changing the mode.
		$currency_only = strtoupper( sanitize_text_field( $this->str( $request, 'rbw_bulk_currency' ) ) );

		if ( '' !== $currency_only && 'currency' !== $mode ) {
			update_post_meta( $product_id, ProductData::META_CURRENCY, $currency_only );
			$changed = true;
		}

		// 3. Base price (simple products only: a variable parent has no base price).
		$base_op    = BulkChange::operation( $this->str( $request, 'rbw_bulk_base_price_op' ) );
		$base_value = (float) $this->str( $request, 'rbw_bulk_base_price' );

		if ( BulkChange::OP_NONE !== $base_op && 'simple' === $type ) {
			$result = BulkChange::apply( ProductData::base_price( $product_id ), $base_op, $base_value );

			if ( $result['changed'] ) {
				if ( null === $result['value'] || $result['value'] <= 0 ) {
					delete_post_meta( $product_id, ProductData::META_BASE_PRICE );
				} else {
					update_post_meta( $product_id, ProductData::META_BASE_PRICE, wc_format_decimal( $result['value'], 4 ) );
				}

				$changed = true;
			}
		}

		// 4. Min price / fluctuation.
		foreach ( array(
			'min_price'   => ProductData::META_MIN_PRICE,
			'fluctuation' => ProductData::META_FLUCTUATION,
		) as $field => $meta_key ) {
			$op = BulkChange::operation( $this->str( $request, 'rbw_bulk_' . $field . '_op' ) );

			if ( BulkChange::OP_NONE === $op ) {
				continue;
			}

			$value = BulkChange::OP_CLEAR === $op ? null : (float) $this->str( $request, 'rbw_bulk_' . $field );

			if ( null === $value || $value <= 0 ) {
				delete_post_meta( $product_id, $meta_key );
			} else {
				update_post_meta( $product_id, $meta_key, wc_format_decimal( $value, 4 ) );
			}

			$changed = true;
		}

		// 5. Profit/commission/extra.
		$rule_mode = sanitize_key( $this->str( $request, 'rbw_bulk_rule_mode' ) );

		if ( in_array( $rule_mode, array( 'none', 'add', 'replace' ), true ) ) {
			update_post_meta( $product_id, ProductData::META_PROFIT_MODE, $rule_mode );
			$changed = true;
		}

		foreach ( array(
			'profit'     => array( ProductData::META_PROFIT_TYPE, ProductData::META_PROFIT_VALUE ),
			'commission' => array( ProductData::META_COMMISSION_TYPE, ProductData::META_COMMISSION_VALUE ),
			'extra'      => array( '', ProductData::META_EXTRA_AMOUNT ),
		) as $slot => $keys ) {
			$type  = sanitize_key( $this->str( $request, 'rbw_bulk_' . $slot . '_type' ) );
			$value = (float) $this->str( $request, 'rbw_bulk_' . $slot . '_value' );

			if ( '' === $type ) {
				continue;
			}

			if ( '' !== $keys[0] ) {
				update_post_meta( $product_id, $keys[0], 'fixed' === $type ? 'fixed' : 'percent' );
			}

			update_post_meta( $product_id, $keys[1], wc_format_decimal( max( 0.0, $value ), 4 ) );
			$changed = true;
		}

		if ( $changed ) {
			delete_post_meta( $product_id, ProductData::META_LAST_ERROR );
			$this->mark_touched( $product_id );
			$this->bulk_count( 'updated' );
		}
	}

	/**
	 * Record a product as touched and make sure the shutdown hook runs once.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function mark_touched( int $product_id ): void {
		$this->bulk_touched[] = $product_id;

		if ( $this->bulk_shutdown_registered ) {
			return;
		}

		$this->bulk_shutdown_registered = true;

		// ONE async job for the whole request, after WooCommerce finished saving.
		add_action(
			'shutdown',
			function (): void {
				$ids = array_values( array_unique( array_filter( $this->bulk_touched ) ) );

				if ( empty( $ids ) ) {
					return;
				}

				( new RepriceJob() )->enqueue_ids( $ids );

				update_user_meta(
					get_current_user_id(),
					self::BULK_NOTICE_META,
					array(
						'updated' => (int) ( $this->bulk_counts['updated'] ?? 0 ),
						'disabled' => (int) ( $this->bulk_counts['disabled'] ?? 0 ),
						'ids'     => count( $ids ),
					)
				);
			}
		);
	}

	/**
	 * Per-request counters.
	 *
	 * @var array<string, int>
	 */
	private array $bulk_counts = array();

	/**
	 * Increment a counter.
	 *
	 * @param string $key Counter key.
	 * @return void
	 */
	private function bulk_count( string $key ): void {
		$this->bulk_counts[ $key ] = (int) ( $this->bulk_counts[ $key ] ?? 0 ) + 1;
	}

	/**
	 * Record a skip reason (currently only used for diagnostics).
	 *
	 * @param int    $product_id Product id.
	 * @param string $reason     Reason code.
	 * @return void
	 */
	private function touched_note( int $product_id, string $reason ): void {
		unset( $product_id, $reason );
	}

	/**
	 * Read a string from the request.
	 *
	 * @param array<string, mixed> $request Request array.
	 * @param string               $key     Key.
	 * @return string
	 */
	private function str( array $request, string $key ): string {
		return isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) ? (string) $request[ $key ] : '';
	}

	/**
	 * Show the bulk-edit result after the redirect.
	 *
	 * @return void
	 */
	public function render_bulk_notice(): void {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$data = get_user_meta( $user_id, self::BULK_NOTICE_META, true );

		if ( ! is_array( $data ) ) {
			return;
		}

		delete_user_meta( $user_id, self::BULK_NOTICE_META );

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || 'edit-product' !== $screen->id ) {
			return;
		}

		$updated  = (int) ( $data['updated'] ?? 0 );
		$disabled = (int) ( $data['disabled'] ?? 0 );
		$ids      = (int) ( $data['ids'] ?? 0 );

		if ( 0 === $updated && 0 === $disabled ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: updated count, 2: disabled count */
					__( '%1$d products updated (%2$d had currency pricing turned off); prices are being recalculated in the background.', 'ratebridge-woo' ),
					$updated,
					$disabled
				)
			)
		);

		unset( $ids );
	}
}
