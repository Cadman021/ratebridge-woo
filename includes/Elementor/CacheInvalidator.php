<?php
/**
 * Keeps Elementor's rendered-document cache in sync with the rates.
 *
 * Elementor (4.x) caches the rendered HTML of a whole document in the
 * `_elementor_element_cache` post/order meta when the "Element Caching" feature
 * is on. A widget's `render()` therefore only runs on a cache miss, so a page
 * could keep showing an old rate for the whole TTL even though the plugin's own
 * fragment cache was flushed.
 *
 * The widgets stay *dynamic* (they render server-side on every cache miss and
 * never store markup themselves), and this class makes sure a rate change also
 * invalidates Elementor's copy. It is written defensively: every Elementor API
 * call is optional, so the plugin keeps working on any Elementor version.
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Flushes Elementor document caches when rates or currencies change.
 */
final class CacheInvalidator {

	/**
	 * Elementor document meta holding the rendered HTML cache.
	 */
	public const CACHE_META_KEY = '_elementor_element_cache';

	/**
	 * Register the invalidation hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rbw_rate_updated', array( $this, 'flush' ), 20 );
		add_action( 'rbw_currency_changed', array( $this, 'flush' ), 20 );
	}

	/**
	 * Delete every cached Elementor document that may embed a rate.
	 *
	 * @return int Number of posts/meta rows touched (0 when nothing to do).
	 */
	public function flush(): int {
		global $wpdb;

		// 1. Ask Elementor to drop its own caches when the API exists.
		$this->purge_elementor_files();

		/*
		 * 2. Delete the per-document rendered HTML cache.
		 *
		 * Elementor stores it as post meta (HPOS orders use the orders meta
		 * table, but documents are posts: pages, templates, products). A single
		 * prepared DELETE is enough and avoids instantiating every document.
		 */
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::CACHE_META_KEY
			)
		);

		/**
		 * Fires after Elementor's document cache was invalidated.
		 *
		 * @param int $deleted Number of deleted meta rows.
		 */
		do_action( 'rbw_elementor_cache_flushed', (int) $deleted );

		return (int) $deleted;
	}

	/**
	 * Purge Elementor's generated CSS/JS files and its own cache manager.
	 *
	 * @return void
	 */
	private function purge_elementor_files(): void {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		$plugin = \Elementor\Plugin::instance();

		if ( ! is_object( $plugin ) ) {
			return;
		}

		// Generated CSS for the widgets (Elementor's files manager).
		if ( isset( $plugin->files_manager ) && is_object( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
			$plugin->files_manager->clear_cache();
		}

		// Elementor's own cache manager (CSS/JS/assets), when present.
		if ( isset( $plugin->assets_manager ) && is_object( $plugin->assets_manager ) ) {
			if ( method_exists( $plugin->assets_manager, 'clear_cache' ) ) {
				$plugin->assets_manager->clear_cache();
			}
		}
	}

	/**
	 * Whether the "Element Caching" experiment is active.
	 *
	 * Used by the admin UI to explain why a rate change rebuilds pages.
	 *
	 * @return bool
	 */
	public static function element_cache_active(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		return 'disable' !== get_option( 'elementor_element_cache_ttl', '' );
	}
}
