<?php
/**
 * Classic product editor fields (simple + variable products).
 *
 * Everything goes through native WooCommerce hooks; prices are only ever
 * written through the CRUD API (`set_regular_price`, `set_sale_price`).
 *
 * @package RateBridge
 */

namespace RateBridge\Product;

use RateBridge\Core\DateFormatter;
use RateBridge\Admin\AjaxController;
use RateBridge\Admin\SettingsPage;
use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Pricing\PriceCalculator;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Pricing\FormulaPricer;
use RateBridge\Pricing\Repricer;
use WC_Product;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the "pricing by currency" product fields.
 */
final class ProductFields {

	/**
	 * Asset handle.
	 */
	public const HANDLE = 'rbw-product';

	/**
	 * Register every hook.
	 *
	 * @return void
	 */
	public function register(): void {
		// Simple products.
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_simple' ) );

		// Variations.
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );

		// Re-reprice after WooCommerce has stored our meta.
		add_action( 'woocommerce_update_product', array( $this, 'reprice_after_save' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'reprice_parent_after_variation_save' ), 20, 1 );

		/*
		 * Products list column.
		 *
		 * WooCommerce 8+ uses `manage_product_posts_columns` and renders cells
		 * through its own list table, which dispatches
		 * `manage_product_posts_custom_column` to BOTH its handler and ours. When
		 * we also registered `manage_posts_custom_column`, our cell could be
		 * rendered twice (or, with a per-post "already rendered" guard, not at
		 * all while WooCommerce's `price` handler still consumed the row) — which
		 * shifted the price and currency cells into each other.
		 *
		 * The rule: add the column on the *same* filter WooCommerce uses, and
		 * render through exactly ONE action, with no per-post static state.
		 */
		add_filter( 'manage_product_posts_columns', array( $this, 'add_column' ), 20 );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );

		// Modern WooCommerce list table (priority 11 so it runs after WC's own
		// handler prepared the row data; the callback only echoes our column).
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 11, 2 );

		// Classic fallback for post types that still use the generic hook.
		add_action( 'manage_posts_custom_column', array( $this, 'render_column_classic' ), 11, 2 );

		// Bulk action: recalculate the selected products right now.
		add_filter( 'bulk_actions-edit-product', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_bulk_result_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_queued_notices' ) );

		// Assets on the product edit screen.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// The panel and preview use the same inline SVG sprite as the plugin page.
		add_action( 'admin_footer', array( $this, 'render_icon_sprite' ) );
	}

	/**
	 * Make sure the WooCommerce meta-box helpers are available.
	 *
	 * `woocommerce_wp_text_input()` / `woocommerce_wp_checkbox()` live in
	 * `wc-meta-box-functions.php`, which WooCommerce only loads on admin screens.
	 * The product editor always has them, but loading the file defensively keeps
	 * these renderers usable from any context (REST, CLI, tests).
	 *
	 * @return void
	 */
	private function load_meta_box_functions(): void {
		if ( function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}

		$file = defined( 'WC_ABSPATH' ) ? WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php' : '';

		if ( '' !== $file && is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Enabled currencies for the dropdowns (code + name + rate).
	 *
	 * @return Currency[]
	 */
	private function currencies(): array {
		return ( new CurrencyRepository() )->get_all();
	}

	/**
	 * Currencies that can actually price a product right now.
	 *
	 * @return Currency[]
	 */
	private function usable_currencies(): array {
		return array_values(
			array_filter(
				$this->currencies(),
				static fn ( Currency $currency ): bool => $currency->is_enabled && $currency->has_rate()
			)
		);
	}

	/**
	 * Format a rate for the dropdown label.
	 *
	 * @param Currency $currency Currency.
	 * @return string
	 */
	private function currency_label( Currency $currency ): string {
		$rate = $currency->has_rate()
			? sprintf(
				/* translators: 1: rate, 2: unit label (Rial/Toman) */
				__( 'rate: %1$s %2$s', 'ratebridge-woo' ),
				SettingsPage::format_rate( $currency ),
				SettingsPage::unit_label()
			)
			: __( 'no rate yet', 'ratebridge-woo' );

		$label = sprintf( '%s — %s (%s)', $currency->code, $currency->name, $rate );

		// Disabled rows stay selectable so an existing product keeps its currency
		// and the admin can see *why* prices are not updating.
		if ( ! $currency->is_enabled ) {
			$label .= ' — ' . __( 'disabled', 'ratebridge-woo' );
		}

		if ( ! $currency->has_rate() ) {
			$label .= ' — ' . __( 'no rate', 'ratebridge-woo' );
		}

		return $label;
	}

	/**
	 * Render the simple-product section.
	 *
	 * @return void
	 */
	public function render_simple_fields(): void {
		$this->load_meta_box_functions();

		global $post;

		$post_id    = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$data       = $post_id > 0 ? ProductData::read( $post_id ) : ProductData::read( 0 );
		$currencies = $this->currencies();
		$unit       = SettingsPage::unit_label();

		echo '<div class="options_group rbw-product-panel rbw-admin">';

		woocommerce_wp_checkbox(
			array(
				'id'            => 'rbw_enabled',
				'value'         => $data['enabled'] ? 'yes' : 'no',
				'label'         => __( 'Pricing by currency', 'ratebridge-woo' ),
				'description'   => __( 'When enabled, the price is calculated from the currency rate in this plugin.', 'ratebridge-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'rbw-field-enabled',
			)
		);

		echo '<div class="rbw-product-fields" id="rbw-simple-fields"' . ( $data['enabled'] ? '' : ' hidden' ) . '>';

		// Pricing method: currency (base price × rate) or formula.
		$this->render_mode_select( $post_id, $data );

		echo '<div class="rbw-mode-currency"' . ( ProductData::MODE_FORMULA === $data['mode'] ? ' hidden' : '' ) . '>';

		if ( empty( $currencies ) ) {
			printf(
				'<p class="rbw-product-note rbw-product-note-warning">%s</p>',
				esc_html__( 'There is no currency yet. Create one in WooCommerce → نرخ ساز → Currencies first.', 'ratebridge-woo' )
			);
		} elseif ( empty( $this->usable_currencies() ) ) {
			printf(
				'<p class="rbw-product-note rbw-product-note-warning">%s <a href="%s">%s</a></p>',
				esc_html__( 'No currency is ready: every currency is disabled or has no rate yet, so product prices cannot be calculated.', 'ratebridge-woo' ),
				esc_url( admin_url( 'admin.php?page=ratebridge-woo' ) ),
				esc_html__( 'Open the currency settings', 'ratebridge-woo' )
			);
		}

		// Currency select.
		echo '<p class="form-field rbw-field">';
		echo '<label for="rbw_currency">' . esc_html__( 'Currency', 'ratebridge-woo' ) . '</label>';
		echo '<select id="rbw_currency" name="rbw_currency" class="wc-enhanced-select">';
		echo '<option value="">' . esc_html__( 'Select a currency…', 'ratebridge-woo' ) . '</option>';

		foreach ( $currencies as $currency ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $currency->code ),
				selected( $data['currency'], $currency->code, false ),
				esc_html( $this->currency_label( $currency ) )
			);
		}

		echo '</select>';
		echo '</p>';

		$this->render_currency_state( $data['currency'] );

		$this->render_numeric_field(
			'base_price',
			__( 'Base price (in the currency)', 'ratebridge-woo' ),
			$data['base_price'],
			__( 'For example 100 for USD 100, or 4.75 for 4.75 grams of 18K gold.', 'ratebridge-woo' )
		);
		$this->render_numeric_field(
			'sale_base_price',
			__( 'Sale base price (optional)', 'ratebridge-woo' ),
			$data['sale_price'],
			__( 'Optional, in the same currency. Leave empty for no sale price.', 'ratebridge-woo' )
		);
		$this->render_numeric_field(
			'min_price',
			sprintf(
				/* translators: %s: unit label */
				__( 'Minimum price (%s)', 'ratebridge-woo' ),
				$unit
			),
			$data['min_price'],
			__( 'The calculated price is never written below this amount. Leave empty for none.', 'ratebridge-woo' )
		);
		$this->render_numeric_field(
			'fluctuation',
			sprintf(
				/* translators: %s: unit label */
				__( 'Fluctuation margin (%s)', 'ratebridge-woo' ),
				$unit
			),
			$data['fluctuation'],
			__( 'If the new price differs from the previous one by less than this amount, the old price is kept.', 'ratebridge-woo' )
		);

		// Profit / commission / extra with a mode select.
		$this->render_rule_section( 'profit', __( 'Profit', 'ratebridge-woo' ), $data );
		$this->render_rule_section( 'commission', __( 'Commission', 'ratebridge-woo' ), $data );
		$this->render_rule_section( 'extra', __( 'Extra amount', 'ratebridge-woo' ), $data, true );

		echo '</div><!-- .rbw-mode-currency -->';

		$this->render_formula_block( $post_id, $data );

		echo '<p class="form-field rbw-preview-row">';
		printf(
			'<button type="button" class="button rbw-preview-button" data-target="rbw-simple-preview">%s</button>',
			esc_html__( 'Price preview', 'ratebridge-woo' )
		);
		echo '<span class="rbw-preview-output" id="rbw-simple-preview" aria-live="polite"></span>';
		echo '</p>';

		echo '</div><!-- .rbw-product-fields -->';
		echo '<p class="rbw-product-note rbw-managed-note" id="rbw-managed-note"' . ( $data['enabled'] ? '' : ' hidden' ) . '>';
		esc_html_e( 'The regular price and the sale price are controlled by the currency and are read-only while pricing by currency is enabled.', 'ratebridge-woo' );
		echo '</p>';

		echo '</div><!-- .rbw-product-panel -->';
	}

	/**
	 * Explain why the selected currency cannot produce a price right now.
	 *
	 * A disabled currency (or one without a rate) means the product keeps its
	 * last price and, when it never had one, WooCommerce shows it as
	 * unavailable. Saying so here beats a silent "out of stock" on the front end.
	 *
	 * @param string $code Currently selected currency code.
	 * @return void
	 */
	private function render_currency_state( string $code ): void {
		if ( '' === $code ) {
			return;
		}

		$currency = ( new CurrencyRepository() )->get_by_code( $code );

		if ( ! $currency instanceof Currency ) {
			printf(
				'<p class="rbw-product-note rbw-product-note-warning">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: currency code */
						__( 'The currency "%s" no longer exists. Prices cannot be calculated.', 'ratebridge-woo' ),
						$code
					)
				)
			);

			return;
		}

		$problems = array();

		if ( ! $currency->is_enabled ) {
			$problems[] = __( 'it is disabled', 'ratebridge-woo' );
		}

		if ( ! $currency->has_rate() ) {
			$problems[] = __( 'it has no rate yet', 'ratebridge-woo' );
		}

		if ( empty( $problems ) ) {
			return;
		}

		printf(
			'<p class="rbw-product-note rbw-product-note-warning">%s <a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: 1: currency code, 2: comma separated problems */
					__( 'Prices are not being calculated because the currency %1$s cannot be used: %2$s.', 'ratebridge-woo' ),
					$currency->code,
					implode( __( ' and ', 'ratebridge-woo' ), $problems )
				)
			),
			esc_url( admin_url( 'admin.php?page=ratebridge-woo' ) ),
			esc_html__( 'Fix it in the currency settings', 'ratebridge-woo' )
		);
	}

	/**
	 * Pricing method select: currency (base price × rate) or formula.
	 *
	 * @param int                  $post_id Product id.
	 * @param array<string, mixed> $data    Product data.
	 * @return void
	 */
	private function render_mode_select( int $post_id, array $data ): void {
		unset( $post_id );

		$mode = (string) ( $data['mode'] ?? ProductData::MODE_CURRENCY );

		/*
		 * A segmented control over the SAME `rbw_mode` field: two radio buttons
		 * with identical name/value semantics, so the save handler and the JS keep
		 * reading `[name="rbw_mode"]` exactly as before.
		 */
		printf(
			'<div class="form-field rbw-field rbw-mode-field rbw-segmented" role="radiogroup" aria-label="%1$s">
				<span class="rbw-field-label">%1$s</span>
				<label class="rbw-segment">
					<input type="radio" name="rbw_mode" id="rbw_mode" value="currency"%2$s />
					<span>%3$s</span>
				</label>
				<label class="rbw-segment">
					<input type="radio" name="rbw_mode" id="rbw_mode_formula" value="formula"%4$s />
					<span>%5$s</span>
				</label>
				<span class="description">%6$s</span>
			</div>',
			esc_html__( 'Pricing method', 'ratebridge-woo' ),
			checked( $mode, ProductData::MODE_CURRENCY, false ),
			esc_html__( 'Currency × base price', 'ratebridge-woo' ),
			checked( $mode, ProductData::MODE_FORMULA, false ),
			esc_html__( 'Formula', 'ratebridge-woo' ),
			esc_html__( 'A formula computes the price from variables such as weight, rates and custom values.', 'ratebridge-woo' )
		);
	}

	/**
	 * Formula picker + variable inputs for the simple product editor.
	 *
	 * @param int                  $post_id Product id.
	 * @param array<string, mixed> $data    Product data.
	 * @return void
	 */
	private function render_formula_block( int $post_id, array $data ): void {
		$formulas   = ( new FormulaRepository() )->get_all( true );
		$formula_id = (int) ( $data['formula_id'] ?? 0 );
		$overrides  = is_array( $data['formula_vars'] ?? null ) ? $data['formula_vars'] : array();
		$hidden     = ProductData::MODE_FORMULA === ( $data['mode'] ?? '' ) ? '' : ' hidden';

		echo '<div class="rbw-mode-formula"' . esc_attr( $hidden ) . '>';

		if ( empty( $formulas ) ) {
			printf(
				'<p class="rbw-product-note rbw-product-note-warning">%s <a href="%s">%s</a></p>',
				esc_html__( 'There is no enabled formula yet.', 'ratebridge-woo' ),
				esc_url( admin_url( 'admin.php?page=ratebridge-woo&tab=formulas' ) ),
				esc_html__( 'Create a formula', 'ratebridge-woo' )
			);

			echo '</div>';

			return;
		}

		echo '<p class="form-field rbw-field">';
		echo '<label for="rbw_formula_id">' . esc_html__( 'Formula', 'ratebridge-woo' ) . '</label>';
		echo '<select id="rbw_formula_id" name="rbw_formula_id" class="rbw-formula-select">';
		echo '<option value="">' . esc_html__( 'Select a formula…', 'ratebridge-woo' ) . '</option>';

		foreach ( $formulas as $formula ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $formula->id ),
				selected( $formula_id, $formula->id, false ),
				esc_html( $formula->name )
			);
		}

		echo '</select>';
		echo '</p>';

		// Variables of the selected formula.
		$selected = $formula_id > 0 ? ( new FormulaRepository() )->get( $formula_id ) : null;

		echo '<div class="rbw-formula-vars-panel" id="rbw-product-formula-vars">';

		if ( $selected instanceof FormulaModel ) {
			$this->render_formula_variables( $selected, wc_get_product( $post_id ), $overrides );
		}

		echo '</div>';

		printf(
			'<p class="rbw-product-note">%s</p>',
			esc_html__( 'In formula mode the currency, base price, profit, commission and extra amount are ignored: put them in the formula. Formula products also have no automatic sale price.', 'ratebridge-woo' )
		);

		echo '</div>';
	}

	/**
	 * Render the variable rows for a formula on a product.
	 *
	 * @param FormulaModel       $formula   Formula.
	 * @param \WC_Product|null   $product   Product.
	 * @param array<string,float> $overrides Per-product overrides.
	 * @return void
	 */
	private function render_formula_variables( FormulaModel $formula, $product, array $overrides ): void {
		$pricer    = new FormulaPricer();
		$parent_id = $product instanceof \WC_Product && $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		echo '<table class="widefat striped rbw-formula-variable-table"><tbody>';

		foreach ( $formula->variables as $variable ) {
			$name  = (string) $variable['name'];
			$label = (string) ( $variable['label'] ?? $name );
			$type  = (string) $variable['type'];

			echo '<tr>';
			printf(
				'<th scope="row"><code dir="ltr">%s</code><div class="rbw-muted">%s</div></th>',
				esc_html( $name ),
				esc_html( $label )
			);
			echo '<td>';

			if ( FormulaModel::TYPE_CURRENCY === $type ) {
				$currency = ( new \RateBridge\Currency\CurrencyRepository() )->get_by_code( (string) ( $variable['currency_code'] ?? '' ) );
				$rate     = $currency instanceof \RateBridge\Currency\Currency ? $currency->rate : 0.0;

				printf(
					'<span class="rbw-readonly-value" dir="ltr">%s</span> <span class="rbw-muted">%s</span>%s',
					esc_html( SettingsPage::format_number( $rate, $rate >= 1000 ? 0 : 4 ) ),
					esc_html( SettingsPage::unit_label() ),
					'' === (string) ( $variable['currency_code'] ?? '' )
						? ''
						: ' <code dir="ltr">' . esc_html( (string) $variable['currency_code'] ) . '</code>'
				);

				if ( $rate <= 0 ) {
					printf(
						'<div class="rbw-error-text">%s</div>',
						esc_html__( 'This currency has no rate yet, so the price cannot be calculated.', 'ratebridge-woo' )
					);
				}
			} elseif ( FormulaModel::TYPE_PRODUCT === $type ) {
				$field = (string) ( $variable['product_field'] ?? 'weight' );
				$value = $product instanceof \WC_Product ? $pricer->product_field( $product, $field, $parent_id ) : null;

				printf(
					'<span class="rbw-readonly-value" dir="ltr">%s</span> <span class="rbw-muted">%s</span>',
					null === $value ? '—' : esc_html( SettingsPage::format_number( $value, 4 ) ),
					esc_html( (string) ( self::product_field_labels()[ $field ] ?? $field ) )
				);

				if ( null === $value ) {
					printf(
						'<div class="rbw-error-text">%s</div>',
						esc_html__( 'This product has no value for this field, so the price cannot be calculated.', 'ratebridge-woo' )
					);
				}
			} else {
				$value = array_key_exists( $name, $overrides )
					? (string) $overrides[ $name ]
					: ( isset( $variable['default'] ) ? (string) $variable['default'] : '' );

				printf(
					'<input type="text" class="short rbw-number rbw-formula-var-input" dir="ltr" inputmode="decimal"
						id="rbw_var_%1$s" name="rbw_formula_vars[%1$s]" value="%2$s" data-default="%3$s" />',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( isset( $variable['default'] ) ? (string) $variable['default'] : '0' )
				);

				if ( '' !== (string) ( $variable['default'] ?? '' ) ) {
					printf(
						' <span class="rbw-muted">%s</span>',
						esc_html(
							sprintf(
								/* translators: %s: default value */
								__( 'default: %s', 'ratebridge-woo' ),
								(string) $variable['default']
							)
						)
					);
				}

				if ( ! empty( $variable['is_money'] ) ) {
					printf(
						' <span class="rbw-muted">(%s)</span>',
						esc_html( SettingsPage::unit_label() )
					);
				}

				if ( isset( $variable['min'] ) || isset( $variable['max'] ) ) {
					printf(
						' <span class="rbw-muted">(%s)</span>',
						esc_html(
							sprintf(
								/* translators: 1: min, 2: max */
								__( 'min %1$s, max %2$s', 'ratebridge-woo' ),
								null === ( $variable['min'] ?? null ) ? '—' : (string) $variable['min'],
								null === ( $variable['max'] ?? null ) ? '—' : (string) $variable['max']
							)
						)
					);
				}
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Persian labels for the supported product fields.
	 *
	 * @return array<string, string>
	 */
	private static function product_field_labels(): array {
		return array(
			'weight' => __( 'Weight', 'ratebridge-woo' ),
			'length' => __( 'Length', 'ratebridge-woo' ),
			'width'  => __( 'Width', 'ratebridge-woo' ),
			'height' => __( 'Height', 'ratebridge-woo' ),
		);
	}

	/**
	 * Render a numeric field inside the panel.
	 *
	 * @param string $name        Field name (without prefix).
	 * @param string $label       Label.
	 * @param float  $value       Current value.
	 * @param string $description Help text.
	 * @return void
	 */
	private function render_numeric_field( string $name, string $label, float $value, string $description ): void {
		printf(
			'<p class="form-field rbw-field">
				<label for="rbw_%1$s">%2$s</label>
				<input type="text" class="short wc_input_price rbw-number" id="rbw_%1$s" name="rbw_%1$s" value="%3$s" dir="ltr" inputmode="decimal" />
				<span class="description">%4$s</span>
			</p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( '' === $this->number_to_string( $value ) ? '' : $this->number_to_string( $value ) ),
			esc_html( $description )
		);
	}

	/**
	 * Render one rule block (type + value + the shared mode select on profit).
	 *
	 * @param string               $slot       profit|commission|extra.
	 * @param string               $label      Label.
	 * @param array<string, mixed> $data       Product data.
	 * @param bool                 $fixed_only Whether the type select is hidden (extra is always fixed).
	 * @return void
	 */
	private function render_rule_section( string $slot, string $label, array $data, bool $fixed_only = false ): void {
		$rule = is_array( $data[ $slot ] ?? null ) ? $data[ $slot ] : array(
			'type'  => 'percent',
			'value' => 0.0,
		);

		if ( $fixed_only ) {
			$rule['type'] = 'fixed';
		}

		echo '<div class="rbw-rule-group">';

		if ( 'profit' === $slot ) {
			printf(
				'<p class="form-field rbw-field">
					<label for="rbw_profit_mode">%1$s</label>
					<select id="rbw_profit_mode" name="rbw_profit_mode" class="rbw-mode">
						<option value="none"%2$s>%3$s</option>
						<option value="add"%4$s>%5$s</option>
						<option value="replace"%6$s>%7$s</option>
					</select>
					<span class="description">%8$s</span>
				</p>',
				esc_html__( 'Profit, commission and extra mode', 'ratebridge-woo' ),
				selected( $data['profit_mode'] ?? 'none', 'none', false ),
				esc_html__( 'Use the currency values', 'ratebridge-woo' ),
				selected( $data['profit_mode'] ?? 'none', 'add', false ),
				esc_html__( 'Add the product values to the currency values', 'ratebridge-woo' ),
				selected( $data['profit_mode'] ?? 'none', 'replace', false ),
				esc_html__( 'Replace the currency values with the product values', 'ratebridge-woo' ),
				esc_html__( 'With “add”, a percentage and a fixed amount are combined after the percentage is converted to an amount.', 'ratebridge-woo' )
			);
		}

		echo '<p class="form-field rbw-field rbw-rule">';
		printf( '<label for="rbw_%1$s_value">%2$s</label>', esc_attr( $slot ), esc_html( $label ) );

		if ( ! $fixed_only ) {
			printf(
				'<select name="rbw_%1$s_type" id="rbw_%1$s_type" class="rbw-rule-type" data-slot="%1$s">
					<option value="percent"%2$s>%3$s</option>
					<option value="fixed"%4$s>%5$s</option>
				</select>',
				esc_attr( $slot ),
				selected( $rule['type'], 'percent', false ),
				esc_html__( 'Percent', 'ratebridge-woo' ),
				selected( $rule['type'], 'fixed', false ),
				esc_html__( 'Fixed amount', 'ratebridge-woo' )
			);
		} else {
			printf( '<input type="hidden" name="rbw_%s_type" value="fixed" />', esc_attr( $slot ) );
		}

		printf(
			'<input type="text" class="short rbw-number" id="rbw_%1$s_value" name="rbw_%1$s_value" value="%2$s" dir="ltr" inputmode="decimal" />',
			esc_attr( $slot ),
			esc_attr( $this->number_to_string( (float) $rule['value'] ) )
		);

		echo '<span class="description rbw-rule-hint" data-slot="' . esc_attr( $slot ) . '">';
		echo esc_html( 'percent' === $rule['type'] ? __( 'Percentage', 'ratebridge-woo' ) : __( 'Amount in the store unit', 'ratebridge-woo' ) );
		echo '</span>';
		echo '</p>';

		echo '</div>';
	}

	/**
	 * Save the simple-product fields.
	 *
	 * WooCommerce already verified the nonce and the capability before this
	 * hook fires, so only sanitisation and persistence are needed here.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public function save_simple( WC_Product $product ): void {
		$this->save_fields( (int) $product->get_id(), 'product', $product );
	}

	/**
	 * Persist the `_rbw_*` meta for a post with a given input scope.
	 *
	 * @param int             $post_id Post id.
	 * @param string          $scope   'product' or 'variation'.
	 * @param WC_Product|null $product Product object (for the repricing call).
	 * @return void
	 */
	private function save_fields( int $post_id, string $scope, ?WC_Product $product = null ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) && ! current_user_can( 'edit_products' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before these hooks.
		$enabled = isset( $_POST['rbw_enabled'] ) && 'yes' === sanitize_text_field( wp_unslash( (string) $_POST['rbw_enabled'] ) );

		/*
		 * Variations have no checkbox of their own: they inherit the parent's
		 * state, so a variation of a disabled parent is never published as a
		 * managed price.
		 */
		if ( 'variation' === $scope ) {
			$parent_id = (int) wp_get_post_parent_id( $post_id );
			$enabled   = $parent_id > 0 && ProductData::is_enabled( $parent_id );
		}

		if ( $enabled ) {
			update_post_meta( $post_id, ProductData::META_ENABLED, 'yes' );

			// Pricing method: currency (default) or formula.
			$mode = isset( $_POST['rbw_mode'] )
				? sanitize_key( wp_unslash( (string) $_POST['rbw_mode'] ) )
				: ProductData::MODE_CURRENCY;

			$mode = ProductData::MODE_FORMULA === $mode ? ProductData::MODE_FORMULA : ProductData::MODE_CURRENCY;

			if ( ProductData::MODE_CURRENCY === $mode ) {
				// Keep a clean row: no formula meta in currency mode.
				delete_post_meta( $post_id, ProductData::META_MODE );
				delete_post_meta( $post_id, ProductData::META_FORMULA_ID );
				delete_post_meta( $post_id, ProductData::META_FORMULA_VARS );
			} else {
				update_post_meta( $post_id, ProductData::META_MODE, ProductData::MODE_FORMULA );

				$formula_id = isset( $_POST['rbw_formula_id'] ) ? absint( $_POST['rbw_formula_id'] ) : 0;
				$formula    = $formula_id > 0 ? ( new FormulaRepository() )->get( $formula_id ) : null;

				if ( $formula instanceof FormulaModel ) {
					update_post_meta( $post_id, ProductData::META_FORMULA_ID, (string) $formula->id );

					// Custom variable overrides (empty = use the formula default).
					$posted = isset( $_POST['rbw_formula_vars'] ) && is_array( $_POST['rbw_formula_vars'] )
						? wp_unslash( $_POST['rbw_formula_vars'] )
						: array();

					$overrides = array();

					foreach ( $formula->variables_of_type( FormulaModel::TYPE_CUSTOM ) as $variable ) {
						$name = (string) $variable['name'];

						if ( ! isset( $posted[ $name ] ) || '' === trim( (string) $posted[ $name ] ) ) {
							continue;
						}

						$value = ProductData::to_decimal( $posted[ $name ] );

						if ( $value > 0 || '0' === trim( (string) $posted[ $name ] ) ) {
							$overrides[ $name ] = $value;
						}
					}

					if ( empty( $overrides ) ) {
						delete_post_meta( $post_id, ProductData::META_FORMULA_VARS );
					} else {
						update_post_meta( $post_id, ProductData::META_FORMULA_VARS, wp_json_encode( $overrides ) );
					}
				} else {
					delete_post_meta( $post_id, ProductData::META_FORMULA_ID );
					delete_post_meta( $post_id, ProductData::META_FORMULA_VARS );
				}
			}

			$currency = isset( $_POST['rbw_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['rbw_currency'] ) ) ) : '';

			if ( '' !== $currency ) {
				$exists = ( new CurrencyRepository() )->get_by_code( $currency );

				if ( $exists instanceof Currency ) {
					update_post_meta( $post_id, ProductData::META_CURRENCY, $exists->code );
				}
			}

			foreach ( array(
				'base_price'      => ProductData::META_BASE_PRICE,
				'sale_base_price' => ProductData::META_SALE_BASE_PRICE,
				'min_price'       => ProductData::META_MIN_PRICE,
				'fluctuation'     => ProductData::META_FLUCTUATION,
			) as $field => $meta_key ) {
				if ( ! isset( $_POST[ 'rbw_' . $field ] ) ) {
					continue;
				}

				$raw   = wp_unslash( (string) $_POST[ 'rbw_' . $field ] );
				$value = ProductData::to_decimal( $raw );

				if ( $value > 0 ) {
					update_post_meta( $post_id, $meta_key, wc_format_decimal( $value, 4 ) );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
			}

			if ( isset( $_POST['rbw_profit_mode'] ) ) {
				update_post_meta( $post_id, ProductData::META_PROFIT_MODE, PriceCalculator::to_mode( sanitize_key( wp_unslash( (string) $_POST['rbw_profit_mode'] ) ) ) );
			}

			foreach ( array(
				'profit'     => array( ProductData::META_PROFIT_TYPE, ProductData::META_PROFIT_VALUE ),
				'commission' => array( ProductData::META_COMMISSION_TYPE, ProductData::META_COMMISSION_VALUE ),
			) as $slot => $keys ) {
				if ( isset( $_POST[ 'rbw_' . $slot . '_type' ] ) ) {
					$type = PriceCalculator::to_type( sanitize_key( wp_unslash( (string) $_POST[ 'rbw_' . $slot . '_type' ] ) ) );
					update_post_meta( $post_id, $keys[0], $type );
				}

				if ( isset( $_POST[ 'rbw_' . $slot . '_value' ] ) ) {
					$value = PriceCalculator::sanitize_rule(
						array(
							'type'  => (string) get_post_meta( $post_id, $keys[0], true ),
							'value' => wp_unslash( (string) $_POST[ 'rbw_' . $slot . '_value' ] ),
						)
					);

					update_post_meta( $post_id, $keys[1], wc_format_decimal( (float) ( $value['value'] ?? 0 ), 4 ) );
				}
			}

			if ( isset( $_POST['rbw_extra_value'] ) ) {
				$extra = ProductData::to_decimal( wp_unslash( (string) $_POST['rbw_extra_value'] ) );
				update_post_meta( $post_id, ProductData::META_EXTRA_AMOUNT, wc_format_decimal( $extra, 4 ) );
			}
		} else {
			foreach ( ProductData::all_meta_keys() as $meta_key ) {
				delete_post_meta( $post_id, $meta_key );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		unset( $product );
	}

	/**
	 * Render the per-variation fields.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param \WP_Post $variation     Variation post.
	 * @return void
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		$this->load_meta_box_functions();

		$variation_id = is_object( $variation ) ? (int) $variation->ID : 0;
		$parent_id    = is_object( $variation ) ? (int) $variation->post_parent : 0;

		$data    = ProductData::read( $variation_id );
		$enabled = ProductData::is_enabled( $parent_id );
		$parent  = $parent_id > 0 ? ProductData::currency_code( $parent_id ) : '';
		$loop    = (int) $loop;

		/*
		 * The hook fires inside WooCommerce's `.variable_pricing` flex row, right
		 * after the regular/sale price inputs. A full-width block with a leading
		 * clear keeps it on its own line instead of squeezing into the flex row
		 * (which used to scatter the fields above the variation).
		 */
		echo '<div class="rbw-variation-fields">';

		if ( ! $enabled ) {
			printf(
				'<p class="rbw-product-note">%s</p>',
				esc_html__( 'Enable “Pricing by currency” on the parent product to price this variation by a currency.', 'ratebridge-woo' )
			);

			echo '</div>';

			return;
		}

		printf(
			'<p class="rbw-product-note">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: parent currency code */
					__( 'Prices of this variation are calculated from the parent currency (%s).', 'ratebridge-woo' ),
					'' !== $parent ? $parent : __( 'not set', 'ratebridge-woo' )
				)
			)
		);

		// Same sanity check as the parent, so a disabled currency is obvious here.
		$parent_currency = '' !== $parent ? ( new CurrencyRepository() )->get_by_code( $parent ) : null;

		if ( $parent_currency instanceof Currency && ( ! $parent_currency->is_enabled || ! $parent_currency->has_rate() ) ) {
			printf(
				'<p class="rbw-product-note rbw-product-note-warning">%s</p>',
				esc_html__(
					'The parent currency is disabled or has no rate, so this variation keeps its current price (or shows as unavailable when it has none).',
					'ratebridge-woo'
				)
			);
		}

		// Base price.
		printf(
			'<p class="form-row form-row-first rbw-variation-field">
				<label for="rbw_variation_base_price_%1$s">%2$s</label>
				<input type="text" class="short wc_input_price rbw-number" id="rbw_variation_base_price_%1$s"
					name="rbw_variation_base_price[%3$d]" value="%4$s" dir="ltr" inputmode="decimal"
					data-rbw="variation-base" placeholder="0" />
				<span class="description">%5$s</span>
			</p>',
			esc_attr( (string) $loop ),
			esc_html__( 'Base price (in the currency)', 'ratebridge-woo' ),
			$loop,
			esc_attr( $this->number_to_string( $data['base_price'] ) ),
			esc_html__( 'Price of this variation in the parent currency.', 'ratebridge-woo' )
		);

		// Optional sale base price.
		printf(
			'<p class="form-row form-row-last rbw-variation-field">
				<label for="rbw_variation_sale_base_price_%1$s">%2$s</label>
				<input type="text" class="short wc_input_price rbw-number" id="rbw_variation_sale_base_price_%1$s"
					name="rbw_variation_sale_base_price[%3$d]" value="%4$s" dir="ltr" inputmode="decimal" placeholder="0" />
				<span class="description">%5$s</span>
			</p>',
			esc_attr( (string) $loop ),
			esc_html__( 'Sale base price (optional)', 'ratebridge-woo' ),
			$loop,
			esc_attr( $this->number_to_string( $data['sale_price'] ) ),
			esc_html__( 'Optional sale price in the parent currency.', 'ratebridge-woo' )
		);

		// Optional currency override for this variation.
		echo '<p class="form-row form-row-full rbw-variation-field">';
		printf(
			'<label for="rbw_variation_currency_%1$s">%2$s</label>',
			esc_attr( (string) $loop ),
			esc_html__( 'Currency (parent currency)', 'ratebridge-woo' )
		);
		printf( '<select id="rbw_variation_currency_%1$s" name="rbw_variation_currency[%1$d]" class="rbw-variation-currency">', $loop );
		printf( '<option value="">%s</option>', esc_html__( 'Use the parent currency', 'ratebridge-woo' ) );

		foreach ( $this->currencies() as $currency ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $currency->code ),
				selected( $data['currency'], $currency->code, false ),
				esc_html( $this->currency_label( $currency ) )
			);
		}

		echo '</select>';
		echo '</p>';

		printf(
			'<p class="rbw-product-note">%s</p>',
			esc_html__( 'The regular price and the sale price of this variation are controlled by the currency and are read-only.', 'ratebridge-woo' )
		);

		echo '</div>';
	}

	/**
	 * Save the per-variation fields.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $loop         Variation loop index.
	 * @return void
	 */
	public function save_variation( $variation_id, $loop ): void {
		$variation_id = (int) $variation_id;
		$loop         = (int) $loop;

		if ( $variation_id <= 0 || ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the nonce.
		$base = isset( $_POST['rbw_variation_base_price'][ $loop ] )
			? ProductData::to_decimal( wp_unslash( (string) $_POST['rbw_variation_base_price'][ $loop ] ) )
			: 0.0;

		$sale = isset( $_POST['rbw_variation_sale_base_price'][ $loop ] )
			? ProductData::to_decimal( wp_unslash( (string) $_POST['rbw_variation_sale_base_price'][ $loop ] ) )
			: 0.0;

		$code = isset( $_POST['rbw_variation_currency'][ $loop ] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['rbw_variation_currency'][ $loop ] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $base > 0 ) {
			update_post_meta( $variation_id, ProductData::META_BASE_PRICE, wc_format_decimal( $base, 4 ) );

			// The flag mirrors the parent so a disabled parent stops repricing.
			$parent_id = (int) wp_get_post_parent_id( $variation_id );

			if ( $parent_id > 0 && ProductData::is_enabled( $parent_id ) ) {
				update_post_meta( $variation_id, ProductData::META_ENABLED, 'yes' );
			} else {
				delete_post_meta( $variation_id, ProductData::META_ENABLED );
			}
		} else {
			delete_post_meta( $variation_id, ProductData::META_BASE_PRICE );
			delete_post_meta( $variation_id, ProductData::META_ENABLED );
		}

		if ( $sale > 0 ) {
			update_post_meta( $variation_id, ProductData::META_SALE_BASE_PRICE, wc_format_decimal( $sale, 4 ) );
		} else {
			delete_post_meta( $variation_id, ProductData::META_SALE_BASE_PRICE );
		}

		if ( '' !== $code && ( new CurrencyRepository() )->get_by_code( $code ) instanceof Currency ) {
			update_post_meta( $variation_id, ProductData::META_CURRENCY, $code );
		} else {
			delete_post_meta( $variation_id, ProductData::META_CURRENCY );
		}

		// Audit meta is stale after a manual edit.
		delete_post_meta( $variation_id, ProductData::META_LAST_PRICE );
	}

	/**
	 * Reprice a product after the editor saved it.
	 *
	 * Re-entrancy guard: Repricer::apply_to_product() saves the product again,
	 * which fires this hook; `Repricer::is_saving()` breaks that loop.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function reprice_after_save( $product_id ): void {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 || Repricer::is_saving() ) {
			return;
		}

		if ( ! ProductData::is_enabled( $product_id ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$result = ( new Repricer() )->reprice_product( $product );

		/*
		 * A skipped run leaves the product without a calculated price. On a
		 * freshly created variation that means WooCommerce prints "out of
		 * stock" on the front end, which is impossible to diagnose from the
		 * editor, so the admin is told right away.
		 */
		if ( 'skipped' === $result['status'] && 'not_managed' !== $result['reason'] ) {
			$this->queue_admin_notice(
				sprintf(
					/* translators: 1: product name, 2: reason message */
					__( 'The price of “%1$s” was not calculated: %2$s', 'ratebridge-woo' ),
					$product->get_name(),
					$result['message']
				)
			);
		}
	}

	/**
	 * Remember a message to show on the next admin page load.
	 *
	 * Uses a short-lived per-user transient so it survives the redirect that
	 * follows a product save (a plain notice would be lost).
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	private function queue_admin_notice( string $message ): void {
		$key   = 'rbw_product_notice_' . get_current_user_id();
		$queue = get_transient( $key );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		if ( in_array( $message, $queue, true ) ) {
			return;
		}

		$queue[] = $message;

		set_transient( $key, array_slice( $queue, -5 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear the queued product messages.
	 *
	 * @return void
	 */
	public function render_queued_notices(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$key   = 'rbw_product_notice_' . get_current_user_id();
		$queue = get_transient( $key );

		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $queue as $message ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html( (string) $message )
			);
		}
	}

	/**
	 * Reprice the parent when a variation is saved directly.
	 *
	 * @param int $variation_id Variation id.
	 * @return void
	 */
	public function reprice_parent_after_variation_save( $variation_id ): void {
		if ( Repricer::is_saving() ) {
			return;
		}

		$variation = wc_get_product( (int) $variation_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return;
		}

		$parent_id = (int) $variation->get_parent_id();
		$parent    = $parent_id > 0 ? wc_get_product( $parent_id ) : null;

		if ( $parent instanceof WC_Product && ProductData::is_enabled( $parent_id ) ) {
			( new Repricer() )->reprice_product( $parent );
		}
	}

	/**
	 * Add the «ارز» column to the products list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_column( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array();
		}

		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'price' === $key ) {
				$new['rbw_currency'] = __( 'Currency', 'ratebridge-woo' );
			}
		}

		if ( ! isset( $new['rbw_currency'] ) ) {
			$new['rbw_currency'] = __( 'Currency', 'ratebridge-woo' );
		}

		return $new;
	}

	/**
	 * Render the «ارز» column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product id.
	 * @return void
	 */
	public function render_column( $column, $post_id ): void {
		if ( 'rbw_currency' !== $column ) {
			return;
		}

		$this->print_column( (int) $post_id );
	}

	/**
	 * Classic fallback: the generic `manage_posts_custom_column` action.
	 *
	 * `manage_product_posts_columns` already rendered the cell for products, so
	 * this only acts on the legacy hook for a non-product post type.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function render_column_classic( $column, $post_id ): void {
		if ( 'rbw_currency' !== $column ) {
			return;
		}

		$post_type = get_post_type( (int) $post_id );

		if ( 'product' === $post_type ) {
			// Handled by render_column() through the WooCommerce list table.
			return;
		}

		$this->print_column( (int) $post_id );
	}

	/**
	 * Print the «ارز» cell for one post.
	 *
	 * Stateless on purpose: WordPress and WooCommerce may call a column renderer
	 * more than once per row, and any "already rendered" cache either duplicates
	 * or swallows the cell (which shifted the neighbouring price column).
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	private function print_column( int $post_id ): void {
		echo '<div class="rbw-col-currency">';

		if ( ! ProductData::is_enabled( $post_id ) ) {
			// A variable product may still have managed variations.
			$variation_code = $this->first_variation_currency( $post_id );

			if ( '' === $variation_code ) {
				echo '<span class="rbw-muted">—</span></div>';
				return;
			}

			echo '<code>' . esc_html( $variation_code ) . '</code></div>';
			return;
		}

		$code   = ProductData::currency_code( $post_id );
		$last   = (int) get_post_meta( $post_id, ProductData::META_LAST_CALC, true );

		if ( ProductData::is_formula_mode( $post_id ) ) {
			$formula_id = ProductData::formula_id( $post_id );
			$formula    = $formula_id > 0 ? ( new FormulaRepository() )->get( $formula_id ) : null;

			printf(
				'<span class="rbw-formula-badge">%s</span>',
				esc_html__( 'Formula', 'ratebridge-woo' )
			);

			if ( $formula instanceof FormulaModel ) {
				printf(
					'<div class="rbw-muted"><code dir="ltr">%s</code></div>',
					esc_html( $formula->slug )
				);
			}

			$problem = $this->unpriced_reason( $post_id, '' );

			if ( '' !== $problem ) {
				printf( '<div class="rbw-col-warning">%s</div>', esc_html( $problem ) );
			}

			if ( $last > 0 ) {
				printf(
					'<span class="rbw-col-date"><span class="rbw-col-date-label">%s</span> <time dir="ltr" datetime="%s">%s</time></span>',
					esc_html__( 'Last calculation:', 'ratebridge-woo' ),
					esc_attr( gmdate( 'c', $last ) ),
					esc_html( DateFormatter::format( (int) $last, 'Y-m-d H:i' ) )
				);
			}

			echo '</div>';
			return;
		}

		echo '<code>' . esc_html( '' !== $code ? $code : '—' ) . '</code>';

		// Why a managed product may still have no calculated price.
		$problem = $this->unpriced_reason( $post_id, $code );

		if ( '' !== $problem ) {
			printf( '<div class="rbw-col-warning">%s</div>', esc_html( $problem ) );
		}

		if ( $last > 0 ) {
			/*
			 * The timestamp is split into a label and a `dir="ltr"` value: the
			 * date is a single short token that must stay on one line, and the
			 * label may wrap. This keeps the cell readable even when a site
			 * dequeues product.css.
			 */
			printf(
				'<span class="rbw-col-date"><span class="rbw-col-date-label">%s</span> <time dir="ltr" datetime="%s">%s</time></span>',
				esc_html__( 'Last calculation:', 'ratebridge-woo' ),
				esc_attr( gmdate( 'c', $last ) ),
				esc_html( DateFormatter::format( (int) $last, 'Y-m-d H:i' ) )
			);
		}

		echo '</div>';
	}

	/**
	 * Short reason why a managed product has no calculated price yet.
	 *
	 * @param int    $post_id Product id.
	 * @param string $code    Stored currency code.
	 * @return string Empty when there is nothing to report.
	 */
	private function unpriced_reason( int $post_id, string $code ): string {
		// Formula mode: the last calculation error is the only useful reason.
		if ( ProductData::is_formula_mode( $post_id ) ) {
			$error = ProductData::last_error( $post_id );

			if ( '' !== $error ) {
				return $error;
			}

			$formula = ProductData::formula_id( $post_id );

			return $formula > 0 ? '' : __( 'No formula selected', 'ratebridge-woo' );
		}

		if ( '' === $code ) {
			return __( 'No currency selected', 'ratebridge-woo' );
		}

		$currency = ( new CurrencyRepository() )->get_by_code( $code );

		if ( ! $currency instanceof Currency ) {
			/* translators: %s: currency code */
			return sprintf( __( '%s is missing', 'ratebridge-woo' ), $code );
		}

		if ( ! $currency->is_enabled ) {
			return __( 'Currency disabled', 'ratebridge-woo' );
		}

		if ( ! $currency->has_rate() ) {
			return __( 'Currency has no rate', 'ratebridge-woo' );
		}

		$product = wc_get_product( $post_id );

		if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
			return '';
		}

		if ( ProductData::base_price( $post_id ) <= 0 ) {
			return __( 'No base price', 'ratebridge-woo' );
		}

		return '';
	}

	/**
	 * Currency code of the first managed variation of a variable product.
	 *
	 * @param int $product_id Parent product id.
	 * @return string
	 */
	private function first_variation_currency( int $product_id ): string {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return '';
		}

		if ( ProductData::is_formula_mode( $product_id ) ) {
			return __( 'formula', 'ratebridge-woo' );
		}

		$parent_code = ProductData::currency_code( $product_id );

		if ( '' !== $parent_code ) {
			return $parent_code;
		}

		foreach ( (array) $product->get_children() as $child_id ) {
			$code = ProductData::currency_code( (int) $child_id );

			if ( '' !== $code ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Add the "recalculate prices" bulk action.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( $actions ): array {
		if ( ! is_array( $actions ) || ! current_user_can( 'edit_products' ) ) {
			return is_array( $actions ) ? $actions : array();
		}

		$actions['rbw_reprice'] = __( 'Recalculate currency prices', 'ratebridge-woo' );

		return $actions;
	}

	/**
	 * Run the bulk action for the selected products.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action key.
	 * @param int[]  $post_ids    Selected product ids.
	 * @return string Redirect URL (with a result count).
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ): string {
		$redirect_to = (string) $redirect_to;

		if ( 'rbw_reprice' !== $action || ! current_user_can( 'edit_products' ) ) {
			return $redirect_to;
		}

		$repricer = new Repricer();
		$counts   = array(
			'repriced' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		foreach ( (array) $post_ids as $post_id ) {
			$product = wc_get_product( (int) $post_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$result = $repricer->reprice_product( $product );

			if ( 'repriced' === $result['status'] ) {
				++$counts['repriced'];
			} elseif ( 'failed' === $result['status'] ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		return add_query_arg(
			array(
				'rbw_repriced' => $counts['repriced'],
				'rbw_skipped'  => $counts['skipped'],
				'rbw_failed'   => $counts['failed'],
			),
			$redirect_to
		);
	}

	/**
	 * Show the outcome of the bulk recalculation.
	 *
	 * @return void
	 */
	public function render_bulk_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result counters.
		if ( ! isset( $_GET['rbw_repriced'] ) ) {
			return;
		}

		$repriced = absint( $_GET['rbw_repriced'] );
		$skipped  = isset( $_GET['rbw_skipped'] ) ? absint( $_GET['rbw_skipped'] ) : 0;
		$failed   = isset( $_GET['rbw_failed'] ) ? absint( $_GET['rbw_failed'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			$failed > 0 ? 'notice-warning' : 'notice-success',
			esc_html(
				sprintf(
					/* translators: 1: repriced count, 2: skipped count, 3: failed count */
					__( 'Recalculation finished: %1$d repriced, %2$d skipped (no usable currency or no base price), %3$d failed.', 'ratebridge-woo' ),
					$repriced,
					$skipped,
					$failed
				)
			)
		);
	}

	/**
	 * Print the icon sprite once on the product editor.
	 *
	 * @return void
	 */
	public function render_icon_sprite(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || 'product' !== $screen->post_type ) {
			return;
		}

		require RBW_PLUGIN_DIR . 'templates/icon-sprite.php';
	}

	/**
	 * Enqueue the product stylesheet (+ its RTL companion).
	 *
	 * Shared by the editor and the products list so the «ارز» column and the
	 * editor panel never drift apart.
	 *
	 * @return void
	 */
	private function enqueue_styles(): void {
		// Design tokens first (badges, chips, preview card all read them).
		wp_enqueue_style(
			'rbw-tokens',
			RBW_PLUGIN_URL . 'assets/admin/css/tokens.css',
			array(),
			RBW_VERSION
		);

		wp_enqueue_style(
			self::HANDLE,
			RBW_PLUGIN_URL . 'assets/admin/css/product.css',
			array( 'rbw-tokens' ),
			RBW_VERSION
		);

		if ( is_rtl() ) {
			wp_enqueue_style(
				self::HANDLE . '-rtl',
				RBW_PLUGIN_URL . 'assets/admin/css/product-rtl.css',
				array( self::HANDLE ),
				RBW_VERSION
			);
		}
	}

	/**
	 * Enqueue the product editor assets.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		$hook_suffix = (string) $hook_suffix;
		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || 'product' !== $screen->post_type ) {
			return;
		}

		/*
		 * The products list (edit.php) needs the stylesheet for the «ارز»
		 * column but none of the editor JS. Without it the column had no CSS at
		 * all and browsers broke "USD" one character per line.
		 */
		if ( 'edit.php' === $hook_suffix ) {
			$this->enqueue_styles();

			return;
		}

		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$this->enqueue_styles();

		wp_enqueue_script(
			self::HANDLE,
			RBW_PLUGIN_URL . 'assets/admin/js/product.js',
			array(),
			RBW_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'rbwProduct',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( AjaxController::NONCE_ACTION ),
				'action'  => AjaxController::PREVIEW_ACTION,
				'unit'    => SettingsPage::unit_label(),
				'storeUnit' => Settings::store_unit(),
				'strings' => array(
					'calcLabel'        => __( 'Calculated price', 'ratebridge-woo' ),
					'baseValue'        => __( 'Base value (price × rate)', 'ratebridge-woo' ),
					'profit'           => __( 'Profit', 'ratebridge-woo' ),
					'commission'       => __( 'Commission', 'ratebridge-woo' ),
					'extra'            => __( 'Extra amount', 'ratebridge-woo' ),
					'beforeMin'        => __( 'Total before the minimum', 'ratebridge-woo' ),
					'afterMin'         => __( 'After the minimum', 'ratebridge-woo' ),
					'rounded'          => __( 'After rounding', 'ratebridge-woo' ),
					'finalPrice'       => __( 'Final price', 'ratebridge-woo' ),
					'saleFinal'        => __( 'Final sale price', 'ratebridge-woo' ),
					'rate'             => __( 'Rate', 'ratebridge-woo' ),
					'minApplied'       => __( 'The minimum price was applied.', 'ratebridge-woo' ),
					'roundingApplied'  => __( 'The price was rounded.', 'ratebridge-woo' ),
					'fluctuationKept'  => __( 'Inside the fluctuation margin: the previous price is kept.', 'ratebridge-woo' ),
					'convertedProfit'  => __( 'The profit was converted from a percentage to an amount because the types differ.', 'ratebridge-woo' ),
					'convertedCommission' => __( 'The commission was converted from a percentage to an amount because the types differ.', 'ratebridge-woo' ),
					'working'          => __( 'Calculating…', 'ratebridge-woo' ),
					'error'            => __( 'The price could not be calculated. Check the currency rate and the base price.', 'ratebridge-woo' ),
					'readOnlyHint'     => __( 'Controlled by the currency', 'ratebridge-woo' ),
					'noCurrency'       => __( 'Select a currency first.', 'ratebridge-woo' ),
				),
			)
		);
	}

	/**
	 * Format a float for an input value ('' when zero).
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function number_to_string( float $value ): string {
		if ( $value <= 0 ) {
			return '';
		}

		$formatted = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '' : $formatted;
	}
}
