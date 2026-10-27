<?php
/**
 * Elementor integration bootstrap.
 *
 * Loaded only when Elementor is active. Registers the «نرخ ساز» widget category
 * and the widget classes, and shows a dismissible notice when Elementor is too
 * old for the 3.5+ widget API.
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor;

use RateBridge\Admin\SettingsPage;
use RateBridge\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the widgets into Elementor.
 */
final class ElementorIntegration {

	/**
	 * Minimum supported Elementor version (widget registration API).
	 */
	public const MIN_VERSION = '3.5.0';

	/**
	 * Widget category slug.
	 */
	public const CATEGORY = 'ratebridge-woo';

	/**
	 * User meta flag: the "Elementor too old" notice was dismissed.
	 */
	public const DISMISS_META = 'rbw_dismiss_elementor_notice';

	/**
	 * Widget class names (without namespace).
	 *
	 * Order matters: it is the order shown in the Elementor panel.
	 *
	 * @return string[]
	 */
	public static function widget_classes(): array {
		return array(
			'RatesTable',
			'Ticker',
			'CurrencyRate',
			'ProductCurrency',
			'ProductPrice',
			'ProductUpdated',
			'SiteUpdated',
		);
	}

	/**
	 * Register the WordPress hooks.
	 *
	 * Runs only after `elementor/loaded`, so Elementor's classes are guaranteed
	 * to exist when the widget base class is loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! self::is_supported() ) {
			add_action( 'admin_notices', array( $this, 'render_version_notice' ) );
			return;
		}

		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

		/*
		 * Elementor caches each document's rendered HTML, so a page could keep an
		 * old rate for its whole TTL. The invalidator makes rate changes flush
		 * that cache; the widgets themselves stay dynamic (server-side render on
		 * every cache miss, no markup stored by the plugin).
		 */
		( new CacheInvalidator() )->register();
	}

	/**
	 * Whether Elementor is active and new enough.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return defined( 'ELEMENTOR_VERSION' ) && version_compare( (string) ELEMENTOR_VERSION, self::MIN_VERSION, '>=' );
	}

	/**
	 * Register the «نرخ ساز» category with its own icon.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'RateBridge', 'ratebridge-woo' ),
				'icon'  => 'eicon-nerd-chuckle', // Elementor's own icon font.
			)
		);
	}

	/**
	 * Register every widget with the 3.5+ API.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ): void {
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		// Tariff CSS must be available in the editor preview as well.
		Frontend::register_styles();

		foreach ( self::widget_classes() as $class_name ) {
			$class = __NAMESPACE__ . '\\Widgets\\' . $class_name;

			if ( ! class_exists( $class ) ) {
				continue;
			}

			$widgets_manager->register( new $class() );
		}
	}

	/**
	 * Notice shown on the plugin page when Elementor is too old.
	 *
	 * @return void
	 */
	public function render_version_notice(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || 'woocommerce_page_' . SettingsPage::SLUG !== $screen->id ) {
			return;
		}

		if ( (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'RateBridge Woo', 'ratebridge-woo' ),
			esc_html(
				sprintf(
					/* translators: 1: minimum version, 2: current version */
					__( 'The Elementor widgets need Elementor %1$s or newer. The installed version is %2$s, so the widgets are not available. Shortcodes keep working.', 'ratebridge-woo' ),
					self::MIN_VERSION,
					defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : __( 'unknown', 'ratebridge-woo' )
				)
			)
		);
	}
}
