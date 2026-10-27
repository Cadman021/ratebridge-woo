<?php
/**
 * Plugin bootstrap.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

use RateBridge\Admin\AdminNotices;
use RateBridge\Admin\AjaxController;
use RateBridge\Admin\SettingsPage;
use RateBridge\Cron\Scheduler;
use RateBridge\Frontend\Frontend;
use RateBridge\Frontend\ProductNotice;
use RateBridge\Frontend\Shortcodes;
use RateBridge\Orders\OrderRates;
use RateBridge\Pricing\RepriceJob;
use RateBridge\Product\ProductFields;

defined( 'ABSPATH' ) || exit;

/**
 * Wires everything together and guards against double bootstrapping.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Scheduler instance (kept to avoid re-instantiating).
	 *
	 * @var Scheduler|null
	 */
	private ?Scheduler $scheduler = null;

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bootstrap the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		Installer::maybe_upgrade();

		$this->scheduler = new Scheduler();
		$this->scheduler->register();
		Scheduler::schedule();

		$ajax = new AjaxController();
		$ajax->register();

		$page = new SettingsPage();
		$page->register();

		// Phase 3: product pricing, repricing on rate change.
		$job = new RepriceJob();
		$job->register();

		/*
		 * A currency that lost its rate because its source changed gets ONE
		 * immediate, budget-respecting fetch attempt.
		 */
		add_action(
			'rbw_currency_rate_pending',
			static function ( $currency_id ) {
				( new \RateBridge\Pricing\RateUpdater() )->on_rate_pending( $currency_id );
			},
			10,
			1
		);

		add_action( 'rbw_rate_updated', array( $this, 'on_rate_updated' ), 10, 3 );

		/*
		 * Elementor widgets.
		 *
		 * `elementor/loaded` fires on `plugins_loaded` priority 0, so by the time
		 * this runs Elementor is either present (hook already fired → register now)
		 * or absent. Registering through the integration class keeps every
		 * Elementor class reference out of this file, so nothing fatals when the
		 * plugin is deactivated.
		 */
		if ( did_action( 'elementor/loaded' ) ) {
			( new \RateBridge\Elementor\ElementorIntegration() )->register();
		} else {
			add_action(
				'elementor/loaded',
				static function () {
					( new \RateBridge\Elementor\ElementorIntegration() )->register();
				},
				20
			);
		}

		$fields = new ProductFields();
		$fields->register();

		AdminNotices::register();

		// Phase 4: order rate records + frontend output.
		$orders = new OrderRates();
		$orders->register();

		$shortcodes = new Shortcodes();
		$shortcodes->register();

		$notice = new ProductNotice();
		$notice->register();

		// Rendered fragments embed rates: drop them when a rate or a currency changes.
		add_action( 'rbw_rate_updated', array( Frontend::class, 'flush_cache' ), 5 );
		add_action( 'rbw_currency_changed', array( Frontend::class, 'flush_cache' ), 5 );
		add_action( 'updated_option', array( $this, 'on_settings_saved' ), 10, 3 );
	}

	/**
	 * Flush the frontend fragment cache when the plugin settings change.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public function on_settings_saved( $option, $old_value, $value ): void {
		unset( $old_value, $value );

		if ( Settings::OPTION === $option ) {
			Frontend::flush_cache();
		}
	}

	/**
	 * Enqueue ONE async repricing job when a rate really changed.
	 *
	 * @param string $currency_code Currency code.
	 * @param float  $old_rate      Previous rate.
	 * @param float  $new_rate      New rate.
	 * @return void
	 */
	public function on_rate_updated( $currency_code, $old_rate, $new_rate ): void {
		unset( $old_rate, $new_rate );

		$job = new RepriceJob();

		// 1. Products priced in that currency (base price × rate).
		$job->enqueue( (string) $currency_code );

		// 2. Products whose FORMULA references that currency.
		$job->enqueue_for_currency_formulas( (string) $currency_code );
	}

	/**
	 * Load translations (also supports WordPress.org language packs).
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'ratebridge-woo', false, dirname( RBW_PLUGIN_BASENAME ) . '/languages' );
	}
}
