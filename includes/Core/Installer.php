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
	public const DB_VERSION = '1.1.0';

	/**
	 * Columns added by 1.1.0 (Phase 3 pricing settings).
	 *
	 * Listed so maybe_upgrade() can report what it added and so the schema is
	 * documented in one place.
	 */
	public const PRICING_COLUMNS = array(
		'profit_type'      => "varchar(16) NOT NULL DEFAULT 'percent'",
		'profit_value'     => 'decimal(30,10) NOT NULL DEFAULT 0.0000000000',
		'commission_type'  => "varchar(16) NOT NULL DEFAULT 'percent'",
		'commission_value' => 'decimal(30,10) NOT NULL DEFAULT 0.0000000000',
		'extra_amount'     => 'decimal(30,10) NOT NULL DEFAULT 0.0000000000',
	);

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
		self::backfill_pricing_columns();
		self::install_default_options();
		self::seed_currencies();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Make sure the 1.1.0 pricing columns exist and carry sane values.
	 *
	 * dbDelta() adds missing columns, but it does not change NULL defaults on
	 * tables created by an older version on some MySQL flavours, and existing
	 * rows may hold empty strings. This runs a single, idempotent UPDATE so
	 * existing rows behave exactly like freshly seeded ones.
	 *
	 * @return void
	 */
	private static function backfill_pricing_columns(): void {
		global $wpdb;

		$table  = self::currencies_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return;
		}

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( ! is_array( $columns ) ) {
			return;
		}

		foreach ( array_keys( self::PRICING_COLUMNS ) as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				continue;
			}

			// dbDelta missed it (should not happen, but never leave the schema broken).
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} " . self::PRICING_COLUMNS[ $column ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			"UPDATE {$table}
			 SET profit_type = CASE WHEN profit_type IN ('percent','fixed') THEN profit_type ELSE 'percent' END,
			     commission_type = CASE WHEN commission_type IN ('percent','fixed') THEN commission_type ELSE 'percent' END,
			     profit_value = CASE WHEN profit_value IS NULL OR profit_value < 0 THEN 0 ELSE profit_value END,
			     commission_value = CASE WHEN commission_value IS NULL OR commission_value < 0 THEN 0 ELSE commission_value END,
			     extra_amount = CASE WHEN extra_amount IS NULL OR extra_amount < 0 THEN 0 ELSE extra_amount END"
		);
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
			profit_type varchar(16) NOT NULL DEFAULT 'percent',
			profit_value decimal(30,10) NOT NULL DEFAULT 0.0000000000,
			commission_type varchar(16) NOT NULL DEFAULT 'percent',
			commission_value decimal(30,10) NOT NULL DEFAULT 0.0000000000,
			extra_amount decimal(30,10) NOT NULL DEFAULT 0.0000000000,
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
