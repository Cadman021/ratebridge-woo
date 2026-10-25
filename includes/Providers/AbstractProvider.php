<?php
/**
 * Shared provider behaviour: HTTP access with caching and error handling.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use RateBridge\Core\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all providers.
 *
 * Concrete providers only implement get_key(), get_label(), get_endpoint(),
 * parse_response() and get_settings_fields().
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * HTTP timeout in seconds.
	 */
	protected const TIMEOUT = 15;

	/**
	 * Cache lifetime in seconds. Providers may override per call.
	 */
	protected const CACHE_TTL = 300;

	/**
	 * Settings key under which provider settings are stored.
	 */
	public const SETTINGS_OPTION = 'rbw_provider_settings';

	/**
	 * Remote endpoint URL, empty for providers that do not use HTTP.
	 *
	 * @return string
	 */
	protected function get_endpoint(): string {
		return '';
	}

	/**
	 * Parse the raw response body into a symbol => rate map.
	 *
	 * @param string               $body      Raw response body.
	 * @param array<string, mixed> $args      Extra arguments passed in by the concrete provider.
	 * @return array<string, float>|WP_Error
	 */
	abstract protected function parse_response( string $body, array $args = array() ): array|WP_Error;

	/**
	 * Perform a GET request and parse the response.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Optional: 'query' (array), 'headers' (array), 'cache_key' (string), 'cache_ttl' (int), 'parse_args' (array).
	 * @return array<string, float>|WP_Error
	 */
	protected function request( string $url, array $args = array() ): array|WP_Error {
		if ( '' === $url ) {
			return new WP_Error( 'rbw_provider_no_endpoint', __( 'This provider does not define an endpoint.', 'ratebridge-woo' ) );
		}

		$query     = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
		$headers   = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$cache_ttl = isset( $args['cache_ttl'] ) ? (int) $args['cache_ttl'] : static::CACHE_TTL;

		$request_url = $query ? add_query_arg( array_map( 'strval', $query ), $url ) : $url;
		$cache_key   = isset( $args['cache_key'] ) ? (string) $args['cache_key'] : 'rbw_http_' . md5( $request_url );

		if ( $cache_ttl > 0 ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'headers'     => array_merge(
					array(
						'Accept'     => 'application/json',
						'User-Agent' => 'RateBridgeWoo/' . ( defined( 'RBW_VERSION' ) ? RBW_VERSION : '1.0' ) . '; ' . home_url( '/' ),
					),
					$headers
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'rbw_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'HTTP request failed: %s', 'ratebridge-woo' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'rbw_http_status',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Unexpected HTTP status: %d', 'ratebridge-woo' ),
					$status
				)
			);
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$parsed = $this->parse_response( $body, isset( $args['parse_args'] ) && is_array( $args['parse_args'] ) ? $args['parse_args'] : array() );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$parsed = $this->sanitize_rates( $parsed );

		if ( empty( $parsed ) ) {
			return new WP_Error( 'rbw_provider_empty', __( 'The provider returned no usable rates.', 'ratebridge-woo' ) );
		}

		if ( $cache_ttl > 0 ) {
			set_transient( $cache_key, $parsed, $cache_ttl );
		}

		return $parsed;
	}

	/**
	 * Drop non-numeric, zero or negative values.
	 *
	 * @param array<string, mixed> $rates Raw rates.
	 * @return array<string, float>
	 */
	protected function sanitize_rates( array $rates ): array {
		$clean = array();

		foreach ( $rates as $symbol => $value ) {
			$value = (float) $value;

			if ( '' === (string) $symbol || ! is_finite( $value ) || $value <= 0 ) {
				continue;
			}

			$clean[ (string) $symbol ] = $value;
		}

		return $clean;
	}

	/**
	 * Read this provider's stored settings (from the shared option).
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$all = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $all ) || ! isset( $all[ $this->get_key() ] ) || ! is_array( $all[ $this->get_key() ] ) ) {
			return array();
		}

		return $all[ $this->get_key() ];
	}

	/**
	 * Read one stored setting for this provider.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function get_setting( string $key, mixed $default = '' ): mixed {
		$settings = $this->get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Persist settings for this provider.
	 *
	 * @param array<string, mixed> $values Values to save.
	 * @return void
	 */
	public function save_settings( array $values ): void {
		$all = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $this->get_key() ] = $values;

		update_option( self::SETTINGS_OPTION, $all );
	}

	/**
	 * Default implementation: no remote call, return an error so that the
	 * rate updater keeps the previous value and stores last_error.
	 *
	 * @param string[] $symbols Provider symbols.
	 * @return array<string, float>|WP_Error
	 */
	public function fetch_rates( array $symbols ): array|WP_Error {
		$endpoint = $this->get_endpoint();

		if ( '' === $endpoint ) {
			return new WP_Error(
				'rbw_provider_not_implemented',
				sprintf(
					/* translators: %s: provider key */
					__( 'Provider "%s" cannot fetch rates automatically.', 'ratebridge-woo' ),
					$this->get_key()
				)
			);
		}

		return $this->request( $endpoint, array( 'parse_args' => array( 'symbols' => $symbols ) ) );
	}

	/**
	 * Helper: current store unit factor (Rial = 1, Toman = 10).
	 *
	 * @return float
	 */
	protected function rial_factor(): float {
		return 'rial' === Settings::store_unit() ? 1.0 : 10.0;
	}

	/**
	 * Default settings schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return array();
	}
}
