<?php
/**
 * Cache generation counters.
 *
 * `DELETE FROM wp_options WHERE option_name LIKE '_transient_...'` only works
 * when transients live in the options table. With Redis/Memcached (or a
 * persistent object cache) the transient rows are not there, so the purge is a
 * no-op and stale rates stay visible.
 *
 * The fix is a classic generation counter: every cache key includes the current
 * generation number, and "flushing" simply increments it. All old keys become
 * unreachable and expire on their own. The `LIKE` purge is kept as a best-effort
 * extra so a site without an object cache also frees the rows.
 *
 * Pure key building (`generation()`, `key()`) is testable without WordPress.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Generation counters for fragment/provider caches.
 */
final class CacheGeneration {

	/**
	 * Option holding the frontend fragment generation.
	 */
	public const OPTION_FRONTEND = 'rbw_cache_gen';

	/**
	 * Option prefix for per-provider generations (`rbw_cache_gen_{provider}`).
	 */
	public const OPTION_PROVIDER_PREFIX = 'rbw_cache_gen_provider_';

	/**
	 * Read a generation counter.
	 *
	 * @param string $scope 'frontend' or a provider key.
	 * @return int
	 */
	public static function get( string $scope = 'frontend' ): int {
		$option = self::option_name( $scope );

		if ( '' === $option ) {
			return 0;
		}

		return (int) get_option( $option, 0 );
	}

	/**
	 * Increment a generation counter and return the new value.
	 *
	 * @param string $scope 'frontend' or a provider key.
	 * @return int The new generation.
	 */
	public static function bump( string $scope = 'frontend' ): int {
		$option = self::option_name( $scope );

		if ( '' === $option ) {
			return 0;
		}

		$next = self::get( $scope ) + 1;

		// Autoload = yes: it is read on every request that renders a fragment.
		update_option( $option, $next, true );

		return $next;
	}

	/**
	 * Option name for a scope.
	 *
	 * @param string $scope Scope.
	 * @return string '' when the scope name is unusable.
	 */
	public static function option_name( string $scope ): string {
		if ( 'frontend' === $scope ) {
			return self::OPTION_FRONTEND;
		}

		$key = strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', $scope ) ?? '' );

		if ( '' === $key ) {
			return '';
		}

		return self::OPTION_PROVIDER_PREFIX . $key;
	}

	/**
	 * Build a generation-aware cache key.
	 *
	 * The generation is appended last so the rest of the key stays readable and
	 * the same input always maps to the same key within one generation.
	 *
	 * @param string $prefix     Key prefix (e.g. `rbw_html_`).
	 * @param string $key        Base key material.
	 * @param int    $generation Generation number.
	 * @return string
	 */
	public static function key( string $prefix, string $key, int $generation ): string {
		return $prefix . md5( $key . '|g' . $generation ) . '_g' . $generation;
	}
}
