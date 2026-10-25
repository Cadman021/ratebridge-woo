<?php
/**
 * Installer: table creation, default options and currency seeding.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

use RateBridge\Currency\CurrencyRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation/deactivation side effects.
 */
final class Installer {

	/**
	 * Option holding the installed DB schema version.
	 */
	public const DB_VERSION_OPTION = 'rbw_db_version';

	/**
	 * Current DB schema version.
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Activation: create tables, options and seed defaults.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::install_default_options();
		self::seed_currencies();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		// Ensure the cron event exists right after activation.
		if ( class_exists( '\RateBridge\Cron\Scheduler' ) ) {
			\RateBridge\Cron\Scheduler::schedule();
		}
	}

	/**
	 * Deactivation: clear scheduled events (data is preserved).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( class_exists( '\RateBridge\Cron\Scheduler' ) ) {
			\RateBridge\Cron\Scheduler::unschedule();
		}
	}

	/**
	 * Run pending schema upgrades on regular requests.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::create_tables();
		self::install_default_options();
		self::seed_currencies();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Table name for currencies.
	 *
	 * @return string
	 */
	public static function currencies_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rbw_currencies';
	}

	/**
	 * Create/upgrade the currencies table with dbDelta().
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::currencies_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(32) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			symbol varchar(32) NOT NULL DEFAULT '',
			provider varchar(64) NOT NULL DEFAULT 'manual',
			provider_symbol varchar(64) NOT NULL DEFAULT '',
			rate decimal(30,10) NOT NULL DEFAULT 0.0000000000,
			is_enabled tinyint(1) NOT NULL DEFAULT 0,
			update_interval_minutes int(11) NOT NULL DEFAULT 60,
			last_updated datetime DEFAULT NULL,
			last_error text NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY is_enabled (is_enabled),
			KEY provider (provider)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create the settings option with defaults if it does not exist yet.
	 *
	 * @return void
	 */
	public static function install_default_options(): void {
		$existing = get_option( Settings::OPTION );

		if ( ! is_array( $existing ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
			return;
		}

		update_option( Settings::OPTION, wp_parse_args( $existing, Settings::defaults() ) );
	}

	/**
	 * Seed a few well-known currencies (disabled) the first time only.
	 *
	 * @return void
	 */
	public static function seed_currencies(): void {
		global $wpdb;

		$table  = self::currencies_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return;
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $count > 0 ) {
			return;
		}

		$now  = current_time( 'mysql', true );
		$repo = new CurrencyRepository();

		$seeds = array(
			array(
				'code'            => 'USD',
				'name'            => __( 'US Dollar', 'ratebridge-woo' ),
				'symbol'          => '$',
				'provider'        => 'mock',
				'provider_symbol' => 'USD',
				'sort_order'      => 10,
			),
			array(
				'code'            => 'EUR',
				'name'            => __( 'Euro', 'ratebridge-woo' ),
				'symbol'          => '€',
				'provider'        => 'mock',
				'provider_symbol' => 'EUR',
				'sort_order'      => 20,
			),
			array(
				'code'            => 'AED',
				'name'            => __( 'UAE Dirham', 'ratebridge-woo' ),
				'symbol'          => 'د.إ',
				'provider'        => 'mock',
				'provider_symbol' => 'AED',
				'sort_order'      => 30,
			),
			array(
				'code'            => 'GOLD18',
				'name'            => __( '18K Gold (gram)', 'ratebridge-woo' ),
				'symbol'          => 'g',
				'provider'        => 'mock',
				'provider_symbol' => 'GOLD18',
				'sort_order'      => 40,
			),
		);

		foreach ( $seeds as $seed ) {
			$repo->create(
				array_merge(
					$seed,
					array(
						'rate'                    => 0,
						'is_enabled'              => 0,
						'update_interval_minutes' => (int) Settings::get( 'default_update_interval', 60 ),
						'created_at'              => $now,
						'updated_at'              => $now,
					)
				)
			);
		}
	}
}
