<?php
/**
 * Per-provider request quota guard.
 *
 * Free API tiers are small (Navasan ≈120 requests/month, AlanChand ≈1/hour), so
 * every real HTTP request must be authorised first. The budget is stored in a
 * non-autoloaded option:
 *
 *   rbw_request_budget = array(
 *       'navasan' => array(
 *           'last_request'      => 1792945862, // unix timestamp of last real request
 *           'last_status'       => 200,        // last HTTP status (0 = transport error)
 *           'count'             => 42,         // count in the current period
 *           'period'            => '2026-10',  // month key the count belongs to
 *           'last_failure'      => 0,          // unix timestamp of last failure
 *           'last_error'        => '',         // scrubbed message
 *       ),
 *   )
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks and enforces request quotas per provider.
 */
final class RequestBudget {

	/**
	 * Non-autoloaded option name.
	 */
	public const OPTION = 'rbw_request_budget';

	/**
	 * Minutes to wait after a failed request before hitting the provider again.
	 */
	public const FAILURE_BACKOFF_MINUTES = 15;

	/**
	 * Read the whole budget map.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return $stored;
	}

	/**
	 * Read one provider's budget state (with defaults).
	 *
	 * @param string $provider_key Provider key.
	 * @return array<string, mixed>
	 */
	public static function get( string $provider_key ): array {
		$all = self::all();

		$state = isset( $all[ $provider_key ] ) && is_array( $all[ $provider_key ] ) ? $all[ $provider_key ] : array();

		return wp_parse_args(
			$state,
			array(
				'last_request' => 0,
				'last_status'  => 0,
				'count'        => 0,
				'period'       => self::current_period(),
				'last_failure' => 0,
				'last_error'   => '',
			)
		);
	}

	/**
	 * Persist one provider's budget state.
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $state        State.
	 * @return void
	 */
	public static function save( string $provider_key, array $state ): void {
		$all = self::all();

		$all[ $provider_key ] = $state;

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Current period key (calendar month, site time).
	 *
	 * @return string e.g. "2026-10".
	 */
	public static function current_period(): string {
		return wp_date( 'Y-m' ) ?: gmdate( 'Y-m' );
	}

	/**
	 * Requests already used in the current month.
	 *
	 * Automatically treats a stale period as zero, so the counter resets on the
	 * first day of each month without a cron job.
	 *
	 * @param string $provider_key Provider key.
	 * @return int
	 */
	public static function used_this_period( string $provider_key ): int {
		$state = self::get( $provider_key );

		if ( (string) $state['period'] !== self::current_period() ) {
			return 0;
		}

		return max( 0, (int) $state['count'] );
	}

	/**
	 * Unix timestamp of the earliest moment a request may be made again.
	 *
	 * @param string $provider_key Provider key.
	 * @param int    $min_interval_minutes Provider's minimum minutes between requests.
	 * @param int    $monthly_limit        Provider's monthly request limit.
	 * @return int 0 when a request is allowed now.
	 */
	public static function next_allowed_at( string $provider_key, int $min_interval_minutes, int $monthly_limit ): int {
		$state = self::get( $provider_key );
		$now   = time();

		// Monthly quota exhausted: only resets with the next calendar month.
		if ( $monthly_limit > 0 && self::used_this_period( $provider_key ) >= $monthly_limit ) {
			$next_month = strtotime( wp_date( 'Y-m-01 H:i:s', $now ) . ' +1 month' );

			return $next_month ? (int) $next_month : $now + DAY_IN_SECONDS;
		}

		$candidates = array();

		// Minimum interval since the last real request.
		if ( $min_interval_minutes > 0 && (int) $state['last_request'] > 0 ) {
			$candidates[] = (int) $state['last_request'] + ( $min_interval_minutes * MINUTE_IN_SECONDS );
		}

		// Back-off after a failure.
		if ( (int) $state['last_failure'] > 0 ) {
			$candidates[] = (int) $state['last_failure'] + ( self::FAILURE_BACKOFF_MINUTES * MINUTE_IN_SECONDS );
		}

		$allowed_at = empty( $candidates ) ? 0 : max( $candidates );

		return $allowed_at > $now ? $allowed_at : 0;
	}

	/**
	 * Ask permission to make a real HTTP request.
	 *
	 * Returns true when the request may proceed. On refusal, returns a WP_Error
	 * with a message safe to show to the user (never contains secrets).
	 *
	 * @param string $provider_key         Provider key.
	 * @param string $provider_label       Translated provider label.
	 * @param int    $min_interval_minutes Minimum minutes between requests.
	 * @param int    $monthly_limit        Monthly limit (0 = unlimited).
	 * @return true|WP_Error
	 */
	public static function authorize( string $provider_key, string $provider_label, int $min_interval_minutes, int $monthly_limit ): true|WP_Error {
		$used = self::used_this_period( $provider_key );

		if ( $monthly_limit > 0 && $used >= $monthly_limit ) {
			return new WP_Error(
				'rbw_budget_monthly_exhausted',
				sprintf(
					/* translators: 1: provider label, 2: used count, 3: monthly limit */
					__( 'The monthly request limit for %1$s has been reached (%2$d of %3$d). No request was sent to the provider.', 'ratebridge-woo' ),
					$provider_label,
					$used,
					$monthly_limit
				)
			);
		}

		$next = self::next_allowed_at( $provider_key, $min_interval_minutes, $monthly_limit );

		if ( 0 === $next ) {
			return true;
		}

		$wait_minutes = max( 1, (int) ceil( ( $next - time() ) / MINUTE_IN_SECONDS ) );

		$state = self::get( $provider_key );

		$is_failure_wait = (int) $state['last_failure'] > 0
			&& ( time() - (int) $state['last_failure'] ) < self::FAILURE_BACKOFF_MINUTES * MINUTE_IN_SECONDS;

		if ( $is_failure_wait ) {
			return new WP_Error(
				'rbw_budget_failure_backoff',
				sprintf(
					/* translators: 1: provider label, 2: wait minutes */
					__( '%1$s failed recently. To protect the quota, the next request is allowed in %2$d minutes.', 'ratebridge-woo' ),
					$provider_label,
					$wait_minutes
				)
			);
		}

		return new WP_Error(
			'rbw_budget_interval',
			sprintf(
				/* translators: 1: provider label, 2: wait minutes */
				__( '%1$s may only be queried every so often. The next request is allowed in %2$d minutes.', 'ratebridge-woo' ),
				$provider_label,
				$wait_minutes
			)
		);
	}

	/**
	 * Record that a request actually left the server.
	 *
	 * Only call this after wp_remote_get() returned (success or HTTP error),
	 * never on cache hits.
	 *
	 * @param string $provider_key Provider key.
	 * @param int    $http_status  HTTP status code, 0 for transport failure.
	 * @param bool   $failed       Whether the request is considered a failure.
	 * @param string $error        Already-scrubbed error message.
	 * @return void
	 */
	public static function record( string $provider_key, int $http_status, bool $failed = false, string $error = '' ): void {
		$state = self::get( $provider_key );
		$now   = time();
		$month = self::current_period();

		// Reset the counter when the month rolled over.
		$count = ( (string) $state['period'] === $month ) ? (int) $state['count'] : 0;

		$state['period']       = $month;
		$state['count']        = $count + 1;
		$state['last_request'] = $now;
		$state['last_status']  = $http_status;

		if ( $failed ) {
			$state['last_failure'] = $now;
			$state['last_error']   = mb_substr( $error, 0, 500 );
		} else {
			$state['last_failure'] = 0;
			$state['last_error']   = '';
		}

		self::save( $provider_key, $state );
	}

	/**
	 * Reset one provider's budget (used by a manual "reset" action).
	 *
	 * @param string $provider_key Provider key.
	 * @return void
	 */
	public static function reset( string $provider_key ): void {
		$all = self::all();

		unset( $all[ $provider_key ] );

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Effective minimum interval, in minutes, derived from a monthly limit.
	 *
	 * Navasan's 120 requests/month ≈ one request every 6 hours.
	 *
	 * @param int $monthly_limit Monthly limit (0 = unlimited).
	 * @return int Minutes.
	 */
	public static function monthly_limit_to_minutes( int $monthly_limit ): int {
		if ( $monthly_limit <= 0 ) {
			return 0;
		}

		$minutes_in_month = (int) ceil( ( 365.25 / 12 ) * DAY_IN_SECONDS / MINUTE_IN_SECONDS );

		return (int) ceil( $minutes_in_month / $monthly_limit );
	}
}
