<?php
/**
 * Asset version resolution for `wp_enqueue_style()` / `wp_enqueue_script()`.
 *
 * Why this exists: the plugin shipped `?ver=0.9.0` on every stylesheet, so a
 * browser that had already cached `tokens.css` kept serving the OLD file after a
 * release — the fixed font URLs were invisible until a hard refresh. Bumping the
 * plugin version is not enough either, because a single-file edit during
 * development does not change it.
 *
 * Rule:
 *  - **Development** (`WP_DEBUG` or `SCRIPT_DEBUG`): the file's `filemtime()`.
 *    Any edit busts the cache immediately.
 *  - **Production**: the plugin version, so URLs stay stable and cacheable and
 *    do not change between two servers with different checkout times.
 *
 * The class is pure apart from reading `filemtime()`, and takes the "debug"
 * flag plus a path resolver as arguments, so `tests/asset-version.php` can pin
 * every branch without WordPress.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the `?ver=` query argument for a plugin asset.
 */
final class AssetVersion {

	/**
	 * Resolve the version for one asset.
	 *
	 * @param string   $relative_path Path relative to the plugin root, e.g.
	 *                                'assets/admin/css/tokens.css'.
	 * @param bool     $debug         True in development (WP_DEBUG/SCRIPT_DEBUG).
	 * @param string   $fallback      Version used when the file cannot be stat'd.
	 * @param callable $resolver      Maps a relative path to an absolute path.
	 *                                Defaults to the plugin root.
	 * @return string
	 */
	public static function for_asset(
		string $relative_path,
		bool $debug,
		string $fallback,
		?callable $resolver = null
	): string {
		if ( ! $debug ) {
			return $fallback;
		}

		$path = null === $resolver
			? self::plugin_path( $relative_path )
			: (string) call_user_func( $resolver, $relative_path );

		if ( '' === $path || ! is_readable( $path ) ) {
			return $fallback;
		}

		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a stat failure must not break the page.

		if ( false === $mtime || $mtime <= 0 ) {
			return $fallback;
		}

		/*
		 * WordPress appends the version verbatim, and a bare integer is fine, but
		 * prefixing it keeps dev URLs visually distinct from release ones in
		 * DevTools and avoids colliding with a real plugin version like 0.9.0.
		 */
		return 'dev-' . $mtime;
	}

	/**
	 * Whether this request should use mtime-based versions.
	 *
	 * @return bool
	 */
	public static function is_debug(): bool {
		$wp_debug     = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$script_debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

		return (bool) ( $wp_debug || $script_debug );
	}

	/**
	 * Version for a plugin asset, using the live environment.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	public static function get( string $relative_path ): string {
		$fallback = defined( 'RBW_VERSION' ) ? (string) RBW_VERSION : '0';

		return self::for_asset( $relative_path, self::is_debug(), $fallback );
	}

	/**
	 * Absolute path of a plugin file ('' when the constant is missing).
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private static function plugin_path( string $relative_path ): string {
		if ( ! defined( 'RBW_PLUGIN_DIR' ) ) {
			return '';
		}

		return rtrim( (string) RBW_PLUGIN_DIR, '/\\' ) . '/' . ltrim( $relative_path, '/\\' );
	}
}
