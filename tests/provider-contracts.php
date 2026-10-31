<?php
/**
 * Provider contract tests for the sources added in Phase 9.
 *
 * Boots WordPress so the real registry, settings fields and provider classes
 * are exercised. NO network request is ever made: only the pure parsers, the
 * symbol/unit contract and the settings schema are asserted.
 *
 * Run from the plugin root:
 *     php tests/provider-contracts.php
 *
 * @package RateBridge
 */

declare( strict_types=1 );

$wp_root = dirname( __DIR__, 4 ) . '/wp-load.php';
$wp_root = realpath( dirname( __DIR__, 4 ) ) . DIRECTORY_SEPARATOR . 'wp-load.php';

// Plugin dir is wp-content/plugins/<slug>, so wp-load.php is 3 levels up.
$candidates = array(
	dirname( __DIR__, 3 ) . '/wp-load.php',
	dirname( __DIR__, 4 ) . '/wp-load.php',
);

$loaded = false;

foreach ( $candidates as $candidate ) {
	if ( is_readable( $candidate ) ) {
		require $candidate;
		$loaded = true;
		break;
	}
}

if ( ! $loaded ) {
	fwrite( STDERR, "Cannot find wp-load.php for the provider contract tests.\n" );
	exit( 1 );
}

use RateBridge\Providers\ProviderRegistry;
use RateBridge\Providers\AbstractProvider;
use RateBridge\Providers\NetarzProvider;
use RateBridge\Providers\NerkhsanjProvider;
use RateBridge\Providers\RequestBudget;

/**
 * PASS/FAIL collector.
 */
final class PC {

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
	 * @return void
	 */
	public static function check( string $label, bool $ok, string $info = '' ): void {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );
		} else {
			++self::$failed;
			printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
		}
	}
}

/**
 * Symbols as a plain list.
 *
 * @param AbstractProvider $provider Provider.
 * @return string[]
 */
function pc_symbols( AbstractProvider $provider ): array {
	return array_map( 'strval', array_keys( $provider->get_known_symbols() ) );
}

/**
 * Settings field keys.
 *
 * @param AbstractProvider $provider Provider.
 * @return string[]
 */
function pc_fields( AbstractProvider $provider ): array {
	return array_map(
		static fn ( array $f ): string => (string) ( $f['key'] ?? '' ),
		$provider->get_settings_fields()
	);
}

echo "RateBridge Woo — Phase 9 provider contracts\n";
echo str_repeat( '=', 60 ), "\n\n";

$registry = ProviderRegistry::instance();

// Settings are mutated below; snapshot them so the run leaves no trace.
$snapshot = array();

foreach ( array( 'netarz', 'nerkhsanj' ) as $snap_key ) {
	$snap_provider = $registry->get( $snap_key );

	if ( $snap_provider instanceof AbstractProvider ) {
		$snapshot[ $snap_key ] = array(
			'provider' => $snap_provider,
			'settings' => $snap_provider->get_settings(),
		);
	}
}

register_shutdown_function(
	static function () use ( $snapshot ): void {
		foreach ( $snapshot as $entry ) {
			// Re-save the ORIGINAL settings captured before the tests ran.
			$entry['provider']->save_settings( $entry['settings'] );
		}

		delete_option( RequestBudget::OPTION );
	}
);

/* ------------------------------------------------------------------------- */
echo "[1] Registry wiring\n";
/* ------------------------------------------------------------------------- */
foreach ( array( 'manual', 'mock', 'navasan', 'alanchand', 'netarz', 'nerkhsanj' ) as $key ) {
	PC::check( "registry has `{$key}`", null !== $registry->get( $key ) );
}

$netarz    = $registry->get( 'netarz' );
$nerkhsanj = $registry->get( 'nerkhsanj' );

PC::check( 'netarz is a NetarzProvider', $netarz instanceof NetarzProvider );
PC::check( 'nerkhsanj is a NerkhsanjProvider', $nerkhsanj instanceof NerkhsanjProvider );

PC::check( 'netarz key', 'netarz' === $netarz->get_key() );
PC::check( 'netarz label is Persian «نت‌ارز»', 'نت‌ارز' === $netarz->get_label(), $netarz->get_label() );
PC::check( 'nerkhsanj key', 'nerkhsanj' === $nerkhsanj->get_key() );
PC::check( 'nerkhsanj label is Persian «نرخ‌سنج»', 'نرخ‌سنج' === $nerkhsanj->get_label(), $nerkhsanj->get_label() );

$choices = $registry->choices();
PC::check( 'both appear in the provider dropdown', isset( $choices['netarz'], $choices['nerkhsanj'] ) );

/* ------------------------------------------------------------------------- */
echo "\n[2] NetArz: auth, limits, symbols\n";
/* ------------------------------------------------------------------------- */
PC::check( 'netarz uses the network', true === $netarz->uses_network() );

$fields = pc_fields( $netarz );
PC::check( 'has an api_key field', in_array( 'api_key', $fields, true ), implode( ',', $fields ) );
PC::check( 'has a plan field', in_array( 'plan', $fields, true ) );
PC::check( 'has a daily_limit field', in_array( 'daily_limit', $fields, true ) );
PC::check( 'has a min_interval field', in_array( 'min_interval', $fields, true ) );

PC::check( 'free plan: 3000 requests/day', 3000 === $netarz->get_daily_limit() );
PC::check( 'free plan: 15 minute interval', 15 === $netarz->get_min_interval_minutes() );

// Pro plan raises the limits.
$netarz->save_settings( array_merge( $netarz->get_settings(), array( 'plan' => 'pro' ) ) );
PC::check( 'pro plan: 200000 requests/day', 200000 === $netarz->get_daily_limit(), (string) $netarz->get_daily_limit() );

/*
 * The interval is `max(5, plan_floor, declared)`. The stored `min_interval` was
 * persisted when the free plan was active (15), so switching the plan to pro does
 * NOT silently lower it — the provider only raises intervals, never reduces a
 * value the admin has on screen. The pro floor of 5 is verified separately below.
 */
PC::check(
	'pro plan never lowers an explicit interval (15 stays 15)',
	15 === $netarz->get_min_interval_minutes(),
	(string) $netarz->get_min_interval_minutes()
);

// With no explicit interval stored, the plan floor applies.
$netarz->save_settings( array_merge( $netarz->get_settings(), array( 'plan' => 'pro', 'min_interval' => 0 ) ) );
PC::check(
	'pro plan floor is 5 minutes when nothing is declared',
	5 === $netarz->get_min_interval_minutes(),
	(string) $netarz->get_min_interval_minutes()
);

$netarz->save_settings( array_merge( $netarz->get_settings(), array( 'plan' => 'free', 'min_interval' => 0 ) ) );
PC::check(
	'free plan floor is 15 minutes when nothing is declared',
	15 === $netarz->get_min_interval_minutes(),
	(string) $netarz->get_min_interval_minutes()
);

// An explicit daily_limit wins over the plan default.
$netarz->save_settings( array_merge( $netarz->get_settings(), array( 'plan' => 'free', 'daily_limit' => 500 ) ) );
PC::check( 'explicit daily_limit overrides the plan', 500 === $netarz->get_daily_limit(), (string) $netarz->get_daily_limit() );

// Restore a clean state.
$netarz->save_settings( array_merge( $netarz->get_settings(), array( 'plan' => 'free', 'daily_limit' => 0 ) ) );

$symbols = pc_symbols( $netarz );
PC::check( 'netarz knows 32 symbols', 32 === count( $symbols ), (string) count( $symbols ) );

foreach ( array( 'USD', 'EUR', 'GBP', 'AED', 'IQD', 'AMD', 'PKR', 'CNY' ) as $symbol ) {
	PC::check( "netarz knows {$symbol}", in_array( $symbol, $symbols, true ) );
}

// NetArz has no gold or coin in its catalogue; it must not pretend otherwise.
PC::check( 'netarz has no GOLD18', ! in_array( 'GOLD18', $symbols, true ) );
PC::check( 'netarz has no COIN', ! in_array( 'COIN', $symbols, true ) );

// Symbol normalisation accepts what an admin is likely to type.
PC::check( 'normalize: lowercase usd', 'USD' === $netarz->normalize_symbol( 'usd' ) );
PC::check( 'normalize: padded " USD "', 'USD' === $netarz->normalize_symbol( ' USD ' ), $netarz->normalize_symbol( ' USD ' ) );

/* ------------------------------------------------------------------------- */
echo "\n[3] Nerkhsanj: open feed, symbols, units\n";
/* ------------------------------------------------------------------------- */
PC::check( 'nerkhsanj uses the network', true === $nerkhsanj->uses_network() );
PC::check( 'nerkhsanj needs no key', array() === array_filter( pc_fields( $nerkhsanj ), static fn ( $k ) => str_contains( $k, 'key' ) || str_contains( $k, 'token' ) ) );
PC::check( 'nerkhsanj has no monthly limit', 0 === $nerkhsanj->get_monthly_limit() );
PC::check( 'nerkhsanj has no daily limit', 0 === $nerkhsanj->get_daily_limit() );
PC::check( 'nerkhsanj default interval is 5 minutes', 5 === $nerkhsanj->get_min_interval_minutes() );

$nk_symbols = pc_symbols( $nerkhsanj );
PC::check( 'nerkhsanj knows 28 symbols', 28 === count( $nk_symbols ), (string) count( $nk_symbols ) );

foreach ( array( 'USD', 'EUR', 'GBP', 'AED', 'CNY', 'TRY', 'GOLD18', 'COIN', 'GOLD24', 'MESGHAL', 'SILVER', 'USDT', 'BTC' ) as $symbol ) {
	PC::check( "nerkhsanj knows {$symbol}", in_array( $symbol, $nk_symbols, true ) );
}

PC::check( 'normalize: "usd" -> USD', 'USD' === $nerkhsanj->normalize_symbol( 'usd' ) );

/* ------------------------------------------------------------------------- */
echo "\n[4] Symbol parity with the parsers\n";
/* ------------------------------------------------------------------------- */
$fixture_dir = dirname( __DIR__ ) . '/docs/api-samples';

$netarz_json = is_readable( $fixture_dir . '/netarz-rates.json' )
	? (string) file_get_contents( $fixture_dir . '/netarz-rates.json' )
	: '';
$nerkh_json  = is_readable( $fixture_dir . '/nerkhsanj-live.json' )
	? (string) file_get_contents( $fixture_dir . '/nerkhsanj-live.json' )
	: '';

if ( '' !== $netarz_json ) {
	$parsed = array_keys( RateBridge\Providers\Parsing\NetarzParser::parse( $netarz_json ) );
	$parsed = array_map( 'strval', $parsed );
	sort( $parsed );
	$known = $symbols;
	sort( $known );

	PC::check( 'netarz provider symbols match the fixture exactly', $parsed === $known, implode( ',', array_diff( $parsed, $known ) ) );
}

if ( '' !== $nerkh_json ) {
	$parsed = array_keys( RateBridge\Providers\Parsing\NerkhsanjParser::parse( $nerkh_json ) );
	$parsed = array_map( 'strval', $parsed );
	sort( $parsed );
	$known = $nk_symbols;
	sort( $known );

	PC::check( 'nerkhsanj provider symbols match the fixture exactly', $parsed === $known, implode( ',', array_diff( $parsed, $known ) ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[5] Secret scrubbing on the new providers\n";
/* ------------------------------------------------------------------------- */
$key = 'fx-ntz-v1-supersecretvalue99';

$leaks = array(
	'plain'     => 'bad key ' . $key,
	'header'    => 'Authorization: Bearer ' . $key,
	'body'      => '{"error":{"code":"unauthorized","message":"key ' . $key . '"}}',
	'url'       => 'https://netarz.ir/api/fx/v1/rates?api_key=' . $key,
);

foreach ( $leaks as $where => $text ) {
	PC::check( "netarz key scrubbed in {$where}", ! str_contains( $netarz->scrub_secrets( $text ), $key ) );
}

PC::check( 'nerkhsanj leaves plain text alone', 'nope' === $nerkhsanj->scrub_secrets( 'nope' ) );

/* ------------------------------------------------------------------------- */
echo "\n[6] Budget isolation between providers\n";
/* ------------------------------------------------------------------------- */
delete_option( RequestBudget::OPTION );

RequestBudget::record( 'netarz', 200, false );

PC::check( 'netarz counted its request', 1 === RequestBudget::used_today( 'netarz' ) );
PC::check( 'nerkhsanj was not counted', 0 === RequestBudget::used_today( 'nerkhsanj' ) );

// An auth failure on NetArz must not block Nerkhsanj.
RequestBudget::set_auth_failed( 'netarz', 401, 'NetArz: invalid key', RequestBudget::fingerprint( array( 'api_key' => $key ) ) );

PC::check( 'netarz is blocked', true === RequestBudget::is_auth_failed( 'netarz', array( 'api_key' => $key ) ) );
PC::check( 'nerkhsanj is unaffected', false === RequestBudget::is_auth_failed( 'nerkhsanj', array() ) );
PC::check( 'nerkhsanj still authorised', true === RequestBudget::authorize( 'nerkhsanj', 'Nerkhsanj', 0, 0, 0 ) );

delete_option( RequestBudget::OPTION );

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", PC::$passed, PC::$failed );

exit( PC::$failed > 0 ? 1 : 0 );
