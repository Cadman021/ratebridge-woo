<?php
/**
 * Tests for the provider budget / auth state machine and message composition.
 *
 * Run from the plugin root:
 *     php tests/provider-budget.php
 *
 * No WordPress is loaded: minimal stubs provide `get_option`/`update_option`,
 * `wp_date()` and the translation helpers, so the pure logic in RequestBudget
 * (daily rollover, auth_failed stickiness, fingerprinting, message order) can be
 * asserted directly.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// --- WP constants ---------------------------------------------------------
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

// --- WP stubs -------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in.
	 */
	class WP_Error {
		/** @var string */
		public string $code = '';
		/** @var string */
		public string $message = '';

		/**
		 * @param string $code    Error code.
		 * @param string $message Message.
		 */
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
/** @var array<string, mixed> In-memory options. */
$GLOBALS['pb_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['pb_options'] ) ? $GLOBALS['pb_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['pb_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['pb_options'][ $name ] );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : $timestamp;

		return gmdate( $format, $timestamp );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'sprintf_like' ) ) {
	// sprintf() is native; nothing to stub.
}

// A controllable "now" for the day-rollover tests.
$GLOBALS['pb_now'] = 1793000000;

if ( ! function_exists( 'pb_test_now' ) ) {
	function pb_test_now() {
		return (int) $GLOBALS['pb_now'];
	}
}

// RequestBudget uses time() indirectly through next_allowed_at(); the day tests
// only need current_day()/used_today(), which go through wp_date().

require_once __DIR__ . '/../includes/Providers/RequestBudget.php';

use RateBridge\Providers\RequestBudget;

/**
 * Tiny PASS/FAIL collector.
 */
final class PB {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Record a check.
	 *
	 * @param string $label Description.
	 * @param bool   $ok    Result.
	 * @param string $info  Extra info.
	 * @return bool
	 */
	public static function check( string $label, bool $ok, string $info = '' ): bool {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );
		} else {
			++self::$failed;
			printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
		}

		return $ok;
	}
}

/**
 * Reset the budget map between scenarios.
 *
 * @return void
 */
function pb_reset(): void {
	$GLOBALS['pb_options'][ RequestBudget::OPTION ] = array();
}

echo "RateBridge Woo — provider budget + auth state\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Daily budget: rollover and exhaustion\n";
/* ------------------------------------------------------------------------- */
pb_reset();

// No state yet.
PB::check( 'used_today starts at 0', 0 === RequestBudget::used_today( 'prov' ) );

// Record 3 requests today.
for ( $i = 0; $i < 3; $i++ ) {
	RequestBudget::record( 'prov', 200, false );
}

PB::check( 'used_today counts 3', 3 === RequestBudget::used_today( 'prov' ), (string) RequestBudget::used_today( 'prov' ) );
PB::check( 'used_this_period counts 3 too', 3 === RequestBudget::used_this_period( 'prov' ) );

// Under the limit: allowed.
$gate = RequestBudget::authorize( 'prov', 'Provider', 0, 0, 5 );
PB::check( '3 of 5 daily: authorised', true === $gate );

// Reaching the limit: blocked with the daily message.
RequestBudget::record( 'prov', 200, false );
RequestBudget::record( 'prov', 200, false );

$gate = RequestBudget::authorize( 'prov', 'Provider', 0, 0, 5 );
PB::check(
	'5 of 5 daily: blocked',
	$gate instanceof WP_Error && 'rbw_budget_daily_exhausted' === $gate->get_error_code(),
	$gate instanceof WP_Error ? $gate->get_error_code() : 'allowed'
);
PB::check(
	'the daily message names the provider and the counts',
	$gate instanceof WP_Error
		&& str_contains( $gate->get_error_message(), 'Provider' )
		&& str_contains( $gate->get_error_message(), '5' )
);

// Day rollover: pretend the stored day is yesterday -> counter resets.
$state            = RequestBudget::get( 'prov' );
$state['day']     = '2026-01-01';
$state['day_count'] = 999;
RequestBudget::save( 'prov', $state );

PB::check( 'a stale day resets the counter', 0 === RequestBudget::used_today( 'prov' ) );

$gate = RequestBudget::authorize( 'prov', 'Provider', 0, 0, 5 );
PB::check( 'after the rollover: authorised again', true === $gate );

/* ------------------------------------------------------------------------- */
echo "\n[2] Daily and monthly limits together\n";
/* ------------------------------------------------------------------------- */
pb_reset();

RequestBudget::record( 'both', 200, false ); // 1 request: 1 today, 1 this month

$gate = RequestBudget::authorize( 'both', 'Provider', 0, 10, 5 );
PB::check( 'both under limit: authorised', true === $gate );

// Push the monthly counter to the limit but keep the daily low.
$state             = RequestBudget::get( 'both' );
$state['count']    = 10;
$state['period']   = RequestBudget::current_period();
$state['day_count'] = 1;
$state['day']      = RequestBudget::current_day();
RequestBudget::save( 'both', $state );

$gate = RequestBudget::authorize( 'both', 'Provider', 0, 10, 5 );
PB::check(
	'monthly exhausted (daily fine) -> monthly error',
	$gate instanceof WP_Error && 'rbw_budget_monthly_exhausted' === $gate->get_error_code(),
	$gate instanceof WP_Error ? $gate->get_error_code() : 'allowed'
);

// Both exhausted: the daily check runs first (clearer for the admin).
$state              = RequestBudget::get( 'both' );
$state['day_count'] = 5;
RequestBudget::save( 'both', $state );

$gate = RequestBudget::authorize( 'both', 'Provider', 0, 10, 5 );
PB::check(
	'both exhausted -> daily error first',
	$gate instanceof WP_Error && 'rbw_budget_daily_exhausted' === $gate->get_error_code(),
	$gate instanceof WP_Error ? $gate->get_error_code() : 'allowed'
);

// Daily limit 0 = unlimited.
pb_reset();
for ( $i = 0; $i < 50; $i++ ) {
	RequestBudget::record( 'unl', 200, false );
}
PB::check( 'daily limit 0 means unlimited', true === RequestBudget::authorize( 'unl', 'P', 0, 0, 0 ) );

/* ------------------------------------------------------------------------- */
echo "\n[3] auth_failed: set, stickiness, clearing\n";
/* ------------------------------------------------------------------------- */
pb_reset();

$creds = array(
	'api_key' => 'fx-ntz-v1-SECRET',
	'plan'    => 'free',
);

$fp = RequestBudget::fingerprint( $creds );
PB::check( 'fingerprint is a sha256 hex', 1 === preg_match( '/^[a-f0-9]{64}$/', $fp ) );
PB::check( 'non-secret settings do not change the fingerprint', $fp === RequestBudget::fingerprint( array( 'api_key' => 'fx-ntz-v1-SECRET', 'plan' => 'pro' ) ) );
PB::check( 'a different key changes the fingerprint', $fp !== RequestBudget::fingerprint( array( 'api_key' => 'fx-ntz-v1-OTHER' ) ) );
PB::check( 'no secrets -> empty fingerprint', '' === RequestBudget::fingerprint( array( 'plan' => 'free' ) ) );

// Not failed yet.
PB::check( 'starts not failed', false === RequestBudget::is_auth_failed( 'prov', $creds ) );

RequestBudget::set_auth_failed( 'prov', 401, 'NetArz: the token/key is invalid or expired (HTTP 401).', $fp );

PB::check( 'auth_failed is sticky with the same credentials', true === RequestBudget::is_auth_failed( 'prov', $creds ) );

$gate = RequestBudget::authorize( 'prov', 'NetArz', 0, 0, 0, $creds );
PB::check(
	'authorize blocks with the auth error, not a quota error',
	$gate instanceof WP_Error && 'rbw_provider_auth_failed' === $gate->get_error_code(),
	$gate instanceof WP_Error ? $gate->get_error_code() : 'allowed'
);
PB::check( 'the auth message is the stored one', $gate instanceof WP_Error && str_contains( $gate->get_error_message(), 'HTTP 401' ) );

// Changing the credentials clears the block.
PB::check(
	'changing the key clears the block',
	false === RequestBudget::is_auth_failed( 'prov', array( 'api_key' => 'fx-ntz-v1-NEW' ) )
);
PB::check( 'and the state was persisted as cleared', empty( RequestBudget::get( 'prov' )['auth_failed'] ) );
PB::check( 'authorize works again after the key changed', true === RequestBudget::authorize( 'prov', 'NetArz', 0, 0, 0, array( 'api_key' => 'fx-ntz-v1-NEW' ) ) );

// A 403 is treated the same way.
$creds2 = array( 'api_token' => 'tok-2' );
RequestBudget::set_auth_failed( 'alan', 403, 'AlanChand: HTTP 403', RequestBudget::fingerprint( $creds2 ) );
PB::check( '403 also sets auth_failed', true === RequestBudget::is_auth_failed( 'alan', $creds2 ) );

// clear_auth_failed() (a successful manual test).
RequestBudget::clear_auth_failed( 'alan' );
PB::check( 'clear_auth_failed() unsticks it', false === RequestBudget::is_auth_failed( 'alan', $creds2 ) );

/* ------------------------------------------------------------------------- */
echo "\n[4] Root cause first in composed messages\n";
/* ------------------------------------------------------------------------- */
pb_reset();

// A failure with a real provider message, then an interval/failure backoff.
RequestBudget::record( 'prov', 500, true, 'NetArz returned an error (server_busy): try later' );

$gate = RequestBudget::authorize( 'prov', 'NetArz', 60, 0, 0 );

PB::check(
	'backoff message starts with the provider root cause',
	$gate instanceof WP_Error && str_starts_with( $gate->get_error_message(), 'NetArz returned an error' ),
	$gate instanceof WP_Error ? $gate->get_error_message() : 'allowed'
);
PB::check(
	'and keeps the generic backoff note after it',
	$gate instanceof WP_Error && str_contains( $gate->get_error_message(), 'failed recently' )
);

/* ------------------------------------------------------------------------- */
echo "\n[5] Source timestamp bookkeeping\n";
/* ------------------------------------------------------------------------- */
pb_reset();

PB::check( 'source_updated starts at 0', 0 === (int) RequestBudget::get( 'netarz' )['source_updated'] );

RequestBudget::set_source_updated( 'netarz', 1793300000 );
PB::check( 'source_updated stored', 1793300000 === (int) RequestBudget::get( 'netarz' )['source_updated'] );

RequestBudget::set_source_updated( 'netarz', 0 );
PB::check( 'source_updated cleared', 0 === (int) RequestBudget::get( 'netarz' )['source_updated'] );

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", PB::$passed, PB::$failed );

exit( PB::$failed > 0 ? 1 : 0 );
