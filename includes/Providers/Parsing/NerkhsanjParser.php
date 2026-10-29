<?php
/**
 * Pure parser for the Nerkhsanj (نرخ‌سنج) live feed.
 *
 * No WordPress dependencies, so `tests/parse-fixtures.php` can exercise it
 * against docs/api-samples/nerkhsanj-live.json.
 *
 * Real response shape (verified against the sample + nerkhsanj-docs.txt):
 *
 *   {
 *     "date": "1405/08/07",            // Jalali date of the rates
 *     "time": "16:59",                 // last change time, Tehran; EMPTY outside market hours
 *     "rates": {
 *        "dollar": 271060,             // US dollar, TOMAN
 *        "euro": 301900, "yuan": 40770,
 *        "gold": 26223500,             // 18K gold, Toman per GRAM
 *        "coin": 266785000,            // Emami gold coin, Toman each
 *        "aed": 74289, "lira": 5460, "pound": 361690,
 *        "tether": 271556, "bitcoin": 21949402800,
 *        "gold24": 34964300, "bahar": 257585000,
 *        "half": 140390000, "quarter": 75930000, "gram": 38010000,
 *        "cad": 193960, "aud": 190790, "chf": 327820, "sar": 72387,
 *        "qar": 72460, "kwd": 873070, "omr": 705610, "iqd": 174.5,
 *        "afn": 4178, "rub": 3368, "inr": 2851,
 *        "mesghal": 113604000, "silver": 489280
 *     },
 *     "change": { "dollar": 0.58, … },  // percent vs previous day
 *     "usd": { "ounce": 4011.67 },      // USD per ounce (not used: not in Toman)
 *     "ounce": 4011.67
 *   }
 *
 * Unit evidence: the docs say Toman, and the sample matches the Navasan and
 * AlanChand fixtures within ~1 % for gold (26.22M vs 26.05M per gram) and the
 * coin (266.8M vs 267.0M), so no ×1000 multiplier is applied — unlike Navasan,
 * which quotes coins in thousand Toman.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers\Parsing;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the Nerkhsanj payload into a symbol => Toman rate map.
 */
final class NerkhsanjParser {

	/**
	 * Sample rate key => plugin symbol.
	 *
	 * Only keys that actually exist in the verified sample are listed.
	 *
	 * @var array<string, string>
	 */
	public const SYMBOL_MAP = array(
		'dollar'  => 'USD',
		'euro'    => 'EUR',
		'yuan'    => 'CNY',
		'pound'   => 'GBP',
		'aed'     => 'AED',
		'lira'    => 'TRY',
		'tether'  => 'USDT',
		'bitcoin' => 'BTC',
		'cad'     => 'CAD',
		'aud'     => 'AUD',
		'chf'     => 'CHF',
		'sar'     => 'SAR',
		'qar'     => 'QAR',
		'kwd'     => 'KWD',
		'omr'     => 'OMR',
		'iqd'     => 'IQD',
		'afn'     => 'AFN',
		'rub'     => 'RUB',
		'inr'     => 'INR',
		'gold'    => 'GOLD18',
		'gold24'  => 'GOLD24',
		'coin'    => 'COIN',
		'bahar'   => 'BAHAR',
		'half'    => 'NIM',
		'quarter' => 'ROB',
		'gram'    => 'SEK',
		'mesghal' => 'MESGHAL',
		'silver'  => 'SILVER',
	);

	/**
	 * Parse the payload into a symbol => Toman rate map.
	 *
	 * Defensive: numbers may be strings with commas, Persian digits or null; an
	 * invalid item is skipped on its own. Zero, negative and non-finite values
	 * never survive.
	 *
	 * @param string $body Raw JSON body.
	 * @return array<string, float>
	 */
	public static function parse( string $body ): array {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
			return array();
		}

		$rates = array();

		foreach ( self::SYMBOL_MAP as $source_key => $symbol ) {
			$value = NumberParser::to_positive_float( $data['rates'][ $source_key ] ?? null );

			if ( null !== $value ) {
				$rates[ $symbol ] = $value;
			}
		}

		return $rates;
	}

	/**
	 * The source's own freshness timestamp.
	 *
	 * `date` is a Jalali date and `time` is Tehran wall-clock time. Outside
	 * market hours the feed keeps the last close and leaves `time` empty, so the
	 * date alone is still a valid (coarser) freshness signal.
	 *
	 * @param string $body      Raw JSON body.
	 * @param int    $now       Reference timestamp (defaults to time()).
	 * @return int Unix timestamp (0 when the payload has no usable date).
	 */
	public static function source_timestamp( string $body, ?int $now = null ): int {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return 0;
		}

		$date = isset( $data['date'] ) ? trim( (string) $data['date'] ) : '';

		if ( ! preg_match( '#^(\d{4})/(\d{2})/(\d{2})$#', $date, $m ) ) {
			return 0;
		}

		$time = isset( $data['time'] ) ? trim( (string) $data['time'] ) : '';
		$hour = 0;
		$min  = 0;

		if ( preg_match( '#^(\d{1,2}):(\d{2})$#', $time, $tm ) ) {
			$hour = min( 23, (int) $tm[1] );
			$min  = min( 59, (int) $tm[2] );
		}

		$timestamp = self::jalali_to_timestamp( (int) $m[1], (int) $m[2], (int) $m[3], $hour, $min );

		if ( 0 === $timestamp ) {
			return 0;
		}

		// A Jalali date parsed with a wrong offset would land in the future; clamp.
		if ( null !== $now && $timestamp > $now + 86400 ) {
			return $now;
		}

		return $timestamp;
	}

	/**
	 * Convert a Jalali (Solar Hijri) date + time into a Unix timestamp (UTC).
	 *
	 * Uses the standard arithmetic conversion (no Intl dependency, because the
	 * CLI tests must run without the intl extension).
	 *
	 * @param int $jy   Jalali year.
	 * @param int $jm   Jalali month (1-12).
	 * @param int $jd   Jalali day (1-31).
	 * @param int $hour Hour (0-23).
	 * @param int $min  Minute (0-59).
	 * @return int Unix timestamp, or 0 for an impossible date.
	 */
	public static function jalali_to_timestamp( int $jy, int $jm, int $jd, int $hour = 0, int $min = 0 ): int {
		if ( $jy < 1200 || $jy > 1700 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 ) {
			return 0;
		}

		// Jalali -> Gregorian (arithmetic 33-year cycle).
		$jy   += 1595;
		$days  = -355668 + ( 365 * $jy ) + ( (int) ( $jy / 33 ) * 8 ) + (int) ( ( ( $jy % 33 ) + 3 ) / 4 ) + $jd;

		$days += $jm < 7
			? ( $jm - 1 ) * 31
			: ( ( $jm - 7 ) * 30 ) + 186;

		$gy = 400 * (int) ( $days / 146097 );
		$days %= 146097;

		if ( $days > 36524 ) {
			$gy   += 100 * (int) ( --$days / 36524 );
			$days %= 36524;

			if ( $days >= 365 ) {
				++$days;
			}
		}

		$gy   += 4 * (int) ( $days / 1461 );
		$days %= 1461;

		if ( $days > 365 ) {
			$gy  += (int) ( ( $days - 1 ) / 365 );
			$days = ( $days - 1 ) % 365;
		}

		$gd = $days + 1;

		$month_days = array( 31, ( ( $gy % 4 === 0 && $gy % 100 !== 0 ) || $gy % 400 === 0 ) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$gm         = 1;

		foreach ( $month_days as $length ) {
			if ( $gd <= $length ) {
				break;
			}

			$gd -= $length;
			++$gm;
		}

		if ( $gm > 12 ) {
			return 0;
		}

		/*
		 * `time` is Tehran wall-clock. Asia/Tehran is UTC+03:30 (no DST since
		 * 2022), so subtract 3h30m to reach UTC.
		 */
		$utc = gmmktime( $hour, $min, 0, $gm, $gd, $gy );

		if ( false === $utc ) {
			return 0;
		}

		// 3h30m = 12600 seconds.
		return $utc - 12600;
	}

	/**
	 * Static metadata the provider exposes: catalogue size and a human date.
	 *
	 * @param string $body Raw JSON body.
	 * @return array{date:string,time:string,count:int}
	 */
	public static function meta( string $body ): array {
		$data = json_decode( $body, true );

		return array(
			'date'  => isset( $data['date'] ) ? (string) $data['date'] : '',
			'time'  => isset( $data['time'] ) ? (string) $data['time'] : '',
			'count' => isset( $data['rates'] ) && is_array( $data['rates'] ) ? count( $data['rates'] ) : 0,
		);
	}
}
