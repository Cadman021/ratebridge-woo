<?php
/**
 * Rate formatting service and template engine.
 *
 * Deliberately free of WordPress and WooCommerce APIs so the whole thing can be
 * unit tested from the command line (`tests/rate-formatter.php`). Anything that
 * needs WordPress (locale, kses, transients) is passed in by the caller.
 *
 * @package RateBridge
 */

namespace RateBridge\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Formats rates/money/time and renders `%placeholder%` templates.
 */
final class RateFormatter {

	/**
	 * Persian digits (۰۱۲۳۴۵۶۷۸۹).
	 */
	private const PERSIAN_DIGITS = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	/**
	 * ASCII digits, index-aligned with PERSIAN_DIGITS.
	 */
	private const ASCII_DIGITS = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

	/**
	 * Placeholders the template engine understands.
	 *
	 * @return string[]
	 */
	public static function placeholders(): array {
		return array( 'name', 'code', 'symbol', 'rate', 'unit', 'updated', 'updated_date', 'source' );
	}

	/**
	 * Format a number with thousand separators and a fixed number of decimals.
	 *
	 * The separators are plain ASCII `,` and `.` on purpose: the site locale may
	 * be Persian while prices must stay machine-readable (and must not be
	 * silently converted to Persian digits by a locale formatter).
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Decimal places (0 for Toman/Rial).
	 * @return string
	 */
	public static function number( float $value, int $decimals = 0 ): string {
		$decimals = max( 0, min( 6, $decimals ) );

		if ( ! is_finite( $value ) ) {
			return '';
		}

		return number_format( $value, $decimals, '.', ',' );
	}

	/**
	 * Convert ASCII digits to Persian digits.
	 *
	 * Only digits are touched: separators, signs and Latin words are preserved.
	 *
	 * @param string $value Text containing ASCII digits.
	 * @return string
	 */
	public static function to_persian_digits( string $value ): string {
		return str_replace( self::ASCII_DIGITS, self::PERSIAN_DIGITS, $value );
	}

	/**
	 * Conditionally convert digits to Persian.
	 *
	 * @param string $value    Text.
	 * @param bool   $persian  Whether to convert.
	 * @return string
	 */
	public static function maybe_persian( string $value, bool $persian ): string {
		return $persian ? self::to_persian_digits( $value ) : $value;
	}

	/**
	 * Unit label (Toman/Rial) for a store unit key.
	 *
	 * Kept dependency-free: the caller passes the already-translated label.
	 *
	 * @param string $unit     'toman' or 'rial'.
	 * @param string $toman    Label for Toman.
	 * @param string $rial     Label for Rial.
	 * @return string
	 */
	public static function unit_label( string $unit, string $toman = 'تومان', string $rial = 'ریال' ): string {
		return 'rial' === $unit ? $rial : $toman;
	}

	/**
	 * Human "time ago" string.
	 *
	 * Pure function: both timestamps are arguments so it can be unit tested.
	 * Returns an array so the caller can translate the label and pluralise.
	 *
	 * @param int      $timestamp Past timestamp (0/negative = unknown).
	 * @param int|null $now       Reference timestamp (defaults to time()).
	 * @return array{unit:string,value:int,direction:string} {
	 *     @type string $unit      second|minute|hour|day|month|year|unknown|now.
	 *     @type int    $value     How many of that unit.
	 *     @type string $direction 'past' when the timestamp is before now.
	 * }
	 */
	public static function time_ago_parts( int $timestamp, ?int $now = null ): array {
		$now = $now ?? time();

		if ( $timestamp <= 0 ) {
			return array(
				'unit'      => 'unknown',
				'value'     => 0,
				'direction' => 'past',
			);
		}

		$diff = $now - $timestamp;

		if ( $diff < 0 ) {
			// A future timestamp (clock skew): treat as "just now".
			$diff = 0;
		}

		$minutes = (int) floor( $diff / 60 );

		if ( $diff < 60 ) {
			return array(
				'unit'      => 'now',
				'value'     => 0,
				'direction' => 'past',
			);
		}

		if ( $minutes < 60 ) {
			return array(
				'unit'      => 'minute',
				'value'     => $minutes,
				'direction' => 'past',
			);
		}

		$hours = (int) floor( $minutes / 60 );

		if ( $hours < 24 ) {
			return array(
				'unit'      => 'hour',
				'value'     => $hours,
				'direction' => 'past',
			);
		}

		$days = (int) floor( $hours / 24 );

		if ( $days < 30 ) {
			return array(
				'unit'      => 'day',
				'value'     => $days,
				'direction' => 'past',
			);
		}

		$months = (int) floor( $days / 30 );

		if ( $months < 12 ) {
			return array(
				'unit'      => 'month',
				'value'     => $months,
				'direction' => 'past',
			);
		}

		return array(
			'unit'      => 'year',
			'value'     => (int) floor( $months / 12 ),
			'direction' => 'past',
		);
	}

	/**
	 * Default English/Persian-agnostic "time ago" text.
	 *
	 * The caller normally localises instead (see Frontend::time_ago()), but this
	 * keeps the class usable on its own and in tests.
	 *
	 * @param int      $timestamp Past timestamp.
	 * @param int|null $now       Reference timestamp.
	 * @return string
	 */
	public static function time_ago( int $timestamp, ?int $now = null ): string {
		$parts = self::time_ago_parts( $timestamp, $now );

		if ( 'unknown' === $parts['unit'] ) {
			return '—';
		}

		if ( 'now' === $parts['unit'] ) {
			return 'now';
		}

		return sprintf( '%d %s ago', $parts['value'], $parts['unit'] );
	}

	/**
	 * Render a `%placeholder%` template.
	 *
	 * Unknown placeholders are left untouched (so a typo is visible instead of
	 * silently disappearing). Values are plain text: escaping is the caller's
	 * job, never the template's.
	 *
	 * @param string                $template Template with `%name%` tokens.
	 * @param array<string, string> $values   Placeholder => replacement.
	 * @return string
	 */
	public static function render_template( string $template, array $values ): string {
		if ( '' === $template ) {
			return '';
		}

		$replacements = array();

		foreach ( $values as $key => $value ) {
			$key = is_string( $key ) ? trim( $key, '%' ) : '';

			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$replacements[ '%' . $key . '%' ] = (string) $value;
		}

		if ( empty( $replacements ) ) {
			return $template;
		}

		/*
		 * A single strtr() pass: a replacement value that happens to contain
		 * `%code%` must not be expanded again (that would let user data inject a
		 * placeholder), and no placeholder can be expanded twice.
		 */
		return strtr( $template, $replacements );
	}

	/**
	 * Sanitise a template so it may only contain a tiny, safe HTML subset.
	 *
	 * The caller passes a kses callback; when none is given the template is
	 * reduced to its plain text content. `%placeholders%` survive kses because
	 * they contain no markup characters.
	 *
	 * @param string        $template Raw template.
	 * @param callable|null $kses     kses-like callable: (string): string.
	 * @return string
	 */
	public static function sanitize_template( string $template, ?callable $kses = null ): string {
		$template = trim( $template );

		if ( '' === $template ) {
			return '';
		}

		// Hard length cap: a template is a label, not a page.
		if ( mb_strlen( $template ) > 500 ) {
			$template = mb_substr( $template, 0, 500 );
		}

		if ( is_callable( $kses ) ) {
			return (string) call_user_func( $kses, $template );
		}

		return self::strip_tags_keep_placeholders( $template );
	}

	/**
	 * Strip every tag, keeping `%placeholder%` text intact.
	 *
	 * @param string $template Raw template.
	 * @return string
	 */
	private static function strip_tags_keep_placeholders( string $template ): string {
		return self::strip_tags_keep_text( $template );
	}

	/**
	 * Minimal `wp_strip_all_tags()` replacement for the no-WordPress fallback.
	 *
	 * Only reached when the caller supplied no kses callback, which never
	 * happens inside WordPress (where `wp_kses()` is always available).
	 *
	 * @param string $value Text with possible tags.
	 * @return string
	 */
	private static function strip_tags_keep_text( string $value ): string {
		$value = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $value ) ?? '';
		$value = strip_tags( $value );

		return trim( preg_replace( '/[
	 ]+/', ' ', $value ) ?? '' );
	}

	/**
	 * Format a timestamp with a token-based date pattern.
	 *
	 * Pure replacement of a small, documented token set (PHP date() is locale
	 * hostile and `wp_date()` needs WordPress), so tests can pin the output.
	 *
	 * Tokens: `Y` year, `m` month 01-12, `d` day 01-31, `H` hour 00-23,
	 * `i` minute 00-59, `s` second, `j` day without leading zero.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $format    Token pattern, e.g. 'Y-m-d H:i'.
	 * @param string $timezone  Optional timezone name ('' = current default).
	 * @return string
	 */
	public static function format_date( int $timestamp, string $format, string $timezone = '' ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		$format = '' === trim( $format ) ? 'Y-m-d H:i' : $format;

		if ( '' !== $timezone ) {
			try {
				$date = new \DateTimeImmutable( '@' . $timestamp );
				$date = $date->setTimezone( new \DateTimeZone( $timezone ) );
			} catch ( \Exception $e ) {
				$date = new \DateTimeImmutable( '@' . $timestamp );
			}
		} else {
			$date = new \DateTimeImmutable( '@' . $timestamp );
		}

		$map = array(
			'Y' => $date->format( 'Y' ),
			'm' => $date->format( 'm' ),
			'd' => $date->format( 'd' ),
			'j' => $date->format( 'j' ),
			'H' => $date->format( 'H' ),
			'i' => $date->format( 'i' ),
			's' => $date->format( 's' ),
		);

		// Walk the format so literal characters outside the token set survive.
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
}
