<?php
/**
 * NetArz provider (نت‌ارز) — key-based FX API.
 *
 * Endpoint: GET https://netarz.ir/api/fx/v1/rates   (list, one request)
 * Auth:     Authorization: Bearer fx-ntz-v1-…      (header only, never in the URL)
 * Docs:     docs/netarz-docs.md, samples in docs/api-samples/netarz-*.json
 *
 * One request returns the whole catalogue (32 codes in the sample), so a newly
 * added currency is served from the cached payload and never costs a new
 * request. Cached for the effective minimum interval.
 *
 * Units: quote_currency is IRT (Toman) and `mid` is per `unit` units; the parser
 * divides by `unit` and this class converts Toman -> store unit. See the header
 * of NetarzParser for the evidence.
 *
 * Free plan caveats (from the docs): data is delayed ~15 minutes, the server's
 * public IP must be allow-listed in the NetArz panel, and the key is bound to
 * your domain/app.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use RateBridge\Providers\Parsing\NetarzParser;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches Toman prices from netarz.ir.
 */
final class NetarzProvider extends AbstractProvider {

	/**
	 * Default base URL (filterable).
	 */
	public const DEFAULT_ENDPOINT = 'https://netarz.ir/api/fx/v1';

	/**
	 * Free plan: daily request limit (docs).
	 */
	public const FREE_DAILY_LIMIT = 3000;

	/**
	 * Pro plan: daily request limit (docs).
	 */
	public const PRO_DAILY_LIMIT = 200000;

	/**
	 * Free plan minimum interval: the free data itself is 15 minutes delayed.
	 */
	public const FREE_MIN_INTERVAL = 15;

	/**
	 * Pro plan minimum interval (the docs' refresh_interval_minutes is 5).
	 */
	public const PRO_MIN_INTERVAL = 5;

	/**
	 * Cache key suffix for the whole catalogue.
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
		return 'netarz';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'NetArz', 'ratebridge-woo' );
	}

	/**
	 * Base URL, filterable per site.
	 *
	 * @return string
	 */
	public function get_base_url(): string {
		/**
		 * Filter the NetArz base URL (no trailing slash).
		 *
		 * @param string $url Base URL.
		 */
		$url = (string) apply_filters( 'rbw_netarz_endpoint', self::DEFAULT_ENDPOINT );

		return rtrim( $url, '/' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_endpoint(): string {
		return $this->get_base_url() . '/rates';
	}

	/**
	 * {@inheritDoc}
	 */
	public function uses_network(): bool {
		return true;
	}

	/**
	 * Plan selected by the admin.
	 *
	 * @return string free|pro
	 */
	public function get_plan(): string {
		$plan = sanitize_key( (string) $this->get_setting( 'plan', 'free' ) );

		return 'pro' === $plan ? 'pro' : 'free';
	}

	/**
	 * Daily limit: the admin value, else the plan default.
	 *
	 * @return int
	 */
	public function get_daily_limit(): int {
		$declared = $this->get_setting( 'daily_limit', null );

		if ( is_numeric( $declared ) && (int) $declared > 0 ) {
			return (int) $declared;
		}

		return 'pro' === $this->get_plan() ? self::PRO_DAILY_LIMIT : self::FREE_DAILY_LIMIT;
	}

	/**
	 * NetArz has no monthly quota in the docs: only a daily one.
	 *
	 * @return int
	 */
	public function get_monthly_limit(): int {
		return 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_min_interval_minutes(): int {
		$declared = (int) $this->get_setting( 'min_interval', 0 );
		$plan_min = 'pro' === $this->get_plan() ? self::PRO_MIN_INTERVAL : self::FREE_MIN_INTERVAL;

		// Never below 5 minutes, and never below the plan's own floor.
		return max( 5, $plan_min, $declared );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_effective_min_interval_minutes(): int {
		return $this->get_min_interval_minutes();
	}

	/**
	 * NetArz-specific hint for 401/403.
	 *
	 * @return string
	 */
	protected function get_auth_hint(): string {
		return __( 'The server’s public IP (or your domain) is probably not allowed in the NetArz panel.', 'ratebridge-woo' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_last_source_timestamp(): int {
		return $this->last_source_timestamp;
	}

	/**
	 * The API key, if set.
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return trim( (string) $this->get_setting( 'api_key', '' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * One request for the whole catalogue; the requested symbols are only used to
	 * decide whether a refresh is needed at all.
	 */
	public function fetch_rates( array $symbols ): array|WP_Error {
		$key = $this->get_api_key();

		if ( '' === $key ) {
			return new WP_Error(
				'rbw_netarz_no_key',
				__( 'NetArz needs an API key. Add it on the Price sources tab.', 'ratebridge-woo' )
			);
		}

		$requested = array_values( array_filter( array_map( 'strtoupper', array_map( 'strval', $symbols ) ) ) );
		$cache_key = 'rbw_netarz_' . self::CACHE_SUFFIX;
		$cache_ttl = max( 60, $this->get_effective_min_interval_minutes() * MINUTE_IN_SECONDS );
		$cached    = get_transient( $cache_key );

		// Serve from the cached catalogue: a new currency never costs a request.
		if ( is_array( $cached ) ) {
			return $this->pick( $cached, $requested );
		}

		// Key in the HEADER only — the docs reject a key in the URL.
		$parsed = $this->request(
			$this->get_endpoint(),
			array(
				'headers'    => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				),
				'cache_key'  => $cache_key,
				'cache_ttl'  => $cache_ttl,
				'parse_args' => array(
					'quote' => $this->get_quote(),
				),
			)
		);

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return $this->pick( (array) $parsed, $requested );
	}

	/**
	 * Which quote side to use (`mid` by default).
	 *
	 * @return string
	 */
	public function get_quote(): string {
		$quote = sanitize_key( (string) $this->get_setting( 'quote', NetarzParser::QUOTE_MID ) );

		return in_array( $quote, NetarzParser::quotes(), true ) ? $quote : NetarzParser::QUOTE_MID;
	}

	/**
	 * Reduce the cached catalogue to the symbols the caller asked for.
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
		// The docs promise errors in `error` with a stable code.
		$error = NetarzParser::error( $body );

		if ( null !== $error ) {
			$code = strtolower( (string) $error['code'] );

			// Auth-shaped codes must flip the sticky auth_failed state.
			$wp_code = preg_match( '/auth|unauthori|forbidden|invalid_?key|invalid_?token/', $code )
				? 'rbw_netarz_auth_error'
				: 'rbw_netarz_error';

			return new WP_Error(
				$wp_code,
				sprintf(
					/* translators: 1: error code, 2: provider message */
					__( 'NetArz returned an error (%1$s): %2$s', 'ratebridge-woo' ),
					$error['code'],
					$this->scrub_secrets( (string) $error['message'] )
				)
			);
		}

		$this->last_source_timestamp = NetarzParser::source_timestamp( $body );

		$quote = isset( $args['quote'] ) ? (string) $args['quote'] : NetarzParser::QUOTE_MID;
		$toman = NetarzParser::parse( $body, $quote );

		if ( empty( $toman ) ) {
			return new WP_Error(
				'rbw_netarz_empty',
				__( 'NetArz returned no usable rates.', 'ratebridge-woo' )
			);
		}

		// Toman -> store unit (Rial store multiplies by 10).
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
				'key'         => 'api_key',
				'label'       => __( 'API key', 'ratebridge-woo' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'An app API key from the NetArz panel (API نرخ ارز).', 'ratebridge-woo' ),
				'hint'        => __( 'The key is sent only in the Authorization header. The public IP of this server must be allowed in the NetArz panel, otherwise every request returns 401/403.', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'plan',
				'label'       => __( 'Plan', 'ratebridge-woo' ),
				'type'        => 'select',
				'required'    => false,
				'default'     => 'free',
				'options'     => array(
					'free' => __( 'Free (data delayed 15 minutes)', 'ratebridge-woo' ),
					'pro'  => __( 'Pro (live data)', 'ratebridge-woo' ),
				),
				'description' => __( 'The free plan delays every rate by about 15 minutes; Pro has no delay.', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'quote',
				'label'       => __( 'Which price', 'ratebridge-woo' ),
				'type'        => 'select',
				'required'    => false,
				'default'     => NetarzParser::QUOTE_MID,
				'options'     => array(
					'mid'  => __( 'Mid (average)', 'ratebridge-woo' ),
					'buy'  => __( 'Buy', 'ratebridge-woo' ),
					'sell' => __( 'Sell', 'ratebridge-woo' ),
				),
				'description' => __( 'Buy/sell are indicative quotes around the market mid.', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'daily_limit',
				'label'       => __( 'Daily request limit', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => self::FREE_DAILY_LIMIT,
				'min'         => 0,
				'step'        => 1,
				'description' => __( '0 = use the plan default (free 3,000 / pro 200,000 per day). The per-minute limit is informational only.', 'ratebridge-woo' ),
			),
			array(
				'key'         => 'min_interval',
				'label'       => __( 'Minimum minutes between requests', 'ratebridge-woo' ),
				'type'        => 'number',
				'required'    => false,
				'default'     => self::FREE_MIN_INTERVAL,
				'min'         => 5,
				'step'        => 1,
				'description' => __( 'Free plan default 15 minutes (the data itself is 15 minutes delayed); pro default 5. Never below 5.', 'ratebridge-woo' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_known_symbols(): array {
		return array(
			'USD' => __( 'US Dollar', 'ratebridge-woo' ),
			'AED' => __( 'UAE Dirham', 'ratebridge-woo' ),
			'EUR' => __( 'Euro', 'ratebridge-woo' ),
			'GBP' => __( 'British Pound', 'ratebridge-woo' ),
			'SAR' => __( 'Saudi Riyal', 'ratebridge-woo' ),
			'QAR' => __( 'Qatari Riyal', 'ratebridge-woo' ),
			'OMR' => __( 'Omani Rial', 'ratebridge-woo' ),
			'TRY' => __( 'Turkish Lira', 'ratebridge-woo' ),
			'BHD' => __( 'Bahraini Dinar', 'ratebridge-woo' ),
			'CNY' => __( 'Chinese Yuan', 'ratebridge-woo' ),
			'INR' => __( 'Indian Rupee', 'ratebridge-woo' ),
			'KWD' => __( 'Kuwaiti Dinar', 'ratebridge-woo' ),
			'JOD' => __( 'Jordanian Dinar', 'ratebridge-woo' ),
			'RUB' => __( 'Russian Ruble', 'ratebridge-woo' ),
			'AZN' => __( 'Azerbaijani Manat', 'ratebridge-woo' ),
			'IQD' => __( 'Iraqi Dinar (100)', 'ratebridge-woo' ),
			'HKD' => __( 'Hong Kong Dollar', 'ratebridge-woo' ),
			'CAD' => __( 'Canadian Dollar', 'ratebridge-woo' ),
			'AUD' => __( 'Australian Dollar', 'ratebridge-woo' ),
			'CHF' => __( 'Swiss Franc', 'ratebridge-woo' ),
			'JPY' => __( 'Japanese Yen', 'ratebridge-woo' ),
			'SGD' => __( 'Singapore Dollar', 'ratebridge-woo' ),
			'MYR' => __( 'Malaysian Ringgit', 'ratebridge-woo' ),
			'THB' => __( 'Thai Baht', 'ratebridge-woo' ),
			'SEK' => __( 'Swedish Krona', 'ratebridge-woo' ),
			'NOK' => __( 'Norwegian Krone', 'ratebridge-woo' ),
			'DKK' => __( 'Danish Krone', 'ratebridge-woo' ),
			'GEL' => __( 'Georgian Lari', 'ratebridge-woo' ),
			'AMD' => __( 'Armenian Dram (100)', 'ratebridge-woo' ),
			'TMT' => __( 'Turkmen Manat', 'ratebridge-woo' ),
			'AFN' => __( 'Afghan Afghani', 'ratebridge-woo' ),
			'PKR' => __( 'Pakistani Rupee (100)', 'ratebridge-woo' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function normalize_symbol( string $symbol ): string {
		return strtoupper( trim( $symbol ) );
	}
}
