<?php
/**
 * Single entry point for every date the plugin prints.
 *
 * Before this class the plugin called `wp_date( 'Y-m-d H:i', … )` in a dozen
 * places, so switching to Jalali would have meant editing each one. Every
 * display path now funnels through `DateFormatter::format()`, which:
 *
 *  1. converts the timestamp with `wp_date()` so the **site timezone** (Tehran
 *     for a Persian store) stays authoritative — never the server timezone;
 *  2. renders the date part in Jalali when the setting (or the `fa_*` locale
 *     default) asks for it, keeping the time part 24-hour;
 *  3. applies the «ارقام فارسی» digit preference last, so the digits of both the
 *     Jalali date and the clock are consistent.
 *
 * The token set is the one documented in the settings screen (`Y m d j H i s`),
 * shared with `RateFormatter::format_date()` so the frontend and the admin can
 * never drift apart.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

use RateBridge\Frontend\RateFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Locale-aware, calendar-aware date rendering.
 */
final class DateFormatter {

	/**
	 * Format a timestamp for display.
	 *
	 * @param int    $timestamp Unix timestamp (0 = unknown).
	 * @param string $format    Token pattern, e.g. 'Y-m-d H:i'.
	 * @param bool|null $jalali  Force Jalali on/off; null follows the setting.
	 * @return string
	 */
	public static function format( int $timestamp, string $format = 'Y-m-d H:i', ?bool $jalali = null ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		$format = '' === trim( $format ) ? 'Y-m-d H:i' : $format;
		$jalali = null === $jalali ? Settings::use_jalali_dates() : $jalali;

		// Site timezone first: the date we convert must already be local.
		$parts = self::local_parts( $timestamp );

		if ( $jalali ) {
			/*
			 * The calendar changes only the DATE part; the clock is identical in
			 * both calendars, so the time tokens are substituted from the local
			 * parts and the rest is handed to the Jalali token formatter.
			 */
			$date = Jalali::format(
				$parts['year'],
				$parts['month'],
				$parts['day'],
				self::replace_time_tokens( $format, $parts )
			);
		} else {
			$date = RateFormatter::format_date( $timestamp, $format, self::timezone_name() );
		}

		return RateFormatter::maybe_persian( $date, self::use_persian_digits() );
	}

	/**
	 * Substitute the clock tokens (H i s) before the date formatter runs.
	 *
	 * `H`/`i`/`s` mean the same thing in both calendars, so they are replaced
	 * up front; `Y m d j` are left for `Jalali::format()`. `\\` escapes are
	 * honoured so a literal `H` can still be printed.
	 *
	 * @param string $format Token pattern.
	 * @param array{hour:int,minute:int,second:int} $parts Local clock parts.
	 * @return string
	 */
	private static function replace_time_tokens( string $format, array $parts ): string {
		$map = array(
			'H' => sprintf( '%02d', $parts['hour'] ),
			'i' => sprintf( '%02d', $parts['minute'] ),
			's' => sprintf( '%02d', $parts['second'] ),
		);

		$out    = '';
		$length = strlen( $format );

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

	/**
	 * Format a stored UTC datetime string.
	 *
	 * @param string|null $utc_datetime UTC datetime, e.g. '2026-10-29 17:23:00'.
	 * @param string      $format       Token pattern.
	 * @param string      $fallback     Returned when the value is empty/invalid.
	 * @return string
	 */
	public static function format_utc( ?string $utc_datetime, string $format = 'Y-m-d H:i', string $fallback = '—' ): string {
		if ( empty( $utc_datetime ) ) {
			return $fallback;
		}

		$timestamp = strtotime( $utc_datetime . ' UTC' );

		if ( false === $timestamp ) {
			return $fallback;
		}

		return self::format( $timestamp, $format ) ?: $fallback;
	}

	/**
	 * The configured format, falling back to the documented default.
	 *
	 * @return string
	 */
	public static function setting_format(): string {
		$format = (string) Settings::get( 'date_format', 'Y-m-d H:i' );

		return '' === trim( $format ) ? 'Y-m-d H:i' : $format;
	}

	/**
	 * Local calendar parts of a timestamp, in the site timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return array{year:int,month:int,day:int,hour:int,minute:int,second:int}
	 */
	public static function local_parts( int $timestamp ): array {
		if ( function_exists( 'wp_date' ) ) {
			$iso = (string) wp_date( 'Y-n-j-G-i-s', $timestamp );
		} else {
			$iso = gmdate( 'Y-n-j-G-i-s', $timestamp );
		}

		$pieces = array_map( 'intval', explode( '-', $iso ) );

		return array(
			'year'   => $pieces[0] ?? 1970,
			'month'  => $pieces[1] ?? 1,
			'day'    => $pieces[2] ?? 1,
			'hour'   => $pieces[3] ?? 0,
			'minute' => $pieces[4] ?? 0,
			'second' => $pieces[5] ?? 0,
		);
	}

	/**
	 * Site timezone name, or '' when WordPress is unavailable.
	 *
	 * @return string
	 */
	public static function timezone_name(): string {
		if ( ! function_exists( 'wp_timezone_string' ) ) {
			return '';
		}

		$name = (string) wp_timezone_string();

		if ( '' === $name ) {
			return '';
		}

		// A raw offset like "+03:30" is not a DateTimeZone name, but
		// DateTimeZone understands exactly that syntax, so pass it through.
		return $name;
	}

	/**
	 * Whether Persian digits are enabled.
	 *
	 * @return bool
	 */
	private static function use_persian_digits(): bool {
		return (bool) Settings::get( 'use_persian_digits', false );
	}
}
