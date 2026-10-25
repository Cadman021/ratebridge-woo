<?php
/**
 * Admin notices.
 *
 * @package RateBridge
 */

namespace RateBridge\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders admin notices (WooCommerce missing, RTL hint, etc.).
 */
final class AdminNotices {

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
}
