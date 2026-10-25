<?php
/**
 * Plugin bootstrap.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

use RateBridge\Admin\AjaxController;
use RateBridge\Admin\SettingsPage;
use RateBridge\Cron\Scheduler;

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
