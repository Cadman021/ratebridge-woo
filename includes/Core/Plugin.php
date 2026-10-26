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

		add_action( 'rbw_rate_updated', array( $this, 'on_rate_updated' ), 10, 3 );

		$fields = new ProductFields();
		$fields->register();

		AdminNotices::register();
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
		$job->enqueue( (string) $currency_code );
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
