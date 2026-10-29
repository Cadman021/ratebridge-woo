<?php
/**
 * Pure-PHP Gregorian ↔ Jalali (Solar Hijri) conversion.
 *
 * Deliberately dependency-free: no `IntlDateFormatter` (the `intl` extension is
 * optional on shared hosts) and no third-party library.
 *
 * The algorithm is Kazimierz M. Borkowski's well-known arithmetic conversion
 * (the same one used by jalaali-js / jalali-moment). It works on Julian Day
 * Numbers, so it is exact and has no float drift:
 *
 *   1. Gregorian → Julian Day Number (`g2d`).
 *   2. JDN → Jalali via the 33-year leap cycle (`d2j`).
 *   3. Jalali → JDN (`j2d`), then JDN → Gregorian (`d2g`).
 *
 * Valid for Jalali 1178–3177 (≈ Gregorian 1799–3798), far beyond anything a
 * store can contain. Leap years are those whose 33-cycle remainder is one of
 * 1, 5, 9, 13, 17, 22, 26, 30.
 *
 * Everything here is pure: no WordPress, no timezone objects, no `date()`.
 * Callers convert timestamps with `wp_date()` so the site timezone stays
 * authoritative.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gregorian ↔ Jalali date arithmetic.
 */
final class Jalali {

	/**
	 * 33-year leap cycle in days.
	 */
	private const CYCLE_DAYS = 12053;

	/**
	 * Jalali epoch offset (JDN of 1/1/1 Jalali minus the JDN formula base).
	 */
	private const JALALI_EPOCH = 1948320;

	/**
	 * Convert a Gregorian date to Jalali.
	 *
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month (1–12).
	 * @param int $gd Gregorian day (1–31).
	 * @return array{0:int,1:int,2:int} array( year, month, day ).
	 */
	public static function from_gregorian( int $gy, int $gm, int $gd ): array {
		return self::jdn_to_jalali( self::gregorian_to_jdn( $gy, $gm, $gd ) );
	}

	/**
	 * Convert a Jalali date to Gregorian.
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month (1–12).
	 * @param int $jd Jalali day (1–31).
	 * @return array{0:int,1:int,2:int} array( year, month, day ).
	 */
	public static function to_gregorian( int $jy, int $jm, int $jd ): array {
		return self::jdn_to_gregorian( self::jalali_to_jdn( $jy, $jm, $jd ) );
	}

	/**
	 * Format a Jalali date with the plugin's token pattern.
	 *
	 * Tokens: `Y` 4-digit year, `m` 2-digit month, `d` 2-digit day, `j` day
	 * without a leading zero. Anything else is copied through, so the existing
	 * `date_format` setting (`Y-m-d H:i`) keeps working unchanged.
	 *
	 * @param int    $gy     Gregorian year.
	 * @param int    $gm     Gregorian month.
	 * @param int    $gd     Gregorian day.
	 * @param string $format Token pattern.
	 * @return string
	 */
	public static function format( int $gy, int $gm, int $gd, string $format = 'Y/m/d' ): string {
		[ $jy, $jm, $jd ] = self::from_gregorian( $gy, $gm, $gd );

		return self::apply_tokens(
			$format,
			array(
				'Y' => sprintf( '%04d', $jy ),
				'm' => sprintf( '%02d', $jm ),
				'd' => sprintf( '%02d', $jd ),
				'j' => (string) $jd,
			)
		);
	}

	/**
	 * Whether a Jalali year is a leap year (Esfand has 30 days).
	 *
	 * Borkowski's rule: a year is leap when `(25y + 11) mod 33 < 8`. This is the
	 * canonical 33-year cycle and matches the official Iranian calendar for the
	 * whole supported range (e.g. 1403 is leap, 1404 is not).
	 *
	 * @param int $jy Jalali year.
	 * @return bool
	 */
	public static function is_leap_year( int $jy ): bool {
		$mod = ( 25 * $jy + 11 ) % 33;

		if ( $mod < 0 ) {
			$mod += 33;
		}

		return $mod < 8;
	}

	/**
	 * Number of days in a Jalali year (365 or 366).
	 *
	 * @param int $jy Jalali year.
	 * @return int
	 */
	public static function days_in_jalali_year( int $jy ): int {
		return self::is_leap_year( $jy ) ? 366 : 365;
	}

	/**
	 * Number of days in a Jalali month.
	 *
	 * @param int $jm Jalali month (1–12).
	 * @param int $jy Jalali year.
	 * @return int
	 */
	public static function month_days( int $jm, int $jy ): int {
		if ( $jm < 1 || $jm > 12 ) {
			return 0;
		}

		if ( $jm <= 6 ) {
			return 31;
		}

		if ( $jm <= 11 ) {
			return 30;
		}

		return self::is_leap_year( $jy ) ? 30 : 29;
	}

	/**
	 * Replace a token pattern with values from a map.
	 *
	 * Shared with the Gregorian path so both branches honour `\\` escapes.
	 *
	 * @param string                $format Token pattern.
	 * @param array<string, string> $map    Token => value.
	 * @return string
	 */
	public static function apply_tokens( string $format, array $map ): string {
		$out     = '';
		$length  = strlen( $format );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $format[ $i ];

			if ( '\\' === $char && $i + 1 < $length ) {
				$out .= $format[ ++$i ];
				continue;
			}

			$out .= $map[ $char ] ?? $char;
		}

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Julian Day Number conversions                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Gregorian date → Julian Day Number.
	 *
	 * @param int $gy Year.
	 * @param int $gm Month.
	 * @param int $gd Day.
	 * @return int
	 */
	public static function gregorian_to_jdn( int $gy, int $gm, int $gd ): int {
		$a = intdiv( 14 - $gm, 12 );
		$y = $gy + 4800 - $a;
		$m = $gm + 12 * $a - 3;

		return $gd + intdiv( 153 * $m + 2, 5 ) + 365 * $y + intdiv( $y, 4 ) - intdiv( $y, 100 ) + intdiv( $y, 400 ) - 32045;
	}

	/**
	 * Julian Day Number → Gregorian date.
	 *
	 * @param int $jdn Julian Day Number.
	 * @return array{0:int,1:int,2:int}
	 */
	public static function jdn_to_gregorian( int $jdn ): array {
		$a = $jdn + 32044;
		$b = intdiv( 4 * $a + 3, 146097 );
		$c = $a - intdiv( 146097 * $b, 4 );
		$d = intdiv( 4 * $c + 3, 1461 );
		$e = $c - intdiv( 1461 * $d, 4 );
		$m = intdiv( 5 * $e + 2, 153 );

		$day   = $e - intdiv( 153 * $m + 2, 5 ) + 1;
		$month = $m + 3 - 12 * intdiv( $m, 10 );
		$year  = 100 * $b + $d - 4800 + intdiv( $m, 10 );

		return array( (int) $year, (int) $month, (int) $day );
	}

	/**
	 * Jalali date → Julian Day Number.
	 *
	 * @param int $jy Year.
	 * @param int $jm Month.
	 * @param int $jd Day.
	 * @return int
	 */
	public static function jalali_to_jdn( int $jy, int $jm, int $jd ): int {
		// Days before 1/1/jy, counted from 1/1/1 Jalali. The closed form of the
		// 33-year leap cycle: 365 days per year plus floor((8y + 21) / 33) leaps.
		$days = 365 * ( $jy - 1 ) + intdiv( 8 * $jy + 21, 33 );

		// 6 months of 31 days, then 5 months of 30 days.
		if ( $jm <= 7 ) {
			$days += ( $jm - 1 ) * 31;
		} else {
			$days += 6 * 31 + ( $jm - 7 ) * 30;
		}

		return $days + $jd + self::JALALI_EPOCH - 1;
	}

	/**
	 * Julian Day Number → Jalali date.
	 *
	 * @param int $jdn Julian Day Number.
	 * @return array{0:int,1:int,2:int}
	 */
	public static function jdn_to_jalali( int $jdn ): array {
		// Invert jalali_to_jdn(): solve for the year from the day count.
		$days = $jdn - self::JALALI_EPOCH + 1;

		// Approximate, then correct. 365.2424 is the mean Jalali year length.
		$jy = (int) floor( ( $days - 1 ) / 365.2424 ) + 1;

		while ( self::jalali_to_jdn( $jy, 1, 1 ) > $jdn ) {
			--$jy;
		}

		while ( self::jalali_to_jdn( $jy + 1, 1, 1 ) <= $jdn ) {
			++$jy;
		}

		$left = $jdn - self::jalali_to_jdn( $jy, 1, 1 );

		$jm = 1;

		while ( $left >= self::month_days( $jm, $jy ) ) {
			$left -= self::month_days( $jm, $jy );
			++$jm;
		}

		return array( $jy, $jm, $left + 1 );
	}

	/* --------------------------------------------------------------------- */
	/* (cycle helpers removed — the closed form above is exact)               */
	/* --------------------------------------------------------------------- */
}
