<?php
/**
 * AlanChand provider (official REST API).
 *
 * Endpoint: GET https://api.alanchand.com?type={currency|gold|crypto}&symbols=a,b
 * Auth:     Authorization: Bearer TOKEN
 *
 * Prices are in Toman. The API is grouped in three catalogues, so we make at
 * most one request per type per refresh cycle and cache each type separately.
 *
 * No HTML scraping is performed.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use RateBridge\Providers\Parsing\AlanChandParser;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches Toman prices from api.alanchand.com.
 */
final class AlanChandProvider extends AbstractProvider {

	/**
	 * Default endpoint.
	 */
	private const DEFAULT_ENDPOINT = 'https://api.alanchand.com';

	/**
	 * Test-plan minimum minutes between requests.
	 */
	private const DEFAULT_MIN_INTERVAL = 60;

	/**
	 * Cache lifetime per type (30 minutes).
	 */
	private const TYPE_TTL = 1800;

	/**
	 * Built-in slug => type map, taken only from the real samples.
	 *
	 * Currency slugs from docs/api-samples/alanchand-currency.json and gold
	 * slugs from docs/api-samples/alanchand-gold.json.
	 *
	 * @var array<string, string>
	 */
	private const SYMBOL_TYPES = array(
		// type=currency (44 slugs in the sample).
		'usd'             => AlanChandParser::TYPE_CURRENCY,
		'eur'             => AlanChandParser::TYPE_CURRENCY,
		'aed'             => AlanChandParser::TYPE_CURRENCY,
		'try'             => AlanChandParser::TYPE_CURRENCY,
		'gbp'             => AlanChandParser::TYPE_CURRENCY,
		'cny'             => AlanChandParser::TYPE_CURRENCY,
		'cad'             => AlanChandParser::TYPE_CURRENCY,
		'aud'             => AlanChandParser::TYPE_CURRENCY,
		'rub'             => AlanChandParser::TYPE_CURRENCY,
		'iqd'             => AlanChandParser::TYPE_CURRENCY,
		'myr'             => AlanChandParser::TYPE_CURRENCY,
		'gel'             => AlanChandParser::TYPE_CURRENCY,
		'azn'             => AlanChandParser::TYPE_CURRENCY,
		'amd'             => AlanChandParser::TYPE_CURRENCY,
		'thb'             => AlanChandParser::TYPE_CURRENCY,
		'omr'             => AlanChandParser::TYPE_CURRENCY,
		'inr'             => AlanChandParser::TYPE_CURRENCY,
		'pkr'             => AlanChandParser::TYPE_CURRENCY,
		'jpy'             => AlanChandParser::TYPE_CURRENCY,
		'sar'             => AlanChandParser::TYPE_CURRENCY,
		'afn'             => AlanChandParser::TYPE_CURRENCY,
		'sek'             => AlanChandParser::TYPE_CURRENCY,
		'chf'             => AlanChandParser::TYPE_CURRENCY,
		'qar'             => AlanChandParser::TYPE_CURRENCY,
		'krw'             => AlanChandParser::TYPE_CURRENCY,
		'nok'             => AlanChandParser::TYPE_CURRENCY,
		'nzd'             => AlanChandParser::TYPE_CURRENCY,
		'sgd'             => AlanChandParser::TYPE_CURRENCY,
		'hkd'             => AlanChandParser::TYPE_CURRENCY,
		'kwd'             => AlanChandParser::TYPE_CURRENCY,
		'dkk'             => AlanChandParser::TYPE_CURRENCY,
		'bhd'             => AlanChandParser::TYPE_CURRENCY,
		'tjs'             => AlanChandParser::TYPE_CURRENCY,
		'tmt'             => AlanChandParser::TYPE_CURRENCY,
		'kgs'             => AlanChandParser::TYPE_CURRENCY,
		'syp'             => AlanChandParser::TYPE_CURRENCY,
		'brl'             => AlanChandParser::TYPE_CURRENCY,
		'ars'             => AlanChandParser::TYPE_CURRENCY,
		'usd-hav'         => AlanChandParser::TYPE_CURRENCY,
		'usd-ist'         => AlanChandParser::TYPE_CURRENCY,
		'usd-sulaymaniyah' => AlanChandParser::TYPE_CURRENCY,
		'usd-herat'       => AlanChandParser::TYPE_CURRENCY,
		'eur-hav'         => AlanChandParser::TYPE_CURRENCY,
		'eur-ist'         => AlanChandParser::TYPE_CURRENCY,

		// type=gold, Toman-priced parts (7 slugs in the sample).
		'abshodeh'        => AlanChandParser::TYPE_GOLD,
		'18ayar'          => AlanChandParser::TYPE_GOLD,
		'sekkeh'          => AlanChandParser::TYPE_GOLD,
		'bahar'           => AlanChandParser::TYPE_GOLD,
		'nim'             => AlanChandParser::TYPE_GOLD,
		'rob'             => AlanChandParser::TYPE_GOLD,
		'sek'             => AlanChandParser::TYPE_GOLD,
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_key(): string {
		return 'alanchand';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'AlanChand', 'ratebridge-woo' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_endpoint(): string {
		/**
		 * Filter the AlanChand endpoint URL.
		 *
		 * @param string $endpoint Endpoint URL.
		 */
		$endpoint = (string) apply_filters( 'rbw_alanchand_endpoint', self::DEFAULT_ENDPOINT );

		return '' !== $endpoint ? $endpoint : self::DEFAULT_ENDPOINT;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_min_interval_minutes(): int {
		$value = (int) $this->get_setting( 'min_interval', self::DEFAULT_MIN_INTERVAL );

		return max( 5, $value );
	}

	/**
	 * The token, when configured.
	 *
	 * @return string
	 */
	private function get_token(): string {
		$token = $this->get_setting( 'api_token', '' );

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Slug => type map exposed for the admin UI.
	 *
	 * @return array<string, string>
	 */
	public static function symbol_types(): array {
		return self::SYMBOL_TYPES;
	}

	/**
	 * Resolve a currency row's provider symbol into [type, slug].
	 *
	 * @param string $symbol Raw provider symbol.
	 * @return array{0: string, 1: string}
	 */
	public function resolve_symbol( string $symbol ): array {
		return AlanChandParser::split_symbol( $symbol, self::SYMBOL_TYPES, AlanChandParser::TYPE_CURRENCY );
	}

	/**
	 * Fetch rates, grouping the requested symbols by catalogue type.
	 *
	 * At most one HTTP request per type per call (three types maximum).
	 *
	 * @param string[] $symbols Provider symbols, e.g. `usd`, `gold:sekkeh`.
	 * @return array<string, float>|WP_Error
	 */
	public function fetch_rates( array $symbols ): array|\WP_Error {
		$symbols = array_values( array_filter( array_map( 'strval', $symbols ) ) );

		if ( empty( $symbols ) ) {
			return new WP_Error( 'rbw_alanchand_no_symbols', __( 'No symbols were requested.', 'ratebridge-woo' ) );
		}

		if ( '' === $this->get_token() ) {
			return new WP_Error(
				'rbw_alanchand_no_token',
				__( 'The AlanChand API token is not configured. Add it in “Price sources”.', 'ratebridge-woo' )
			);
		}

		// Group requested slugs per type, remembering the original symbol for output.
		$by_type = array();

		foreach ( $symbols as $symbol ) {
			list( $type, $slug ) = $this->resolve_symbol( $symbol );

			$by_type[ $type ]['symbols'][] = $symbol;
			$by_type[ $type ]['slugs'][]   = $slug;
		}

		$rates  = array();
		$errors = array();

		foreach ( $by_type as $type => $group ) {
			$payload = $this->get_type_payload( $type, array_values( array_unique( $group['slugs'] ) ) );

			if ( is_wp_error( $payload ) ) {
				$errors[] = $payload->get_error_message();
				continue;
			}

			foreach ( $group['symbols'] as $index => $symbol ) {
				$slug = $group['slugs'][ $index ];

				if ( isset( $payload[ $slug ] ) ) {
					$rates[ $symbol ] = $this->from_toman( (float) $payload[ $slug ] );
				}
			}
		}

		if ( empty( $rates ) ) {
			return new WP_Error(
				'rbw_alanchand_no_match',
				! empty( $errors )
					? implode( ' | ', array_unique( $errors ) )
					/* translators: %s: comma separated symbol list */
					: sprintf( __( 'AlanChand returned none of the requested symbols (%s).', 'ratebridge-woo' ), implode( ', ', array_slice( $symbols, 0, 5 ) ) )
			);
		}

		return $rates;
	}

	/**
	 * Fetch (or reuse) the parsed Toman map for one catalogue type.
	 *
	 * @param string   $type Catalogue type.
	 * @param string[] $slugs Slugs requested from that catalogue.
	 * @return array<string, float>|WP_Error
	 */
	private function get_type_payload( string $type, array $slugs ): array|WP_Error {
		$settings_hash = md5( (string) wp_json_encode( $this->get_settings() ) );
		$cache_key     = 'rbw_alanchand_' . $type . '_' . $settings_hash;

		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		return $this->request(
			$this->get_endpoint(),
			array(
				'query'     => array(
					'type'    => $type,
					'symbols' => implode( ',', $slugs ),
				),
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->get_token(),
				),
				'cache_key' => $cache_key,
				'cache_ttl' => self::TYPE_TTL,
				// The payload keys are slugs; callers select what they need.
				'parse_args' => array(
					'type' => $type,
				),
			)
		);
	}

	/**
	 * Parse one catalogue payload.
	 *
	 * @param string               $body Raw JSON body.
	 * @param array<string, mixed> $args Parse args (`type`).
	 * @return array<string, float>|WP_Error
	 */
	protected function parse_response( string $body, array $args = array() ): array|\WP_Error {
		$type    = isset( $args['type'] ) ? (string) $args['type'] : AlanChandParser::TYPE_CURRENCY;
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'rbw_alanchand_bad_json',
				__( 'AlanChand returned a response that is not valid JSON.', 'ratebridge-woo' )
			);
		}

		if ( isset( $decoded['error'] ) ) {
			return new WP_Error(
				'rbw_alanchand_api_error',
				sprintf(
					/* translators: %s: provider error message */
					__( 'AlanChand reported an error: %s', 'ratebridge-woo' ),
					$this->scrub_secrets( (string) $decoded['error'] )
				)
			);
		}

		$parsed = AlanChandParser::parse_to_toman( $body, $type );

		if ( empty( $parsed ) ) {
			return new WP_Error(
				'rbw_alanchand_empty',
				__( 'AlanChand returned no usable prices.', 'ratebridge-woo' )
			);
		}

		return $parsed;
	}

	/**
	 * Symbols offered in the admin UI, prefixed with their catalogue type.
	 *
	 * @return array<string, string>
	 */
	public function get_known_symbols(): array {
		$labels = array(
			'usd'    => __( 'US Dollar', 'ratebridge-woo' ),
			'eur'    => __( 'Euro', 'ratebridge-woo' ),
			'aed'    => __( 'UAE Dirham', 'ratebridge-woo' ),
			'try'    => __( 'Turkish Lira', 'ratebridge-woo' ),
			'gbp'    => __( 'British Pound', 'ratebridge-woo' ),
			'cny'    => __( 'Chinese Yuan', 'ratebridge-woo' ),
			'cad'    => __( 'Canadian Dollar', 'ratebridge-woo' ),
			'aud'    => __( 'Australian Dollar', 'ratebridge-woo' ),
			'jpy'    => __( 'Japanese Yen', 'ratebridge-woo' ),
			'chf'    => __( 'Swiss Franc', 'ratebridge-woo' ),
			'sar'    => __( 'Saudi Riyal', 'ratebridge-woo' ),
			'iqd'    => __( 'Iraqi Dinar (100)', 'ratebridge-woo' ),
			'kwd'    => __( 'Kuwaiti Dinar', 'ratebridge-woo' ),
			'bhd'    => __( 'Bahraini Dinar', 'ratebridge-woo' ),
			'omr'    => __( 'Omani Rial', 'ratebridge-woo' ),
			'qar'    => __( 'Qatari Riyal', 'ratebridge-woo' ),
			'usd-hav' => __( 'US Dollar (remittance)', 'ratebridge-woo' ),
			'18ayar' => __( '18K Gold (gram)', 'ratebridge-woo' ),
			'abshodeh' => __( 'Melted gold (mithqal)', 'ratebridge-woo' ),
			'sekkeh' => __( 'Gold coin (full)', 'ratebridge-woo' ),
			'bahar'  => __( 'Bahar Azadi coin', 'ratebridge-woo' ),
			'nim'    => __( 'Half coin', 'ratebridge-woo' ),
			'rob'    => __( 'Quarter coin', 'ratebridge-woo' ),
			'sek'    => __( 'Gram coin', 'ratebridge-woo' ),
		);

		$known = array();

		foreach ( $labels as $slug => $label ) {
			$type = self::SYMBOL_TYPES[ $slug ] ?? AlanChandParser::TYPE_CURRENCY;

			// Currencies stay bare (the API default); gold is explicitly typed.
			$key = AlanChandParser::TYPE_CURRENCY === $type ? $slug : $type . ':' . $slug;

			$known[ $key ] = $label;
		}

		return $known;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'key'         => 'api_token',
				'label'       => __( 'API token', 'ratebridge-woo' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'The bearer token issued by AlanChand.', 'ratebridge-woo' ),
				'hint'        => __( 'Copy the token from your AlanChand account panel. It is sent only in the Authorization header.', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'min_interval',
				'label'       => __( 'Minimum minutes between requests', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => self::DEFAULT_MIN_INTERVAL,
				'min'         => 5,
				'step'        => 1,
				'description' => __( 'The test plan allows one request per hour. The plugin will never send requests faster than this.', 'ratebridge-woo' ),
			),
		);
	}

	/**
	 * Small, safe symbol set for the "test connection" button.
	 *
	 * @return string[]
	 */
	public function get_test_symbols(): array {
		return array( 'usd' );
	}
}
