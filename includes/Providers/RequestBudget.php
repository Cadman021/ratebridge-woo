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
 *           'count'             => 42,         // count in the current month period
 *           'period'            => '2026-10',  // month key the count belongs to
 *           'day_count'         => 7,          // count in the current day (site timezone)
 *           'day'               => '2026-10-29',// site-timezone date the day count belongs to
 *           'last_failure'      => 0,          // unix timestamp of last failure
 *           'last_error'        => '',         // scrubbed message, ROOT CAUSE first
 *           'auth_failed'       => false,      // 401/403 seen with the current credentials
 *           'auth_status'       => 0,          // HTTP status of that failure
 *           'auth_message'      => '',         // Persian message shown to the admin
 *           'auth_fingerprint'  => 'sha256…',  // hash of the credential settings
 *           'source_updated'    => 0,          // provider's own "as of" timestamp (optional)
 *       ),
 *   )
 *
 * `auth_failed` is sticky: automatic retries stop until the credentials change
 * (the fingerprint no longer matches) or the admin runs a manual test / reset.
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
				'last_request'     => 0,
				'last_status'      => 0,
				'count'            => 0,
				'period'           => self::current_period(),
				'day_count'        => 0,
				'day'              => self::current_day(),
				'last_failure'     => 0,
				'last_error'       => '',
				'auth_failed'      => false,
				'auth_status'      => 0,
				'auth_message'     => '',
				'auth_fingerprint' => '',
				'source_updated'   => 0,
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
	 * Current day key in the SITE timezone (`Y-m-d`).
	 *
	 * The daily limit is a calendar concept for the admin (midnight in Tehran),
	 * not a rolling 24h window, so it resets at 00:00 site time.
	 *
	 * @return string
	 */
	public static function current_day(): string {
		return wp_date( 'Y-m-d' ) ?: gmdate( 'Y-m-d' );
	}

	/**
	 * Requests already used today (site timezone).
	 *
	 * @param string $provider_key Provider key.
	 * @return int
	 */
	public static function used_today( string $provider_key ): int {
		$state = self::get( $provider_key );

		if ( (string) $state['day'] !== self::current_day() ) {
			return 0;
		}

		return max( 0, (int) $state['day_count'] );
	}

	/* ------------------------------------------------------------------ */
	/* Credential / auth state                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Fingerprint of a provider's credential settings.
	 *
	 * Only secret-shaped fields take part (key/token/secret/pass/auth), so
	 * changing an unrelated setting does not clear an auth failure. Returns ''
	 * when the provider has no secrets.
	 *
	 * @param array<string, mixed> $settings Provider settings.
	 * @return string
	 */
	public static function fingerprint( array $settings ): string {
		$parts = array();

		foreach ( $settings as $key => $value ) {
			$key = strtolower( (string) $key );

			if ( ! preg_match( '/key|token|secret|pass|auth|bearer/', $key ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$parts[ $key ] = (string) $value;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		ksort( $parts );

		return hash( 'sha256', (string) wp_json_encode( $parts ) );
	}

	/**
	 * Mark a provider as needing valid credentials (HTTP 401/403).
	 *
	 * Automatic retries stop while the fingerprint matches, so an expired token
	 * cannot burn the quota or flood the log.
	 *
	 * @param string $provider_key Provider key.
	 * @param int    $status       HTTP status (401/403).
	 * @param string $message      Persian, already scrubbed.
	 * @param string $fingerprint  Credential fingerprint at the time of failure.
	 * @return void
	 */
	public static function set_auth_failed( string $provider_key, int $status, string $message, string $fingerprint = '' ): void {
		$state = self::get( $provider_key );

		$state['auth_failed']      = true;
		$state['auth_status']      = $status;
		$state['auth_message']     = mb_substr( $message, 0, 500 );
		$state['auth_fingerprint'] = $fingerprint;
		$state['last_status']      = $status;
		$state['last_error']       = mb_substr( $message, 0, 500 );

		self::save( $provider_key, $state );
	}

	/**
	 * Clear the auth failure (credentials changed, manual test succeeded, reset).
	 *
	 * @param string $provider_key Provider key.
	 * @return void
	 */
	public static function clear_auth_failed( string $provider_key ): void {
		$state = self::get( $provider_key );

		if ( empty( $state['auth_failed'] ) && 0 === (int) $state['auth_status'] ) {
			return;
		}

		$state['auth_failed']      = false;
		$state['auth_status']      = 0;
		$state['auth_message']     = '';
		$state['auth_fingerprint'] = '';

		self::save( $provider_key, $state );
	}

	/**
	 * Whether the provider is blocked on credentials for the given settings.
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $settings     Current provider settings.
	 * @return bool
	 */
	public static function is_auth_failed( string $provider_key, array $settings = array() ): bool {
		$state = self::get( $provider_key );

		if ( empty( $state['auth_failed'] ) ) {
			return false;
		}

		$stored = (string) $state['auth_fingerprint'];

		if ( '' === $stored ) {
			return true;
		}

		$current = self::fingerprint( $settings );

		// Credentials changed since the failure: the block no longer applies.
		if ( '' !== $current && $current !== $stored ) {
			self::clear_auth_failed( $provider_key );

			return false;
		}

		return true;
	}

	/**
	 * Store the provider's own "as of" timestamp (e.g. Netarz meta.as_of).
	 *
	 * @param string $provider_key Provider key.
	 * @param int    $timestamp    Unix timestamp (0 clears it).
	 * @return void
	 */
	public static function set_source_updated( string $provider_key, int $timestamp ): void {
		$state = self::get( $provider_key );

		if ( (int) $state['source_updated'] === $timestamp ) {
			return;
		}

		$state['source_updated'] = $timestamp;

		self::save( $provider_key, $state );
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
	public static function authorize( string $provider_key, string $provider_label, int $min_interval_minutes, int $monthly_limit, int $daily_limit = 0, array $settings = array() ): true|WP_Error {
		/*
		 * 1. Credentials first: an expired key must be reported as such, never as
		 *    a quota problem. Nothing is retried while the credentials are the same.
		 */
		if ( self::is_auth_failed( $provider_key, $settings ) ) {
			$state = self::get( $provider_key );

			return new WP_Error(
				'rbw_provider_auth_failed',
				'' !== (string) $state['auth_message']
					? (string) $state['auth_message']
					: sprintf(
						/* translators: %s: provider label */
						__( '%s rejected the credentials. Fix the API key or token, then run “Test connection”.', 'ratebridge-woo' ),
						$provider_label
					)
			);
		}

		// 2. Daily limit (site-timezone calendar day).
		$used_today = self::used_today( $provider_key );

		if ( $daily_limit > 0 && $used_today >= $daily_limit ) {
			return new WP_Error(
				'rbw_budget_daily_exhausted',
				sprintf(
					/* translators: 1: provider label, 2: used today, 3: daily limit, 4: reset time */
					__( 'The daily request limit for %1$s has been reached (%2$d of %3$d). It resets at midnight (%4$s). No request was sent to the provider.', 'ratebridge-woo' ),
					$provider_label,
					$used_today,
					$daily_limit,
					wp_date( 'Y-m-d 00:00' )
				)
			);
		}

		// 3. Monthly limit.
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

		/*
		 * Root cause first: when we know WHY the last attempt failed (a real
		 * provider message), it is prepended to the generic backoff note instead
		 * of being replaced by it.
		 */
		$root_cause = trim( (string) $state['last_error'] );

		if ( $is_failure_wait ) {
			$backoff = sprintf(
				/* translators: 1: provider label, 2: wait minutes */
				__( '%1$s failed recently. To protect the quota, the next request is allowed in %2$d minutes.', 'ratebridge-woo' ),
				$provider_label,
				$wait_minutes
			);

			return new WP_Error(
				'rbw_budget_failure_backoff',
				'' !== $root_cause ? $root_cause . ' — ' . $backoff : $backoff
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

		// Reset the month counter when the month rolled over.
		$count = ( (string) $state['period'] === $month ) ? (int) $state['count'] : 0;

		// Same for the daily counter (site timezone calendar day).
		$day      = self::current_day();
		$day_count = ( (string) $state['day'] === $day ) ? (int) $state['day_count'] : 0;

		$state['period']       = $month;
		$state['count']        = $count + 1;
		$state['day']          = $day;
		$state['day_count']    = $day_count + 1;
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
