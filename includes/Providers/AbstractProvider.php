<?php
/**
 * Shared provider behaviour: HTTP access with caching, quotas and error handling.
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
 * Concrete providers implement get_key(), get_label(), parse_response() and
 * get_settings_fields(), and usually override get_known_symbols().
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * HTTP timeout in seconds.
	 */
	protected const TIMEOUT = 15;

	/**
	 * Default cache lifetime in seconds.
	 */
	protected const CACHE_TTL = 300;

	/**
	 * Settings key under which provider settings are stored (autoload = no).
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
	 * @param string               $body Raw response body.
	 * @param array<string, mixed> $args Extra arguments from the caller.
	 * @return array<string, float>|WP_Error
	 */
	abstract protected function parse_response( string $body, array $args = array() ): array|WP_Error;

	/**
	 * Minimum minutes between two real HTTP requests to this provider.
	 *
	 * Providers that read the value from their own settings override this.
	 *
	 * @return int
	 */
	public function get_min_interval_minutes(): int {
		return 0;
	}

	/**
	 * Monthly real-request limit, 0 for unlimited.
	 *
	 * @return int
	 */
	public function get_monthly_limit(): int {
		return 0;
	}

	/**
	 * Symbols this provider can serve, as symbol => Persian label.
	 *
	 * Providers that talk to a fixed catalogue override this so the admin can
	 * pick from a list. The default is empty (free-text symbols only).
	 *
	 * @return array<string, string>
	 */
	public function get_known_symbols(): array {
		return array();
	}

	/**
	 * Normalise a provider symbol before storing or looking it up.
	 *
	 * Default: trim + lowercase. Providers with a catalogue override this so
	 * free-text input lines up with their canonical keys.
	 *
	 * @param string $symbol Raw symbol.
	 * @return string
	 */
	public function normalize_symbol( string $symbol ): string {
		return strtolower( trim( $symbol ) );
	}

	/**
	 * The budget period the provider is effectively limited to, in minutes.
	 *
	 * The larger of the explicit interval and the interval implied by the
	 * monthly limit.
	 *
	 * @return int
	 */
	public function get_effective_min_interval_minutes(): int {
		return max(
			$this->get_min_interval_minutes(),
			RequestBudget::monthly_limit_to_minutes( $this->get_monthly_limit() )
		);
	}

	/**
	 * Whether this provider performs real network requests.
	 *
	 * @return bool
	 */
	public function uses_network(): bool {
		return '' !== $this->get_endpoint();
	}

	/**
	 * Perform a budgeted, cached GET request and parse the response.
	 *
	 * Order of operations:
	 *  1. cache hit → return cached data (no budget consumed, no HTTP);
	 *  2. budget refusal → WP_Error, no HTTP;
	 *  3. HTTP request → record the attempt against the budget;
	 *  4. parse → cache the parsed map.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args {
	 *     @type array  $query       Query args.
	 *     @type array  $headers     HTTP headers.
	 *     @type string $cache_key   Transient key.
	 *     @type int    $cache_ttl   Transient lifetime, 0 disables caching.
	 *     @type array  $parse_args  Passed to parse_response().
	 *     @type bool   $bypass_cache Only if the budget still allows it.
	 * }
	 * @return array<string, float>|WP_Error
	 */
	protected function request( string $url, array $args = array() ): array|WP_Error {
		if ( '' === $url ) {
			return new WP_Error( 'rbw_provider_no_endpoint', __( 'This provider does not define an endpoint.', 'ratebridge-woo' ) );
		}

		$query        = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
		$headers      = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$cache_ttl    = isset( $args['cache_ttl'] ) ? (int) $args['cache_ttl'] : (int) static::CACHE_TTL;
		$bypass_cache = ! empty( $args['bypass_cache'] );

		$request_url = $query ? add_query_arg( array_map( 'strval', $query ), $url ) : $url;

		// Cache keys must never contain secrets, so hash the final URL.
		$cache_key = isset( $args['cache_key'] )
			? (string) $args['cache_key']
			: 'rbw_http_' . md5( $this->get_key() . '|' . $request_url );

		if ( $cache_ttl > 0 && ! $bypass_cache ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// Quota gate: nothing leaves the server without permission.
		$authorized = RequestBudget::authorize(
			$this->get_key(),
			$this->get_label(),
			$this->get_min_interval_minutes(),
			$this->get_monthly_limit()
		);

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
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
			// The WP_Error may embed the full URL, so scrub before it is stored.
			$message = $this->scrub_secrets( (string) $response->get_error_message() );

			RequestBudget::record( $this->get_key(), 0, true, $message );

			return new WP_Error(
				'rbw_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'HTTP request failed: %s', 'ratebridge-woo' ),
					$message
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			$message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Unexpected HTTP status: %d', 'ratebridge-woo' ),
				$status
			);

			RequestBudget::record( $this->get_key(), $status, true, $message );

			return new WP_Error( 'rbw_http_status', $message );
		}

		$parsed = $this->parse_response( $body, isset( $args['parse_args'] ) && is_array( $args['parse_args'] ) ? $args['parse_args'] : array() );

		if ( is_wp_error( $parsed ) ) {
			$parsed = new WP_Error( $parsed->get_error_code(), $this->scrub_secrets( $parsed->get_error_message() ) );

			RequestBudget::record( $this->get_key(), $status, true, $parsed->get_error_message() );

			return $parsed;
		}

		$parsed = $this->sanitize_rates( $parsed );

		if ( empty( $parsed ) ) {
			$message = __( 'The provider returned no usable rates.', 'ratebridge-woo' );

			RequestBudget::record( $this->get_key(), $status, true, $message );

			return new WP_Error( 'rbw_provider_empty', $message );
		}

		// The request succeeded and consumed quota.
		RequestBudget::record( $this->get_key(), $status, false );

		if ( $cache_ttl > 0 ) {
			set_transient( $cache_key, $parsed, $cache_ttl );
		}

		return $parsed;
	}

	/**
	 * Remove secrets and key-bearing URLs from any user-facing string.
	 *
	 * Subclasses declare their secret values through get_secret_values().
	 *
	 * @param string $text Text to scrub.
	 * @return string
	 */
	public function scrub_secrets( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		foreach ( $this->get_secret_values() as $secret ) {
			if ( '' === $secret ) {
				continue;
			}

			$text = str_replace( $secret, '***', $text );

			// Also scrub URL-encoded and rawurlencoded variants.
			$text = str_replace( array( rawurlencode( $secret ), urlencode( $secret ) ), '***', $text );
		}

		// Drop any query parameter named like a credential.
		$text = preg_replace(
			'/([?&](?:api_?key|token|access_?token|auth|authorization|secret)=)[^&\s\'"]+/i',
			'$1***',
			$text
		) ?? $text;

		// Belt and braces: never echo a bare long token-looking string.
		return $text;
	}

	/**
	 * Secret values this provider must never expose.
	 *
	 * @return string[]
	 */
	protected function get_secret_values(): array {
		$secrets = array();

		foreach ( $this->get_settings_fields() as $field ) {
			if ( ( $field['type'] ?? '' ) === 'password' ) {
				$value = $this->get_setting( (string) $field['key'], '' );

				if ( is_string( $value ) && '' !== $value ) {
					$secrets[] = $value;
				}
			}
		}

		return $secrets;
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
	 * Read all of this provider's stored settings.
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
	 * Persist settings for this provider (stored in a non-autoloaded option).
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

		// autoload = no: secrets must not be loaded on every request.
		update_option( self::SETTINGS_OPTION, $all, false );

		// A settings change invalidates this provider's caches and quota view.
		$this->flush_cache();
	}

	/**
	 * Delete every transient belonging to this provider.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$prefix = 'rbw_' . $this->get_key() . '_';

		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
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
	 * Convert a Toman amount into the store's selected unit.
	 *
	 * Sources such as Navasan and AlanChand quote prices in Toman. The store
	 * may be configured in Rial (1 Toman = 10 Rial) or Toman (no change).
	 *
	 * @param float $toman Amount in Toman.
	 * @return float Amount in the store unit.
	 */
	protected function from_toman( float $toman ): float {
		if ( ! is_finite( $toman ) || $toman <= 0 ) {
			return 0.0;
		}

		return 'rial' === Settings::store_unit() ? $toman * 10.0 : $toman;
	}

	/**
	 * How many Rial make one unit of the store currency.
	 *
	 * Rial store → 1.0, Toman store → 10.0 (1 Toman = 10 Rial).
	 *
	 * This is the inverse relationship of from_toman(): it converts a
	 * store-unit amount *to* Rial, i.e. `store_amount * rial_factor()`.
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
