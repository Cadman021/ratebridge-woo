<?php
/**
 * Admin screen: WooCommerce > Currency Prices.
 *
 * @package RateBridge
 */

namespace RateBridge\Admin;

use RateBridge\Core\Logger;
use RateBridge\Core\Settings;
use RateBridge\Cron\Scheduler;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Providers\ProviderRegistry;
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the settings page with two tabs.
 */
final class SettingsPage {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'ratebridge-woo';

	/**
	 * Capability required to view/use the page.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . RBW_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Currency Prices', 'ratebridge-woo' ),
			__( 'Currency Prices', 'ratebridge-woo' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_page' )
		);
	}
	/**
	 * Add a settings link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function add_action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'ratebridge-woo' ) )
		);

		return $links;
	}

	/**
	 * Enqueue CSS/JS on the plugin screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		/*
		 * The stale-rate notice also appears on the Products screen. admin.js
		 * only needs the AJAX config there (to persist the dismissal), so it is
		 * enqueued with a trimmed config instead of the whole page bundle.
		 */
		if ( 'edit.php' === $hook_suffix ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( ! $screen instanceof \WP_Screen || 'product' !== $screen->post_type ) {
				return;
			}

			wp_enqueue_style(
				'rbw-tokens',
				RBW_PLUGIN_URL . 'assets/admin/css/tokens.css',
				array(),
				RBW_VERSION
			);

			wp_enqueue_script(
				'rbw-admin',
				RBW_PLUGIN_URL . 'assets/admin/js/admin.js',
				array(),
				RBW_VERSION,
				true
			);

			wp_localize_script(
				'rbw-admin',
				'rbwAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( AjaxController::NONCE_ACTION ),
					'strings' => array(
						'genericError'         => __( 'Something went wrong. Please try again.', 'ratebridge-woo' ),
						'requestNotRecognised' => __( 'The server did not recognise this request (HTTP %d). Please refresh the page.', 'ratebridge-woo' ),
					),
				)
			);

			return;
		}

		if ( 'woocommerce_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		// Design tokens first: every other stylesheet depends on them.
		wp_enqueue_style(
			'rbw-tokens',
			RBW_PLUGIN_URL . 'assets/admin/css/tokens.css',
			array(),
			RBW_VERSION
		);

		wp_enqueue_style(
			'rbw-admin',
			RBW_PLUGIN_URL . 'assets/admin/css/admin.css',
			array( 'rbw-tokens' ),
			RBW_VERSION
		);

		/*
		 * RTL: admin.css is direction-agnostic (logical properties), so it must
		 * always load. admin-rtl.css only adds RTL-specific overrides.
		 *
		 * Do NOT use wp_style_add_data( ..., 'rtl', 'replace' ) here: "replace"
		 * makes WordPress load admin-rtl.css INSTEAD of admin.css on RTL locales,
		 * which dropped every base rule (modal positioning, grid, flex).
		 */
		if ( is_rtl() ) {
			wp_enqueue_style(
				'rbw-admin-rtl',
				RBW_PLUGIN_URL . 'assets/admin/css/admin-rtl.css',
				array( 'rbw-admin' ),
				RBW_VERSION
			);
		}

		wp_enqueue_script(
			'rbw-admin',
			RBW_PLUGIN_URL . 'assets/admin/js/admin.js',
			array(),
			RBW_VERSION,
			true
		);

		wp_localize_script(
			'rbw-admin',
			'rbwAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( AjaxController::NONCE_ACTION ),
				'minInterval' => Scheduler::min_interval(),
				'storeUnit'   => Settings::store_unit(),
				'storeUnitLabel' => self::unit_label(),
				'symbols'     => ProviderRegistry::instance()->all_known_symbols(),
				'providerLabels' => ProviderRegistry::instance()->choices(),
				'providerEffMin' => $this->effective_minimums(),
				'variableTypes'  => array(
					\RateBridge\Formula\FormulaModel::TYPE_CURRENCY => __( 'Currency rate', 'ratebridge-woo' ),
					\RateBridge\Formula\FormulaModel::TYPE_PRODUCT  => __( 'Product field', 'ratebridge-woo' ),
					\RateBridge\Formula\FormulaModel::TYPE_CUSTOM   => __( 'Custom value', 'ratebridge-woo' ),
				),
				'productFields'  => array(
					'weight' => __( 'Weight', 'ratebridge-woo' ),
					'length' => __( 'Length', 'ratebridge-woo' ),
					'width'  => __( 'Width', 'ratebridge-woo' ),
					'height' => __( 'Height', 'ratebridge-woo' ),
				),
				'currencyCodes'  => $this->currency_codes_for_js(),
				'usePersianDigits' => (bool) Settings::get( 'use_persian_digits', false ),
				'isRtl'          => (bool) is_rtl(),
				'strings'  => array(
					'confirmDelete' => __( 'Delete this currency? This cannot be undone.', 'ratebridge-woo' ),
					'confirmClear'  => __( 'Clear the update log?', 'ratebridge-woo' ),
					'saving'        => __( 'Saving…', 'ratebridge-woo' ),
					'working'       => __( 'Working…', 'ratebridge-woo' ),
					'genericError'  => __( 'Something went wrong. Please try again.', 'ratebridge-woo' ),
					'requestNotRecognised' => __( 'The server did not recognise this request (HTTP %d). Please refresh the page.', 'ratebridge-woo' ),
					'saveButton'    => __( 'Save', 'ratebridge-woo' ),
					'saveSettingsButton' => __( 'Save settings', 'ratebridge-woo' ),
					'addButton'     => __( 'Add', 'ratebridge-woo' ),
					'editLabel'     => __( 'Edit', 'ratebridge-woo' ),
					'deleteLabel'   => __( 'Delete', 'ratebridge-woo' ),
					'updateNowLabel' => __( 'Update now', 'ratebridge-woo' ),
					'manualLabel'   => __( 'Manual', 'ratebridge-woo' ),
					'cronLabel'     => __( 'Auto schedule', 'ratebridge-woo' ),
					'okLabel'       => __( 'OK', 'ratebridge-woo' ),
					'errorShort'    => __( 'Error', 'ratebridge-woo' ),
					'codeTaken'     => __( 'That code is already used. Suggested: %s', 'ratebridge-woo' ),
					'symbolHint'    => __( 'Pick a preset symbol or type a custom one. Leave empty to reuse the code.', 'ratebridge-woo' ),
					'editTitle'     => __( 'Edit currency', 'ratebridge-woo' ),
					'addTitle'      => __( 'Add currency', 'ratebridge-woo' ),
					'emptyRate'     => __( 'Keep current rate', 'ratebridge-woo' ),
					'confirmBudgetReset' => __( 'Reset this source’s request counters? This does not refund real requests already sent.', 'ratebridge-woo' ),
					'confirmPresetAdd'   => __( 'Add this currency from the source preset?', 'ratebridge-woo' ),
					'testSuccess'   => __( 'Connection successful', 'ratebridge-woo' ),
					'testFailed'    => __( 'Connection failed', 'ratebridge-woo' ),
					'searchSymbol'  => __( 'Search symbol…', 'ratebridge-woo' ),
					'customSymbol'  => __( 'Custom symbol (free text)', 'ratebridge-woo' ),
					'noSymbols'     => __( 'This source has no preset symbol list. Enter the symbol manually.', 'ratebridge-woo' ),
					'parsedCount'   => __( 'Parsed: %d', 'ratebridge-woo' ),
					'samplePrefix'  => __( 'Sample:', 'ratebridge-woo' ),
					'intervalTooShort' => __( 'This interval (%1$d minutes) is shorter than the source allows (%2$d minutes). The row will only refresh when the source permits it.', 'ratebridge-woo' ),
					'confirmUnitChange' => __( 'Changing the store unit multiplies or divides every stored rate and every fixed amount by 10, and all product prices will be recalculated. Continue?', 'ratebridge-woo' ),
					'unitChangeWarning' => __( 'Attention: this converts every stored rate, every fixed profit/commission/extra amount, product minimum prices, fluctuation margins and formula variables marked as money amounts, then recalculates all product prices. Numbers written directly inside a formula expression are NOT converted.', 'ratebridge-woo' ),
					'percentLabel'      => __( 'Percent', 'ratebridge-woo' ),
					'fixedLabel'        => __( 'Fixed amount', 'ratebridge-woo' ),
					// Formulas.
					'addFormula'        => __( 'Add formula', 'ratebridge-woo' ),
					'editFormula'       => __( 'Edit formula', 'ratebridge-woo' ),
					'saveFormula'       => __( 'Save formula', 'ratebridge-woo' ),
					'calculate'         => __( 'Calculate', 'ratebridge-woo' ),
					'formulaValid'      => __( 'The formula is valid.', 'ratebridge-woo' ),
					'formulaInvalid'    => __( 'The formula is not valid.', 'ratebridge-woo' ),
					'unusedVars'        => __( 'Unused variables: %s', 'ratebridge-woo' ),
					'createMissing'     => __( 'Create missing variables', 'ratebridge-woo' ),
					'noVarsYet'         => __( 'No variables declared yet.', 'ratebridge-woo' ),
					'defaultValue'      => __( 'Default', 'ratebridge-woo' ),
					'minValue'          => __( 'Min', 'ratebridge-woo' ),
					'maxValue'          => __( 'Max', 'ratebridge-woo' ),
					'isMoney'           => __( 'Amount in store unit', 'ratebridge-woo' ),
					'liveRate'          => __( 'live rate', 'ratebridge-woo' ),
					'testValue'         => __( 'Result', 'ratebridge-woo' ),
					'confirmDeleteFormula' => __( 'Delete this formula?', 'ratebridge-woo' ),
					'confirmDetach'     => __( 'Products use this formula. Detach them (they switch back to currency mode) and delete it?', 'ratebridge-woo' ),
					// Import / export.
					'selectOneSection'  => __( 'Select at least one section to export.', 'ratebridge-woo' ),
					'chooseFile'        => __( 'Choose a file first.', 'ratebridge-woo' ),
					'section'           => __( 'Section', 'ratebridge-woo' ),
					'create'            => __( 'New', 'ratebridge-woo' ),
					'update'            => __( 'Update', 'ratebridge-woo' ),
					'skip'              => __( 'Skip', 'ratebridge-woo' ),
					'invalid'           => __( 'Invalid', 'ratebridge-woo' ),
					'item'              => __( 'Item', 'ratebridge-woo' ),
					'action'            => __( 'Action', 'ratebridge-woo' ),
					'oldValue'          => __( 'Before', 'ratebridge-woo' ),
					'newValue'          => __( 'After', 'ratebridge-woo' ),
					'nothingToDo'       => __( 'Nothing to import.', 'ratebridge-woo' ),
					'unitDiffers'       => __( 'The package was exported in a different store unit (%s). Money amounts are converted when you keep the conversion option on.', 'ratebridge-woo' ),
					'confirmImport'     => __( 'Import this package now? A backup is created first.', 'ratebridge-woo' ),
					'confirmRestore'    => __( 'Restore this backup? The current settings, currencies and formulas are replaced (and backed up first).', 'ratebridge-woo' ),
					'restore'           => __( 'Restore', 'ratebridge-woo' ),
					'noBackups'         => __( 'No backups yet.', 'ratebridge-woo' ),
					'rowsRead'          => __( '%d rows read.', 'ratebridge-woo' ),
					'validInvalid'      => __( 'Valid: %1$d, invalid: %2$d.', 'ratebridge-woo' ),
					'row'               => __( 'Row', 'ratebridge-woo' ),
					// UI shell / toasts / confirm.
					'dismiss'           => __( 'Dismiss', 'ratebridge-woo' ),
					'cancelLabel'       => __( 'Cancel', 'ratebridge-woo' ),
					'importNow'         => __( 'Import now', 'ratebridge-woo' ),
					'showingCount'      => __( '%1$d of %2$d', 'ratebridge-woo' ),
					'confirmDiscard'    => __( 'You have unsaved changes. Close without saving?', 'ratebridge-woo' ),
				),
			)
		);
	}

	/**
	 * Currency codes + labels for the formula variable editor.
	 *
	 * @return array<int, array{code:string,label:string}>
	 */
	private function currency_codes_for_js(): array {
		$out = array();

		foreach ( ( new CurrencyRepository() )->get_all() as $currency ) {
			$out[] = array(
				'code'  => $currency->code,
				'label' => sprintf( '%s — %s', $currency->code, $currency->name ),
			);
		}

		return $out;
	}

	/**
	 * Effective minimum interval (minutes) per provider, for the JS warning.
	 *
	 * @return array<string, int>
	 */
	private function effective_minimums(): array {
		$map = array();

		foreach ( ProviderRegistry::instance()->all() as $key => $provider ) {
			$map[ $key ] = $provider instanceof \RateBridge\Providers\AbstractProvider
				? $provider->get_effective_min_interval_minutes()
				: 0;
		}

		return $map;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ratebridge-woo' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = in_array( $tab, array( 'overview', 'currencies', 'formulas', 'sources', 'general', 'import-export' ), true ) ? $tab : 'overview';

		$repo      = new CurrencyRepository();
		$registry  = ProviderRegistry::instance();
		$service   = new ProviderSettings( $registry );
		$cards     = array();

		foreach ( $registry->with_settings() as $provider ) {
			$cards[ $provider->get_key() ] = $service->card_model( $provider );
		}

		$formula_repo = new FormulaRepository();
		$formulas     = array();

		foreach ( $formula_repo->get_all() as $formula ) {
			$formulas[] = array(
				'formula'  => $formula,
				'products' => $formula_repo->count_products_using( $formula->id ),
			);
		}

		// Overview aggregates existing data only (KPIs, provider health, attention list).
		$rbw_overview = ( new Overview() )->model();

		// Templates receive $rbw_view in scope.
		$rbw_view = array(
			'overview'     => $rbw_overview,
			'health'       => $rbw_overview['health'],
			'currencies'   => $repo->get_all(),
			'formulas'     => $formulas,
			'variable_types' => array(
				FormulaModel::TYPE_CURRENCY => __( 'Currency rate', 'ratebridge-woo' ),
				FormulaModel::TYPE_PRODUCT  => __( 'Product field', 'ratebridge-woo' ),
				FormulaModel::TYPE_CUSTOM   => __( 'Custom value', 'ratebridge-woo' ),
			),
			'product_fields' => array(
				'weight' => __( 'Weight', 'ratebridge-woo' ),
				'length' => __( 'Length', 'ratebridge-woo' ),
				'width'  => __( 'Width', 'ratebridge-woo' ),
				'height' => __( 'Height', 'ratebridge-woo' ),
			),
			'settings'     => Settings::all(),
			'providers'    => $registry->choices(),
			'symbols'      => $registry->all_known_symbols(),
			'cards'        => $cards,
			'log'          => Logger::all(),
			'next_run'     => Scheduler::next_run(),
			'min_interval' => Scheduler::min_interval(),
			'active_tab'   => $tab,
		);

		require RBW_PLUGIN_DIR . 'templates/layout.php';
	}

	/**
	 * Format a currency rate for display.
	 *
	 * @param Currency $currency Currency.
	 * @return string
	 */
	public static function format_rate( Currency $currency ): string {
		if ( ! $currency->has_rate() ) {
			return '—';
		}

		$decimals = $currency->rate >= 1000 ? 0 : 4;

		return number_format_i18n( $currency->rate, $decimals );
	}

	/**
	 * Format a number for admin display (ASCII separators, store decimals).
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Decimals (defaults to the store precision).
	 * @return string
	 */
	public static function format_number( float $value, int $decimals = -1 ): string {
		$decimals = $decimals < 0 ? Settings::store_decimals() : $decimals;

		return \RateBridge\Frontend\RateFormatter::number( $value, $decimals );
	}

	/**
	 * Unit label for display (Rial/Toman).
	 *
	 * @return string
	 */
	public static function unit_label(): string {
		return 'rial' === Settings::store_unit()
			? __( 'Rial', 'ratebridge-woo' )
			: __( 'Toman', 'ratebridge-woo' );
	}

	/**
	 * Format a stored UTC datetime for display in site time.
	 *
	 * @param string|null $utc_datetime UTC datetime string.
	 * @return string
	 */
	public static function format_datetime( ?string $utc_datetime ): string {
		if ( empty( $utc_datetime ) ) {
			return '—';
		}

		$timestamp = strtotime( $utc_datetime . ' UTC' );

		if ( false === $timestamp ) {
			return '—';
		}

		return wp_date( 'Y-m-d H:i', $timestamp );
	}
}
