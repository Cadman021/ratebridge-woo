<?php
/**
 * Minimal update-attempt log stored in an option (last 20 entries).
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last N rate-update attempts for the admin log view.
 */
final class Logger {

	/**
	 * Option name holding the log entries.
	 */
	public const OPTION = 'rbw_update_log';

	/**
	 * Maximum number of entries kept.
	 */
	public const MAX_ENTRIES = 20;

	/**
	 * Record one update attempt.
	 *
	 * @param array<string, mixed> $entry {
	 *     @type string $status    'success' or 'error'.
	 *     @type string $message   Human readable message.
	 *     @type array  $currencies Currency codes touched.
	 *     @type array  $rates      symbol => rate map returned by provider (optional).
	 *     @type string $trigger   'cron' or 'manual'.
	 * }
	 * @return void
	 */
	public static function add( array $entry ): void {
		$log = self::all();

		array_unshift(
			$log,
			array(
				'time'       => time(),
				'status'     => isset( $entry['status'] ) && 'success' === $entry['status'] ? 'success' : 'error',
				'message'    => isset( $entry['message'] ) ? (string) $entry['message'] : '',
				'currencies' => isset( $entry['currencies'] ) ? array_values( array_map( 'strval', (array) $entry['currencies'] ) ) : array(),
				'rates'      => isset( $entry['rates'] ) && is_array( $entry['rates'] ) ? $entry['rates'] : array(),
				'trigger'    => isset( $entry['trigger'] ) ? sanitize_key( (string) $entry['trigger'] ) : 'cron',
			)
		);

		$log = array_slice( $log, 0, self::MAX_ENTRIES );

		update_option( self::OPTION, $log, false );
	}

	/**
	 * All log entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear the log.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
