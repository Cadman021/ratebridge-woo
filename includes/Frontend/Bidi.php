<?php
/**
 * Bidirectional (RTL/LTR) text helpers.
 *
 * Persian sentences routinely contain Latin fragments — ISO dates, numbers in
 * parentheses, currency codes, slugs, cron hook names. Without isolation the
 * Unicode bidi algorithm re-orders those fragments against the surrounding RTL
 * run and the result is unreadable: `29-10-2026` renders as `2026-10-29` garbled
 * and `(1,234,567)` moves the parenthesis to the wrong side.
 *
 * The fix is to wrap every such fragment in `<bdi dir="ltr">…</bdi>`:
 *  - `<bdi>` isolates the fragment from the paragraph direction (no U+2068/U+2069
 *    litter in `textContent`, and it is announced correctly by screen readers);
 *  - `dir="ltr"` pins the fragment's own base direction, so digits, `-`, `/` and
 *    brackets keep their logical order;
 *  - because the fragment is *isolated*, the surrounding punctuation (the `(`
 *    and `)` of a Persian sentence, a trailing `:`) stays on the correct side.
 *
 * All methods are pure and escape their input, so callers may echo the result
 * directly. Every helper returns a plain escaped string when the setting says the
 * output is LTR already (`en_US`): isolation is only needed in RTL runs.
 *
 * @package RateBridge
 */

namespace RateBridge\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Isolation helpers for embedded LTR fragments.
 */
final class Bidi {

	/**
	 * Wrap an already-escaped fragment in an LTR isolate.
	 *
	 * @param string $escaped Fragment that is ALREADY escaped for HTML output.
	 * @return string
	 */
	public static function isolate_escaped( string $escaped ): string {
		if ( '' === $escaped ) {
			return '';
		}

		return '<bdi dir="ltr">' . $escaped . '</bdi>';
	}

	/**
	 * Escape a raw fragment and isolate it.
	 *
	 * @param string $text Raw (unescaped) text.
	 * @return string
	 */
	public static function isolate( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		return self::isolate_escaped( esc_html( $text ) );
	}

	/**
	 * Isolate a number-like value with the site's digit preference.
	 *
	 * Persian digits are presentation-only; the value keeps LTR base direction so
	 * a thousands-separated figure never re-orders around a minus sign.
	 *
	 * @param string|int|float $value    Value to print.
	 * @param bool             $persian  Convert ASCII digits to Persian digits.
	 * @param int              $decimals Decimals for float input (-1 = as given).
	 * @return string
	 */
	public static function number( string|int|float $value, bool $persian = false, int $decimals = -1 ): string {
		if ( is_float( $value ) ) {
			$text = number_format( $value, $decimals >= 0 ? $decimals : 0, '.', ',' );
		} elseif ( is_int( $value ) ) {
			$text = number_format( $value, 0, '.', ',' );
		} else {
			$text = (string) $value;
		}

		if ( $persian ) {
			$text = RateFormatter::to_persian_digits( $text );
		}

		return self::isolate( $text );
	}

	/**
	 * Isolate a machine identifier (code, slug, cron hook, symbol).
	 *
	 * @param string $code Identifier.
	 * @return string
	 */
	public static function code( string $code ): string {
		return self::isolate( $code );
	}

	/**
	 * Isolate a Latin word or short label that must not be re-ordered.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function ltr( string $text ): string {
		return self::isolate( $text );
	}
}
