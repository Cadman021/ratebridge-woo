<?php
/**
 * Mock provider: deterministic fake rates for development and testing.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Produces stable pseudo-random rates derived from the symbol and the hour,
 * so that repeated calls within an hour return the same numbers.
 *
 * Nothing is fetched from the network.
 */
final class MockProvider extends AbstractProvider {

	/**
	 * Base rates (in Rial per unit) used as the anchor for the fake variation.
	 *
	 * @var array<string, float>
	 */
	private const BASE_RATES_RIAL = array(
		'USD'    => 600000,
		'EUR'    => 650000,
		'AED'    => 163000,
		'GBP'    => 760000,
		'TRY'    => 18000,
		'GOLD18' => 45000000,
		'GOLD24' => 60000000,
		'SILVER' => 500000,
		'COIN'   => 500000000,
		'BTC'    => 40000000000,
	);

	/**
	 * Fallback base for unknown symbols.
	 */
	private const DEFAULT_BASE_RIAL = 100000.0;

	/**
	 * {@inheritDoc}
	 */
	public function get_key(): string {
		return 'mock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Mock / test data (development only)', 'ratebridge-woo' );
	}

	/**
	 * Generate deterministic fake rates.
	 *
	 * @param string[] $symbols Provider symbols.
	 * @return array<string, float>|WP_Error
	 */
	public function fetch_rates( array $symbols ): array|\WP_Error {
		$symbols = array_values( array_filter( array_map( 'strval', $symbols ) ) );

		if ( empty( $symbols ) ) {
			return new WP_Error( 'rbw_mock_empty', __( 'No symbols requested.', 'ratebridge-woo' ) );
		}

		$bucket  = (int) floor( time() / HOUR_IN_SECONDS );
		$factor  = 'rial' === \RateBridge\Core\Settings::store_unit() ? 1.0 : 10.0;
		$results = array();

		$volatility = (float) $this->get_setting( 'volatility', 0.02 );
		$volatility = min( 0.5, max( 0.0, $volatility ) );

		foreach ( $symbols as $symbol ) {
			$base = self::BASE_RATES_RIAL[ $symbol ] ?? self::DEFAULT_BASE_RIAL;

			// Deterministic pseudo random in [-1, 1] derived from symbol + hour.
			$seed      = crc32( $symbol . '|' . $bucket );
			$random    = ( ( $seed % 20001 ) / 10000 ) - 1;
			$rate_rial = $base * ( 1 + ( $random * $volatility ) );

			$results[ $symbol ] = round( $rate_rial / $factor, 2 );
		}

		return $results;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( string $body, array $args = array() ): array|\WP_Error {
		return new WP_Error( 'rbw_mock_no_http', __( 'The mock provider does not use HTTP.', 'ratebridge-woo' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'key'         => 'volatility',
				'label'       => __( 'Volatility', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => 0.02,
				'description' => __( 'Maximum hourly variation (0 to 0.5). Default 0.02 = ±2%.', 'ratebridge-woo' ),
			),
		);
	}

	/**
	 * The built-in mock catalogue, so the admin can pick symbols from a list.
	 *
	 * @return array<string, string>
	 */
	public function get_known_symbols(): array {
		return array(
			'USD'    => __( 'US Dollar', 'ratebridge-woo' ),
			'EUR'    => __( 'Euro', 'ratebridge-woo' ),
			'AED'    => __( 'UAE Dirham', 'ratebridge-woo' ),
			'GBP'    => __( 'British Pound', 'ratebridge-woo' ),
			'TRY'    => __( 'Turkish Lira', 'ratebridge-woo' ),
			'GOLD18' => __( '18K Gold (gram)', 'ratebridge-woo' ),
			'GOLD24' => __( '24K Gold (gram)', 'ratebridge-woo' ),
			'SILVER' => __( 'Silver (gram)', 'ratebridge-woo' ),
			'COIN'   => __( 'Gold coin (full)', 'ratebridge-woo' ),
			'BTC'    => __( 'Bitcoin', 'ratebridge-woo' ),
		);
	}
}
