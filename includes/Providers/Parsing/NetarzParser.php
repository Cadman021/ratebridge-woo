<?php
/**
 * Pure parser for the NetArz (نت‌ارز) FX API.
 *
 * No WordPress dependencies, so `tests/parse-fixtures.php` can exercise it
 * against docs/api-samples/netarz-rates.json and netarz-rate-usd.json.
 *
 * Real response shapes (both verified in the samples):
 *
 *   List   : { "data": [ {…item}, … ], "meta": {…} }
 *   Single : { "data": {…item},        "meta": {…} }
 *
 *   item: { code, name, name_en, symbol, unit, per_usd, is_pegged,
 *           buy, sell, mid, change_24h_percent, quote_currency }
 *   meta: { quote_currency: "IRT", usd_irt, as_of, delayed_minutes, is_delayed,
 *           plan, spread_note, refresh_interval_minutes, source }
 *
 * Units (verified, not assumed): `quote_currency` is **IRT (Toman)** and `mid`
 * is per **`unit` units** of the currency. 29 of the 32 sample rows have
 * unit = 1; **IQD, AMD and PKR have unit = 100**. Evidence: NetArz IQD mid
 * 17,910 / 100 = 179.1 vs Navasan `iqd` 178.23 (0.5 % apart), and USD/EUR/AED/
 * GBP all match the Navasan and AlanChand fixtures within 0.6 %. So the parser
 * divides by `unit` and then the provider converts Toman -> store unit with
 * `from_toman()`. No ×1000 multiplier anywhere (unlike Navasan's coins).
 *
 * The docs also state `Authorization: Bearer fx-ntz-v1-…` (or `X-API-Key`), that
 * the key is never accepted in the URL, and that the server's public IP must be
 * allow-listed in the NetArz panel.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers\Parsing;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a NetArz payload into a symbol => Toman rate map.
 */
final class NetarzParser {

	/**
	 * Which quote price to use: `mid`, `buy` or `sell`.
	 */
	public const QUOTE_MID = 'mid';
	public const QUOTE_BUY = 'buy';
	public const QUOTE_SELL = 'sell';

	/**
	 * Quote prices the admin may pick.
	 *
	 * @return string[]
	 */
	public static function quotes(): array {
		return array( self::QUOTE_MID, self::QUOTE_BUY, self::QUOTE_SELL );
	}

	/**
	 * Parse a payload (list or single object) into symbol => Toman rate.
	 *
	 * The NetArz `code` is already the ISO-ish code we use as the plugin symbol,
	 * so no renaming table is needed.
	 *
	 * @param string $body  Raw JSON body.
	 * @param string $quote Which price field to use (mid by default).
	 * @return array<string, float>
	 */
	public static function parse( string $body, string $quote = self::QUOTE_MID ): array {
		$quote = in_array( $quote, self::quotes(), true ) ? $quote : self::QUOTE_MID;
		$data  = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['data'] ) ) {
			return array();
		}

		$items = $data['data'];

		// Single-currency responses wrap one object instead of a list.
		if ( self::is_item( $items ) ) {
			$items = array( $items );
		}

		if ( ! is_array( $items ) ) {
			return array();
		}

		$rates = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $item['code'] ?? '' ) ) ?? '' );

			if ( '' === $code ) {
				continue;
			}

			$value = NumberParser::to_positive_float( $item[ $quote ] ?? null );

			// Fall back to `mid` when the requested side is missing/zero.
			if ( null === $value && self::QUOTE_MID !== $quote ) {
				$value = NumberParser::to_positive_float( $item[ self::QUOTE_MID ] ?? null );
			}

			if ( null === $value ) {
				continue;
			}

			// `mid` is quoted per `unit` units (IQD/AMD/PKR use 100).
			$unit = NumberParser::to_positive_float( $item['unit'] ?? 1 ) ?? 1.0;

			$rates[ $code ] = $value / $unit;
		}

		return $rates;
	}

	/**
	 * The `meta` block of a payload.
	 *
	 * @param string $body Raw JSON body.
	 * @return array<string, mixed>
	 */
	public static function meta( string $body ): array {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return array();
		}

		return $data['meta'];
	}

	/**
	 * Source freshness timestamp from `meta.as_of` (ISO 8601).
	 *
	 * @param string $body Raw JSON body.
	 * @return int Unix timestamp (0 when absent/unparsable).
	 */
	public static function source_timestamp( string $body ): int {
		$meta = self::meta( $body );
		$as_of = isset( $meta['as_of'] ) ? trim( (string) $meta['as_of'] ) : '';

		if ( '' === $as_of ) {
			return 0;
		}

		$timestamp = strtotime( $as_of );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Whether the payload says the data is delayed and by how much.
	 *
	 * @param string $body Raw JSON body.
	 * @return array{delayed:bool,minutes:int,plan:string}
	 */
	public static function freshness( string $body ): array {
		$meta = self::meta( $body );

		return array(
			'delayed' => ! empty( $meta['is_delayed'] ),
			'minutes' => (int) ( $meta['delayed_minutes'] ?? 0 ),
			'plan'    => (string) ( $meta['plan'] ?? '' ),
		);
	}

	/**
	 * NetArz error envelope: `{ "error": { "code": "...", "message": "..." } }`.
	 *
	 * The docs promise errors always live in `error` with a stable `code`.
	 *
	 * @param string $body Raw JSON body.
	 * @return array{code:string,message:string}|null Null when there is no error.
	 */
	public static function error( string $body ): ?array {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['error'] ) ) {
			return null;
		}

		$error = $data['error'];

		if ( is_string( $error ) ) {
			return array(
				'code'    => 'error',
				'message' => $error,
			);
		}

		if ( ! is_array( $error ) ) {
			return array(
				'code'    => 'error',
				'message' => '',
			);
		}

		return array(
			'code'    => (string) ( $error['code'] ?? 'error' ),
			'message' => (string) ( $error['message'] ?? '' ),
		);
	}

	/**
	 * Whether a decoded node looks like one currency item.
	 *
	 * @param mixed $node Decoded value.
	 * @return bool
	 */
	private static function is_item( mixed $node ): bool {
		return is_array( $node ) && isset( $node['code'] ) && ! array_is_list( $node );
	}
}
