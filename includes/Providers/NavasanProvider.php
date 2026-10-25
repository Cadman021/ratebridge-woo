<?php
/**
 * Navasan provider (official REST endpoint).
 *
 * Endpoint: GET {endpoint}/latest/?api_key=KEY
 * Prices are in Toman. One request returns the whole catalogue (~300 items),
 * so a single payload is cached and every requested symbol is served from it.
 *
 * No HTML scraping is performed.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use RateBridge\Providers\Parsing\NavasanParser;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches Toman prices from api.navasan.tech.
 */
final class NavasanProvider extends AbstractProvider {

	/**
	 * Default secure endpoint. Overridable via the rbw_navasan_endpoint filter.
	 */
	private const DEFAULT_ENDPOINT = 'https://api.navasan.tech/latest/';

	/**
	 * Free-plan monthly request allowance.
	 */
	private const DEFAULT_MONTHLY_LIMIT = 120;

	/**
	 * Cache lifetime for the full parsed catalogue (10 minutes).
	 */
	private const CATALOGUE_TTL = 600;

	/**
	 * {@inheritDoc}
	 */
	public function get_key(): string {
		return 'navasan';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Navasan', 'ratebridge-woo' );
	}

	/**
	 * The resolved endpoint.
	 *
	 * Always HTTPS by default. We never silently downgrade to http: if the TLS
	 * request fails the error is surfaced and the admin can override the URL
	 * deliberately with the rbw_navasan_endpoint filter.
	 *
	 * @return string
	 */
	protected function get_endpoint(): string {
		/**
		 * Filter the Navasan endpoint URL.
		 *
		 * @param string $endpoint Endpoint URL.
		 */
		$endpoint = (string) apply_filters( 'rbw_navasan_endpoint', self::DEFAULT_ENDPOINT );

		return '' !== $endpoint ? $endpoint : self::DEFAULT_ENDPOINT;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_min_interval_minutes(): int {
		$limit = $this->get_monthly_limit();

		// The monthly limit dominates; keep at least 5 minutes.
		return max( 5, RequestBudget::monthly_limit_to_minutes( $limit ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_monthly_limit(): int {
		$limit = (int) $this->get_setting( 'monthly_limit', self::DEFAULT_MONTHLY_LIMIT );

		return $limit > 0 ? $limit : self::DEFAULT_MONTHLY_LIMIT;
	}

	/**
	 * Preferred price variant.
	 *
	 * @return string default|buy|sell|average
	 */
	private function get_variant(): string {
		$variant = (string) $this->get_setting( 'price_type', NavasanParser::VARIANT_DEFAULT );

		return array_key_exists( $variant, NavasanParser::variants() ) ? $variant : NavasanParser::VARIANT_DEFAULT;
	}

	/**
	 * Whether an API key is configured.
	 *
	 * @return bool
	 */
	private function has_api_key(): bool {
		$key = $this->get_setting( 'api_key', '' );

		return is_string( $key ) && '' !== trim( $key );
	}

	/**
	 * Fetch the whole catalogue once and serve all requested symbols from it.
	 *
	 * @param string[] $symbols Provider symbols (Navasan base keys).
	 * @return array<string, float>|WP_Error
	 */
	public function fetch_rates( array $symbols ): array|\WP_Error {
		$symbols = array_values( array_filter( array_map( 'strval', $symbols ) ) );

		if ( empty( $symbols ) ) {
			return new WP_Error( 'rbw_navasan_no_symbols', __( 'No symbols were requested.', 'ratebridge-woo' ) );
		}

		if ( ! $this->has_api_key() ) {
			return new WP_Error(
				'rbw_navasan_no_key',
				__( 'The Navasan API key is not configured. Add it in “Price sources”.', 'ratebridge-woo' )
			);
		}

		$catalogue = $this->get_catalogue();

		if ( is_wp_error( $catalogue ) ) {
			return $catalogue;
		}

		$rates = array();

		foreach ( $symbols as $symbol ) {
			if ( isset( $catalogue[ $symbol ] ) ) {
				$rates[ $symbol ] = $this->from_toman( (float) $catalogue[ $symbol ] );
			}
		}

		if ( empty( $rates ) ) {
			return new WP_Error(
				'rbw_navasan_no_match',
				sprintf(
					/* translators: %s: comma separated symbol list */
					__( 'Navasan returned none of the requested symbols (%s).', 'ratebridge-woo' ),
					implode( ', ', array_slice( $symbols, 0, 5 ) )
				)
			);
		}

		return $rates;
	}

	/**
	 * Fetch (or reuse) the parsed Toman catalogue.
	 *
	 * The transient key includes a hash of the settings (per the spec) so a key
	 * or variant change never serves stale data.
	 *
	 * @return array<string, float>|WP_Error
	 */
	private function get_catalogue(): array|WP_Error {
		$settings_hash = md5( (string) wp_json_encode( $this->get_settings() ) );
		$cache_key     = 'rbw_navasan_catalogue_' . $settings_hash;

		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$variant = $this->get_variant();

		$request = $this->request(
			$this->get_endpoint(),
			array(
				'query'       => array( 'api_key' => (string) $this->get_setting( 'api_key', '' ) ),
				'cache_key'   => $cache_key,
				'cache_ttl'   => self::CATALOGUE_TTL,
				'parse_args'  => array(
					'variant' => $variant,
				),
			)
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return $request;
	}

	/**
	 * Parse a Navasan body into a Toman catalogue.
	 *
	 * @param string               $body Raw JSON body.
	 * @param array<string, mixed> $args Parse args (`variant`, `symbols`).
	 * @return array<string, float>|WP_Error
	 */
	protected function parse_response( string $body, array $args = array() ): array|\WP_Error {
		$variant = isset( $args['variant'] ) ? (string) $args['variant'] : $this->get_variant();

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'rbw_navasan_bad_json',
				__( 'Navasan returned a response that is not valid JSON.', 'ratebridge-woo' )
			);
		}

		// The API may answer with an error object such as {"error": "..."}.
		if ( isset( $decoded['error'] ) ) {
			return new WP_Error(
				'rbw_navasan_api_error',
				sprintf(
					/* translators: %s: provider error message */
					__( 'Navasan reported an error: %s', 'ratebridge-woo' ),
					$this->scrub_secrets( (string) $decoded['error'] )
				)
			);
		}

		$catalogue = NavasanParser::parse_to_toman( $body, $variant );

		if ( empty( $catalogue ) ) {
			return new WP_Error(
				'rbw_navasan_empty',
				__( 'Navasan returned no usable prices.', 'ratebridge-woo' )
			);
		}

		return $catalogue;
	}

	/**
	 * Symbols offered in the admin UI.
	 *
	 * Curated to items that are genuinely useful as product price sources and
	 * that are Toman-priced in the sample. Keys are exact Navasan payload keys.
	 *
	 * @return array<string, string>
	 */
	public function get_known_symbols(): array {
		return array(
			'usd'       => __( 'US Dollar', 'ratebridge-woo' ),
			'eur'       => __( 'Euro', 'ratebridge-woo' ),
			'aed'       => __( 'UAE Dirham', 'ratebridge-woo' ),
			'gbp'       => __( 'British Pound', 'ratebridge-woo' ),
			'try'       => __( 'Turkish Lira', 'ratebridge-woo' ),
			'cny'       => __( 'Chinese Yuan', 'ratebridge-woo' ),
			'cad'       => __( 'Canadian Dollar', 'ratebridge-woo' ),
			'aud'       => __( 'Australian Dollar', 'ratebridge-woo' ),
			'jpy'       => __( 'Japanese Yen', 'ratebridge-woo' ),
			'rub'       => __( 'Russian Ruble', 'ratebridge-woo' ),
			'18ayar'    => __( '18K Gold (gram)', 'ratebridge-woo' ),
			'abshodeh'  => __( 'Melted gold (mithqal)', 'ratebridge-woo' ),
			'sekkeh'    => __( 'Gold coin (full)', 'ratebridge-woo' ),
			'bahar'     => __( 'Bahar Azadi coin', 'ratebridge-woo' ),
			'nim'       => __( 'Half coin', 'ratebridge-woo' ),
			'rob'       => __( 'Quarter coin', 'ratebridge-woo' ),
			'gerami'    => __( 'Gram coin', 'ratebridge-woo' ),
			'xau'       => __( 'Gold ounce (Toman)', 'ratebridge-woo' ),
			'xag'       => __( 'Silver ounce (Toman)', 'ratebridge-woo' ),
			'usd_btc'   => __( 'Bitcoin', 'ratebridge-woo' ),
			'usd_eth'   => __( 'Ethereum', 'ratebridge-woo' ),
			'usd_usdt'  => __( 'Tether', 'ratebridge-woo' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'key'         => 'api_key',
				'label'       => __( 'API key', 'ratebridge-woo' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'The key issued by your Navasan account panel.', 'ratebridge-woo' ),
				'hint'        => __( 'Copy the value shown in your Navasan user panel under the API section.', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'price_type',
				'label'       => __( 'Price type', 'ratebridge-woo' ),
				'type'        => 'select',
				'required'    => false,
				'default'     => NavasanParser::VARIANT_DEFAULT,
				'options'     => array(
					NavasanParser::VARIANT_DEFAULT => __( 'Default item price', 'ratebridge-woo' ),
					NavasanParser::VARIANT_BUY     => __( 'Buy price', 'ratebridge-woo' ),
					NavasanParser::VARIANT_SELL    => __( 'Sell price', 'ratebridge-woo' ),
					NavasanParser::VARIANT_AVERAGE => __( 'Average of buy and sell', 'ratebridge-woo' ),
				),
				'description' => __( 'Which value to read when the API offers both a buy and a sell price.', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'monthly_limit',
				'label'       => __( 'Monthly request limit', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => self::DEFAULT_MONTHLY_LIMIT,
				'min'         => 1,
				'step'        => 1,
				'description' => __( 'The free plan allows about 120 requests per month. The plugin never exceeds this number.', 'ratebridge-woo' ),
			),
		);
	}

	/**
	 * Small, safe symbol set for the "test connection" button.
	 *
	 * @return string[]
	 */
	public function get_test_symbols(): array {
		return array( 'usd', '18ayar' );
	}
}
