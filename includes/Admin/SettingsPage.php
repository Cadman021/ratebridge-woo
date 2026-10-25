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
		if ( 'woocommerce_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'rbw-admin',
			RBW_PLUGIN_URL . 'assets/admin/css/admin.css',
			array(),
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
				),
			)
		);
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

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'currencies'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = in_array( $tab, array( 'currencies', 'sources', 'general' ), true ) ? $tab : 'currencies';

		$repo      = new CurrencyRepository();
		$registry  = ProviderRegistry::instance();
		$service   = new ProviderSettings( $registry );
		$cards     = array();

		foreach ( $registry->with_settings() as $provider ) {
			$cards[ $provider->get_key() ] = $service->card_model( $provider );
		}

		// Templates receive $rbw_view in scope.
		$rbw_view = array(
			'currencies'   => $repo->get_all(),
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
