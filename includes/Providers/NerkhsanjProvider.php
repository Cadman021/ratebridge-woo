<?php
/**
 * Nerkhsanj provider (نرخ‌سنج) — open, key-less live feed.
 *
 * Endpoint: GET https://nerkh.jahankhahan.shop/data/live.json
 * Auth:     none
 * Docs:     docs/api-samples/nerkhsanj-docs.txt, sample nerkhsanj-live.json
 *
 * The source updates every minute, but it is an **unofficial** aggregator, so we
 * poll politely: one request per effective interval (5 minutes minimum), the
 * whole payload is cached, and a newly added currency is served from the cache.
 *
 * Freshness: the feed carries its own `date` (Jalali) and `time` (Tehran). When
 * the market is closed it keeps the last close and leaves `time` empty, which is
 * normal — so we only warn when the SOURCE timestamp is older than 3 days.
 *
 * Units: Toman per unit of the item, verified against the Navasan/AlanChand
 * fixtures (gold 26.22M vs 26.05M per gram; coin 266.8M vs 267.0M). See the
 * header of NerkhsanjParser for the evidence; no ×1000 multiplier.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use RateBridge\Providers\Parsing\NerkhsanjParser;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches Toman prices from the Nerkhsanj live feed.
 */
final class NerkhsanjProvider extends AbstractProvider {

	/**
	 * Default endpoint (filterable).
	 */
	public const DEFAULT_ENDPOINT = 'https://nerkh.jahankhahan.shop/data/live.json';

	/**
	 * Default minimum minutes between requests.
	 */
	public const DEFAULT_MIN_INTERVAL = 5;

	/**
	 * Age (days) after which the source data is considered stale.
	 */
	public const STALE_AFTER_DAYS = 3;

	/**
	 * Cache key suffix for the whole payload.
	 */
	private const CACHE_SUFFIX = 'catalogue';

	/**
	 * Source timestamp from the last parsed payload.
	 *
	 * @var int
	 */
	private int $last_source_timestamp = 0;

	/**
	 * {@inheritDoc}
	 */
	public function get_key(): string {
		return 'nerkhsanj';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Nerkhsanj', 'ratebridge-woo' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_endpoint(): string {
		/**
		 * Filter the Nerkhsanj endpoint URL.
		 *
		 * @param string $url Endpoint.
		 */
		return (string) apply_filters( 'rbw_nerkhsanj_endpoint', self::DEFAULT_ENDPOINT );
	}

	/**
	 * {@inheritDoc}
	 */
	public function uses_network(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * No key, no monthly quota: only the politeness interval matters.
	 */
	public function get_min_interval_minutes(): int {
		$declared = (int) $this->get_setting( 'min_interval', self::DEFAULT_MIN_INTERVAL );

		return max( 5, $declared > 0 ? $declared : self::DEFAULT_MIN_INTERVAL );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_effective_min_interval_minutes(): int {
		return $this->get_min_interval_minutes();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_monthly_limit(): int {
		return 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_daily_limit(): int {
		$declared = $this->get_setting( 'daily_limit', null );

		return is_numeric( $declared ) ? max( 0, (int) $declared ) : 0;
	}

	/**
	 * Request timeout: the feed is small, so a short one is enough.
	 *
	 * @return int Seconds.
	 */
	public function get_timeout(): int {
		$timeout = (int) $this->get_setting( 'timeout', 10 );

		return max( 5, min( 30, $timeout > 0 ? $timeout : 10 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_last_source_timestamp(): int {
		return $this->last_source_timestamp;
	}

	/**
	 * The source's own timestamp for the given payload (0 when unusable).
	 *
	 * @param string $body Raw payload.
	 * @return int
	 */
	public static function source_timestamp( string $body ): int {
		return NerkhsanjParser::source_timestamp( $body );
	}

	/**
	 * Whether a source timestamp is older than the staleness threshold.
	 *
	 * @param int $source_timestamp Timestamp (0 = unknown).
	 * @param int $now              Reference time.
	 * @return bool
	 */
	public static function is_stale_source( int $source_timestamp, int $now = 0 ): bool {
		if ( $source_timestamp <= 0 ) {
			return false;
		}

		$now = $now > 0 ? $now : time();

		return ( $now - $source_timestamp ) > self::STALE_AFTER_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * One request for the whole feed; symbols only narrow the result.
	 */
	public function fetch_rates( array $symbols ): array|WP_Error {
		$requested = array_values( array_filter( array_map( 'strtoupper', array_map( 'strval', $symbols ) ) ) );
		$cache_key = 'rbw_nerkhsanj_' . self::CACHE_SUFFIX;
		$cache_ttl = max( 60, $this->get_effective_min_interval_minutes() * MINUTE_IN_SECONDS );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $this->pick( $cached, $requested );
		}

		$parsed = $this->request(
			$this->get_endpoint(),
			array(
				'headers'   => array(
					// Descriptive, no site URL, no personal data.
					'User-Agent' => 'RateBridge-Woo/' . ( defined( 'RBW_VERSION' ) ? RBW_VERSION : '1.0' ),
					'Accept'     => 'application/json',
				),
				'cache_key' => $cache_key,
				'cache_ttl' => $cache_ttl,
				'timeout'   => $this->get_timeout(),
			)
		);

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return $this->pick( (array) $parsed, $requested );
	}

	/**
	 * Narrow a cached catalogue to the requested symbols.
	 *
	 * @param array<string, float> $catalogue Symbol => Toman rate.
	 * @param string[]             $symbols   Requested symbols.
	 * @return array<string, float>
	 */
	private function pick( array $catalogue, array $symbols ): array {
		if ( empty( $symbols ) ) {
			return $catalogue;
		}

		$out = array();

		foreach ( $symbols as $symbol ) {
			if ( isset( $catalogue[ $symbol ] ) ) {
				$out[ $symbol ] = (float) $catalogue[ $symbol ];
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( string $body, array $args = array() ): array|WP_Error {
		unset( $args );

		$this->last_source_timestamp = NerkhsanjParser::source_timestamp( $body );

		$toman = NerkhsanjParser::parse( $body );

		if ( empty( $toman ) ) {
			return new WP_Error(
				'rbw_nerkhsanj_empty',
				__( 'Nerkhsanj returned no usable rates.', 'ratebridge-woo' )
			);
		}

		$rates = array();

		foreach ( $toman as $symbol => $value ) {
			$rates[ $symbol ] = $this->from_toman( (float) $value );
		}

		return $rates;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'key'         => 'min_interval',
				'label'       => __( 'Minimum minutes between requests', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => self::DEFAULT_MIN_INTERVAL,
				'min'         => 5,
				'step'        => 1,
				'description' => __( 'The source updates every minute, but it is free and unofficial: please keep at least 5 minutes (default 5).', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'daily_limit',
				'label'       => __( 'Daily request limit', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => 0,
				'min'         => 0,
				'step'        => 1,
				'description' => __( '0 = no daily limit (the interval already keeps usage low).', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'timeout',
				'label'       => __( 'Request timeout (seconds)', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => 10,
				'min'         => 5,
				'step'        => 1,
				'description' => __( '5 to 30 seconds. The feed is small, 10 is plenty.', 'ratebridge-woo' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_known_symbols(): array {
		return array(
			'USD'     => __( 'US Dollar', 'ratebridge-woo' ),
			'EUR'     => __( 'Euro', 'ratebridge-woo' ),
			'CNY'     => __( 'Chinese Yuan', 'ratebridge-woo' ),
			'GBP'     => __( 'British Pound', 'ratebridge-woo' ),
			'AED'     => __( 'UAE Dirham', 'ratebridge-woo' ),
			'TRY'     => __( 'Turkish Lira', 'ratebridge-woo' ),
			'USDT'    => __( 'Tether (USDT)', 'ratebridge-woo' ),
			'BTC'     => __( 'Bitcoin', 'ratebridge-woo' ),
			'CAD'     => __( 'Canadian Dollar', 'ratebridge-woo' ),
			'AUD'     => __( 'Australian Dollar', 'ratebridge-woo' ),
			'CHF'     => __( 'Swiss Franc', 'ratebridge-woo' ),
			'SAR'     => __( 'Saudi Riyal', 'ratebridge-woo' ),
			'QAR'     => __( 'Qatari Riyal', 'ratebridge-woo' ),
			'KWD'     => __( 'Kuwaiti Dinar', 'ratebridge-woo' ),
			'OMR'     => __( 'Omani Rial', 'ratebridge-woo' ),
			'IQD'     => __( 'Iraqi Dinar', 'ratebridge-woo' ),
			'AFN'     => __( 'Afghan Afghani', 'ratebridge-woo' ),
			'RUB'     => __( 'Russian Ruble', 'ratebridge-woo' ),
			'INR'     => __( 'Indian Rupee', 'ratebridge-woo' ),
			'GOLD18'  => __( '18K Gold (gram)', 'ratebridge-woo' ),
			'GOLD24'  => __( '24K Gold (gram)', 'ratebridge-woo' ),
			'COIN'    => __( 'Gold coin (full)', 'ratebridge-woo' ),
			'BAHAR'   => __( 'Bahar Azadi coin', 'ratebridge-woo' ),
			'NIM'     => __( 'Half coin', 'ratebridge-woo' ),
			'ROB'     => __( 'Quarter coin', 'ratebridge-woo' ),
			'SEK'     => __( 'Gram coin', 'ratebridge-woo' ),
			'MESGHAL' => __( 'Melted gold (mithqal)', 'ratebridge-woo' ),
			'SILVER'  => __( 'Silver (gram)', 'ratebridge-woo' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function normalize_symbol( string $symbol ): string {
		return strtoupper( trim( $symbol ) );
	}
}
