<?php
/**
 * Pure parser for the Navasan "latest" endpoint.
 *
 * No WordPress dependencies, so it can be exercised from tests/parse-fixtures.php.
 *
 * Real response shape (docs/api-samples/navasan-latest.json, 302 keys):
 *
 *   {
 *     "usd":      { "value": "270000", "change": 1800, "timestamp": 1792945862, "date": "1405-08-03 20:01:02" },
 *     "usd_buy":  { "value": "269600", ... },
 *     "usd_sell": { "value": "270000", ... },
 *     "18ayar":   { "value": "26051530", ... },
 *     "sekkeh":   { "value": "267000", ... },
 *     "usd_xau":  { "value": "3995.88", ... },   // <-- USD per ounce, NOT Toman
 *     "bub_abshodeh": { "change_val": 0, "change_pct": 0, "date": 1792946412 } // <-- no value key
 *   }
 *
 * All prices are in Toman. `value` is a *string* for every numeric item.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers\Parsing;

/**
 * Extracts Toman prices from a Navasan payload.
 */
final class NavasanParser {

	/**
	 * Price variants a caller may request when both exist.
	 */
	public const VARIANT_DEFAULT = 'default';
	public const VARIANT_BUY     = 'buy';
	public const VARIANT_SELL    = 'sell';
	public const VARIANT_AVERAGE = 'average';

	/**
	 * Item suffixes that mark derivative variants rather than base items.
	 *
	 * `usd_xau` is "XAU expressed in USD", i.e. a USD-denominated ounce price.
	 * Such items must never be treated as Toman prices. Note that the bare
	 * `xau` / `xag` keys ARE Toman-priced and stay usable.
	 */
	private const NON_TOMAN_SUFFIXES = array( 'xau', 'xag', 'xpt', 'xpd', 'xrh' );

	/**
	 * Base keys that are only non-Toman when prefixed with a USD-relative
	 * parent such as `usd_`.
	 */
	private const USD_RELATIVE_BASES = array( 'xau', 'xag', 'xpt', 'xpd', 'xrh' );

	/**
	 * Browse-only prefixes whose items are not real Toman prices
	 * (`bub_*` = bubble metrics, `mob_*` = legacy/stale, `mex_*` = Mexico).
	 */
	private const IGNORED_PREFIXES = array( 'bub_', 'mob_', 'mex_', 'hav_' );

	/**
	 * Items whose Navasan `value` is expressed in THOUSAND Toman, not Toman.
	 *
	 * Evidence (docs/api-samples, same moment in time):
	 *
	 *   item        Navasan        AlanChand        ratio
	 *   sekkeh       267,000       267,000,000      x1000
	 *   bahar        255,000       255,000,000      x1000
	 *   nim          141,000       141,000,000      x1000
	 *   rob           76,000        76,000,000      x1000
	 *   gerami        38,000        38,000,000      x1000  (AlanChand slug: sek)
	 *   abshodeh     112,850       112,850,000      x1000
	 *   --- unchanged, genuinely Toman ---
	 *   usd          270,000       270,900         x1
	 *   eur          302,460       303,500         x1
	 *   aed           74,220        73,760         x1
	 *   18ayar    26,051,530    26,051,530        x1
	 *
	 * xau / xag and the crypto pairs are real Toman and stay x1.
	 *
	 * @var array<string, float>
	 */
	private const UNIT_MULTIPLIERS = array(
		'sekkeh'   => 1000.0,
		'bahar'    => 1000.0,
		'nim'      => 1000.0,
		'rob'      => 1000.0,
		'gerami'   => 1000.0,
		'abshodeh' => 1000.0,
	);

	/**
	 * Conversion factor applied to a Navasan item to reach real Toman.
	 *
	 * Collapses an optional buy/sell suffix first, so `sekkeh_buy` also gets
	 * the x1000 treatment.
	 *
	 * @param string $key Payload key, e.g. `sekkeh` or `sekkeh_sell`.
	 * @return float 1.0 or 1000.0.
	 */
	public static function unit_multiplier( string $key ): float {
		$base = preg_replace( '/_(buy|sell)$/', '', strtolower( trim( $key ) ) ) ?? '';

		return self::UNIT_MULTIPLIERS[ $base ] ?? 1.0;
	}

	/**
	 * Parse the whole payload into a base-key => Toman rate map.
	 *
	 * Keys keep their Navasan base form (`usd`, `18ayar`, `sekkeh`, …) so any
	 * base item can be addressed directly as a provider symbol.
	 *
	 * @param string $body    Raw JSON body.
	 * @param string $variant Which variant to read: default|buy|sell|average.
	 * @return array<string, float>|\WP_Error_Bag
	 */
	public static function parse_to_toman( string $body, string $variant = self::VARIANT_DEFAULT ): array {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$rates = array();

		foreach ( $decoded as $key => $item ) {
			$key = is_string( $key ) ? trim( $key ) : '';

			if ( '' === $key || ! is_array( $item ) ) {
				continue;
			}

			if ( ! self::is_toman_item( $key ) ) {
				continue;
			}

			$value = self::pick_variant( $decoded, $key, $variant, $item );

			if ( null === $value ) {
				continue;
			}

			// Coins and melted gold are quoted in thousand Toman by Navasan.
			$rates[ $key ] = $value * self::unit_multiplier( $key );
		}

		return $rates;
	}

	/**
	 * Pick the numeric value for a base item honouring the variant preference.
	 *
	 * Variants are separate keys (`usd_buy`, `usd_sell`). When the preferred
	 * variant is absent we fall back to the plain item, then to the other
	 * variant, then to their average.
	 *
	 * @param array<string, mixed> $all     Whole decoded payload.
	 * @param string               $base    Base key, e.g. `usd`.
	 * @param string               $variant Requested variant.
	 * @param array<string, mixed> $item    The base item itself.
	 * @return float|null
	 */
	private static function pick_variant( array $all, string $base, string $variant, array $item ): ?float {
		$direct = NumberParser::to_positive_float( $item['value'] ?? null );

		$buy  = NumberParser::to_positive_float( $all[ $base . '_buy' ]['value'] ?? null );
		$sell = NumberParser::to_positive_float( $all[ $base . '_sell' ]['value'] ?? null );

		switch ( $variant ) {
			case self::VARIANT_BUY:
				return $buy ?? $direct ?? $sell;
			case self::VARIANT_SELL:
				return $sell ?? $direct ?? $buy;
			case self::VARIANT_AVERAGE:
				if ( null !== $buy && null !== $sell ) {
					return ( $buy + $sell ) / 2;
				}
				return $direct ?? $buy ?? $sell;
			case self::VARIANT_DEFAULT:
			default:
				return $direct ?? $sell ?? $buy;
		}
	}

	/**
	 * Whether a payload key represents a usable Toman price item.
	 *
	 * @param string $key Payload key.
	 * @return bool
	 */
	public static function is_toman_item( string $key ): bool {
		foreach ( self::IGNORED_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return false;
			}
		}

		$parts = explode( '_', strtolower( $key ) );

		// A single-segment key such as `xau` / `xag` is a Toman price.
		if ( 1 === count( $parts ) ) {
			return true;
		}

		// Multi-segment keys are USD-relative quotes when their last segment is a
		// metal (`usd_xau`), not Toman prices.
		$last = (string) end( $parts );

		if ( in_array( $last, self::NON_TOMAN_SUFFIXES, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a key is a quoted-in-USD amount rather than a Toman price.
	 *
	 * @param string $key Payload key.
	 * @return bool
	 */
	public static function is_usd_quoted( string $key ): bool {
		$parts = explode( '_', strtolower( $key ) );

		return count( $parts ) > 1 && in_array( (string) end( $parts ), self::USD_RELATIVE_BASES, true );
	}

	/**
	 * The variant keys offered in the admin dropdown.
	 *
	 * @return array<string, string> key => English source label key.
	 */
	public static function variants(): array {
		return array(
			self::VARIANT_DEFAULT => 'Default item price',
			self::VARIANT_BUY     => 'Buy price',
			self::VARIANT_SELL    => 'Sell price',
			self::VARIANT_AVERAGE => 'Average of buy and sell',
		);
	}
}
