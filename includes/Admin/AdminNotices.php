<?php
/**
 * Admin notices.
 *
 * @package RateBridge
 */

namespace RateBridge\Admin;

use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Pricing\Repricer;

defined( 'ABSPATH' ) || exit;

/**
 * Renders admin notices (WooCommerce missing, stale rates, …).
 */
final class AdminNotices {

	/**
	 * User meta flag used to dismiss the stale-rate notice.
	 */
	public const DISMISS_META = 'rbw_dismiss_stale_notice';

	/**
	 * Register the notice hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render_stale_rate_notice' ) );
	}

	/**
	 * Notice shown when WooCommerce is not active.
	 *
	 * @return void
	 */
	public static function render_woocommerce_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'RateBridge Woo', 'ratebridge-woo' ),
			esc_html__( 'This plugin requires WooCommerce to be installed and active. Its features are disabled until WooCommerce is activated.', 'ratebridge-woo' )
		);
	}

	/**
	 * Currencies whose rate is older than the configured maximum age.
	 *
	 * @return Currency[]
	 */
	public static function stale_currencies(): array {
		$max_hours = Settings::max_rate_age_hours();

		if ( $max_hours <= 0 ) {
			return array();
		}

		$repricer = new Repricer();
		$stale    = array();

		foreach ( ( new CurrencyRepository() )->get_enabled() as $currency ) {
			if ( $currency->has_rate() && $repricer->is_stale( $currency ) ) {
				$stale[] = $currency;
			}
		}

		return $stale;
	}

	/**
	 * Render the dismissible stale-rate notice on the plugin page and the
	 * products screen.
	 *
	 * @return void
	 */
	public static function render_stale_rate_notice(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		$is_plugin_page = 'woocommerce_page_' . SettingsPage::SLUG === $screen->id;
		$is_products    = 'edit-product' === $screen->id;

		if ( ! $is_plugin_page && ! $is_products ) {
			return;
		}

		// Dismissal is per user, so one admin hiding it does not hide it for others.
		if ( (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === (int) Settings::max_rate_age_hours() ) {
			return;
		}

		$stale = self::stale_currencies();

		if ( empty( $stale ) ) {
			return;
		}

		$names = array();

		foreach ( $stale as $currency ) {
			$names[] = sprintf( '%s (%s)', $currency->code, SettingsPage::format_datetime( $currency->last_updated ) );
		}

		printf(
			'<div class="notice notice-warning is-dismissible rbw-stale-notice" data-rbw-dismiss="stale">
				<p><strong>%s</strong> %s</p>
				<p>%s <a href="%s">%s</a></p>
			</div>',
			esc_html__( 'RateBridge Woo: stale exchange rates', 'ratebridge-woo' ),
			esc_html(
				sprintf(
					/* translators: 1: hours, 2: currency list */
					__( 'These currencies have not been updated for more than %1$d hours, so product prices are not being recalculated: %2$s', 'ratebridge-woo' ),
					(int) Settings::max_rate_age_hours(),
					implode( ', ', $names )
				)
			),
			esc_html__( 'Prices are kept as they are until a fresh rate arrives. You can change the limit in the settings.', 'ratebridge-woo' ),
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=general' ) ),
			esc_html__( 'General settings', 'ratebridge-woo' )
		);
	}

	/**
	 * Persist the dismissal for the current user.
	 *
	 * Called from AjaxController::dismiss_stale_notice(), which already checked
	 * the nonce and the capability.
	 *
	 * @return void
	 */
	public static function dismiss_stale_notice(): void {
		update_user_meta( get_current_user_id(), self::DISMISS_META, (int) Settings::max_rate_age_hours() );

		wp_send_json_success( array( 'message' => __( 'Notice dismissed.', 'ratebridge-woo' ) ) );
	}
}
