<?php
/**
 * Pure parser for the AlanChand API.
 *
 * No WordPress dependencies, so it can be exercised from tests/parse-fixtures.php.
 *
 * Real response shape (docs/api-samples/alanchand-*.json) — a flat map keyed by
 * slug, NOT a list:
 *
 *   // type=currency  (44 items)
 *   "usd": { "slug": "usd", "name": "دلار آمریکا", "sell": 270900, "buy": 268200,
 *            "dolar_rate": 1, "open": ..., "high": ..., "low": ...,
 *            "dayChange": 0.74, "updated_at": "2026-10-25 20:20:06" }
 *
 *   // type=gold (12 items) — Toman-priced parts use "price"
 *   "18ayar": { "slug": "18ayar", "name": "گرم طلای 18 عیار", "price": 26051530,
 *               "real_price": 26034000, "bubble": 17530, ... }
 *
 *   // type=gold — the last 5 items are USD per ounce, NOT Toman
 *   "usd_xau": { "slug": "usd_xau", "name": "انس طلا", "price": 3995.85 }
 *
 * Prices are numbers (not strings) and are in Toman.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers\Parsing;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts Toman prices from AlanChand payloads.
 */
final class AlanChandParser {

	/**
	 * Query `type` values supported by the API.
	 */
	public const TYPE_CURRENCY = 'currency';
	public const TYPE_GOLD     = 'gold';
	public const TYPE_CRYPTO   = 'crypto';

	/**
	 * Slugs that are quoted in USD per ounce even though they arrive under
	 * `type=gold` (`usd_xau`, `xag`, `xpt`, `xpd`, `xrh`).
	 *
	 * @var string[]
	 */
	private const US_DENOMINATED = array( 'usd_xau', 'xau', 'xag', 'xpt', 'xpd', 'xrh' );

	/**
	 * Parse a payload of a single type into slug => Toman rate.
	 *
	 * @param string $body Raw JSON body.
	 * @param string $type One of TYPE_*.
	 * @return array<string, float>
	 */
	public static function parse_to_toman( string $body, string $type = self::TYPE_CURRENCY ): array {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		// The API may wrap the map in an envelope on some plans; unwrap defensively.
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			$decoded = $decoded['data'];
		}

		$rates = array();

		foreach ( $decoded as $slug => $item ) {
			$slug = is_string( $slug ) ? trim( $slug ) : '';

			if ( '' === $slug || ! is_array( $item ) ) {
				continue;
			}

			if ( self::is_us_denominated( $slug ) ) {
				continue;
			}

			$value = self::extract_price( $item, $type );

			if ( null === $value ) {
				continue;
			}

			$rates[ $slug ] = $value;
		}

		return $rates;
	}

	/**
	 * Read the price field appropriate for the type.
	 *
	 * Currency items expose `sell`/`buy`; gold and crypto expose `price`.
	 * We prefer `sell` (what a buyer pays) and fall back defensively.
	 *
	 * @param array<string, mixed> $item Decoded item.
	 * @param string               $type Payload type.
	 * @return float|null
	 */
	public static function extract_price( array $item, string $type ): ?float {
		$candidates = self::TYPE_CURRENCY === $type
			? array( 'sell', 'price', 'buy' )
			: array( 'price', 'sell', 'buy' );

		foreach ( $candidates as $field ) {
			$value = NumberParser::to_positive_float( $item[ $field ] ?? null );

			if ( null !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Whether a slug is quoted in USD rather than Toman.
	 *
	 * `usd_xau` (ounce of gold in USD) and the bare bullion slugs are all
	 * USD-denominated in the `gold` payload, so they are excluded.
	 *
	 * @param string $slug Item slug.
	 * @return bool
	 */
	public static function is_us_denominated( string $slug ): bool {
		$slug = strtolower( trim( $slug ) );

		if ( in_array( $slug, self::US_DENOMINATED, true ) ) {
			return true;
		}

		// Any `<base>_xau` / `<base>_xag` style slug is USD-relative.
		$parts = explode( '_', $slug );

		return count( $parts ) > 1 && in_array( (string) end( $parts ), self::US_DENOMINATED, true );
	}

	/**
	 * Split a provider symbol into [type, slug].
	 *
	 * Accepts an explicit override (`gold:sekkeh`, `currency:usd`,
	 * `crypto:btc`) and falls back to the built-in map for bare slugs.
	 *
	 * @param string                $symbol         Provider symbol from the currency row.
	 * @param array<string, string> $symbol_type_map slug => type.
	 * @param string                $fallback_type  Type to use for unknown bare slugs.
	 * @return array{0: string, 1: string} [type, slug]
	 */
	public static function split_symbol( string $symbol, array $symbol_type_map, string $fallback_type = self::TYPE_CURRENCY ): array {
		$symbol = trim( $symbol );

		if ( str_contains( $symbol, ':' ) ) {
			list( $type, $slug ) = explode( ':', $symbol, 2 );
			$type                = strtolower( trim( $type ) );
			$slug                = trim( $slug );

			// Require a known type AND a slug with no further colon, so a
			// malformed `bogus:gold:sekkeh` degrades to a plain slug instead of
			// silently swallowing the prefix.
			if ( ! str_contains( $slug, ':' )
				&& in_array( $type, array( self::TYPE_CURRENCY, self::TYPE_GOLD, self::TYPE_CRYPTO ), true )
				&& '' !== $slug
			) {
				return array( $type, $slug );
			}
		}

		$type = $symbol_type_map[ $symbol ] ?? $fallback_type;

		return array( $type, $symbol );
	}
}
