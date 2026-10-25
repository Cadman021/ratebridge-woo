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

		wp_style_add_data( 'rbw-admin', 'rtl', 'replace' );

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
				'strings'  => array(
					'confirmDelete' => __( 'Delete this currency? This cannot be undone.', 'ratebridge-woo' ),
					'confirmClear'  => __( 'Clear the update log?', 'ratebridge-woo' ),
					'saving'        => __( 'Saving…', 'ratebridge-woo' ),
					'working'       => __( 'Working…', 'ratebridge-woo' ),
					'genericError'  => __( 'Something went wrong. Please try again.', 'ratebridge-woo' ),
					'editTitle'     => __( 'Edit currency', 'ratebridge-woo' ),
					'addTitle'      => __( 'Add currency', 'ratebridge-woo' ),
					'emptyRate'     => __( 'Keep current rate', 'ratebridge-woo' ),
				),
			)
		);
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
		$tab = in_array( $tab, array( 'currencies', 'general' ), true ) ? $tab : 'currencies';

		$repo = new CurrencyRepository();

		// Templates receive $rbw_view in scope.
		$rbw_view = array(
			'currencies'   => $repo->get_all(),
			'settings'     => Settings::all(),
			'providers'    => ProviderRegistry::instance()->choices(),
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
