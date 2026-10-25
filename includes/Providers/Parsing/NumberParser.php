<?php
/**
 * Numeric parsing helpers shared by provider parsers.
 *
 * Deliberately free of WordPress dependencies so it can be unit tested from
 * the CLI (see tests/parse-fixtures.php).
 *
 * @package RateBridge
 */

namespace RateBridge\Providers\Parsing;

defined( 'ABSPATH' ) || exit;

/**
 * Turns messy provider values into finite positive floats.
 */
final class NumberParser {

	/**
	 * Persian (۰-۹ / U+06F0..U+06F9) and Arabic-Indic (٠-٩ / U+0660..U+0669) digits.
	 */
	private const DIGIT_MAP = array(
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
		'٫' => '.', // Arabic decimal separator.
		'٬' => '',  // Arabic thousands separator.
	);

	/**
	 * Parse a provider value into a positive finite float.
	 *
	 * Handles: ints, floats, numeric strings, strings with thousands separators
	 * ("," or "٬"), Persian/Arabic-Indic digits, surrounding whitespace,
	 * Persian decimal separators, and a leading currency word.
	 *
	 * @param mixed $value Raw value from the API.
	 * @return float|null Null when the value is not a usable positive number.
	 */
	public static function to_positive_float( mixed $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			$float = (float) $value;

			return ( is_finite( $float ) && $float > 0 ) ? $float : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$clean = strtr( $value, self::DIGIT_MAP );
		$clean = trim( $clean );

		if ( '' === $clean ) {
			return null;
		}

		// Drop thousands separators and any non-numeric decoration
		// (currency words, spaces, NBSP, RTL marks) but keep sign and dot.
		$clean = str_replace( array( ',', "\xC2\xA0", "\xE2\x80\x8C", "\xE2\x80\x8F", ' ' ), '', $clean );
		$clean = preg_replace( '/[^0-9.\-+eE]/', '', $clean ) ?? '';

		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return null;
		}

		$float = (float) $clean;

		return ( is_finite( $float ) && $float > 0 ) ? $float : null;
	}

	/**
	 * Convert Persian/Arabic-Indic digits in a slug-ish string to ASCII.
	 *
	 * @param string $value Raw string.
	 * @return string
	 */
	public static function normalize_digits( string $value ): string {
		return strtr( $value, self::DIGIT_MAP );
	}
}
