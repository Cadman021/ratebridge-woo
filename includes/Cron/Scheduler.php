<?php
/**
 * Cron scheduling.
 *
 * @package RateBridge
 */

namespace RateBridge\Cron;

use RateBridge\Core\Settings;
use RateBridge\Pricing\RateUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the custom schedule and the main rate update event.
 */
final class Scheduler {

	/**
	 * Custom schedule name (5 minutes; WordPress does not allow less).
	 */
	public const SCHEDULE = 'rbw_every_five_minutes';

	/**
	 * Main cron hook.
	 */
	public const HOOK = 'rbw_update_rates';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Add the 5-minute schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (RateBridge)', 'ratebridge-woo' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule the main event if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Remove the main event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}

		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run(): void {
		// Defensive: never run if the table is missing.
		global $wpdb;
		$table = $wpdb->prefix . 'rbw_currencies';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		( new RateUpdater() )->update_due( 'cron' );
	}

	/**
	 * Whether the event is currently scheduled.
	 *
	 * @return bool
	 */
	public static function is_scheduled(): bool {
		return false !== wp_next_scheduled( self::HOOK );
	}

	/**
	 * Next scheduled timestamp (0 when not scheduled).
	 *
	 * @return int
	 */
	public static function next_run(): int {
		return (int) wp_next_scheduled( self::HOOK );
	}

	/**
	 * Human-readable minimum interval, for the UI.
	 *
	 * @return int
	 */
	public static function min_interval(): int {
		return Settings::min_interval();
	}
}
