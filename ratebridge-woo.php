<?php
/**
 * Plugin Name:       نرخ ساز
 * Plugin URI:        https://example.com/ratebridge-woo
 * Description:       قیمت محصولات را به ارز یا کالای خارجی (دلار، یورو، طلا، سکه و…) تعریف کنید و قیمت تومانی/ریالی فروشگاه را به‌صورت خودکار از نرخ‌های روز به‌روزرسانی کنید.
 * Version:           0.5.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.5
 * Author:            RateBridge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ratebridge-woo
 * Domain Path:       /languages
 *
 * @package RateBridge
 */

namespace RateBridge;

defined( 'ABSPATH' ) || exit;

define( 'RBW_VERSION', '0.5.1' );
define( 'RBW_PLUGIN_FILE', __FILE__ );
define( 'RBW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RBW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RBW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Lightweight PSR-4 autoloader: RateBridge\Foo\Bar => includes/Foo/Bar.php
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'RateBridge\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );
		$path     = RBW_PLUGIN_DIR . 'includes/' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded, so WooCommerce can be detected.
 *
 * @return void
 */
function rbw_bootstrap(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', array( Admin\AdminNotices::class, 'render_woocommerce_missing' ) );
		return;
	}

	Core\Plugin::instance()->boot();
}

if ( did_action( 'plugins_loaded' ) ) {
	// Loaded late (e.g. from another plugin or a CLI harness): boot immediately.
	rbw_bootstrap();
} else {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\rbw_bootstrap' );
}

/**
 * Declare compatibility with WooCommerce HPOS (custom order tables).
 *
 * @return void
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RBW_PLUGIN_FILE, true );
	}
);

/**
 * Activation callback.
 *
 * @return void
 */
function rbw_activate(): void {
	Core\Installer::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\rbw_activate' );

/**
 * Deactivation callback.
 *
 * @return void
 */
function rbw_deactivate(): void {
	Core\Installer::deactivate();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\rbw_deactivate' );
