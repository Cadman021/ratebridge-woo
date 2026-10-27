<?php
/**
 * Normalizer: makes a user-typed formula safe to tokenize.
 *
 * Handles the things a Persian keyboard produces: Persian/Arabic-Indic digits,
 * the Arabic decimal separator, multiplication/division/minus symbols, full-width
 * parentheses, zero-width joiners and stray bidi marks.
 *
 * Number rules (documented in README):
 *  - `.` and `٫` (U+066B) are the decimal separator, `,` and `،` are argument
 *    separators only;
 *  - thousands separators INSIDE a number are not allowed (`26,051,530` is a
 *    syntax error, `26051530` is accepted);
 *  - the output is pure ASCII, so every later stage sees one representation.
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Convert a raw expression into normalised ASCII source.
 */
final class Normalizer {

	/**
	 * Maximum accepted input length (characters, before normalisation).
	 */
	public const MAX_LENGTH = 2000;

	/**
	 * Characters removed entirely (zero-width / bidi control characters).
	 *
	 * @var string[]
	 */
	private const STRIP = array(
		"\xE2\x80\x8C", // ZWNJ
		"\xE2\x80\x8D", // ZWJ
		"\xE2\x80\x8E", // LRM
		"\xE2\x80\x8F", // RLM
		"\xE2\x80\xAA", // LRE
		"\xE2\x80\xAB", // RLE
		"\xE2\x80\xAC", // PDF
		"\xE2\x80\xAD", // LRO
		"\xE2\x80\xAE", // RLO
		"\xE2\x81\xA0", // word joiner
		"\xEF\xBB\xBF", // BOM
		"\xC2\xA0",     // NBSP
	);

	/**
	 * Normalise an expression.
	 *
	 * @param string $input Raw expression from the admin.
	 * @return array{source:string,error:string,position:int} {
	 *     @type string $source   Normalised ASCII source ('' on error).
	 *     @type string $error    Reason code ('' when fine).
	 *     @type int    $position Character offset in the *input* that caused it.
	 * }
	 */
	public static function normalize( string $input ): array {
		$input = trim( $input );

		if ( '' === $input ) {
			return self::fail( 'empty', 0 );
		}

		if ( self::length( $input ) > self::MAX_LENGTH ) {
			return self::fail( 'too_long', self::MAX_LENGTH );
		}

		// Remove invisible characters first: they would otherwise shift offsets.
		$text = str_replace( self::STRIP, '', $input );

		// Digits: Persian (۰-۹) and Arabic-Indic (٠-٩) to ASCII.
		$text = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			$text
		);

		// Operators and punctuation with a canonical ASCII form.
		$text = str_replace(
			array(
				"\xC3\x97", // × multiplication sign
				"\xC3\xB7", // ÷ division sign
				"\xE2\x88\x92", // − minus sign (U+2212)
				"\xEF\xBC\x88", // （ full-width (
				"\xEF\xBC\x89", // ） full-width )
				"\xEF\xBC\x8B", // ＋
				"\xEF\xBC\x8D", // －
				"\xEF\xBC\x8A", // ＊
				"\xEF\xBC\x8F", // ／
				"\xD8\x8C", // U+060C Persian comma           -> argument separator
				"\xD9\xAC", // U+066C Arabic thousands sep    -> argument separator
				"\xD9\xAB", // U+066B Arabic decimal sep      -> decimal point
			),
			array( '*', '/', '-', '(', ')', '+', '-', '*', '/', ',', ',', '.' ),
			$text
		);

		// Collapse all whitespace runs (including tabs/newlines) to one space.
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

		return array(
			'source'   => trim( $text ),
			'error'    => '',
			'position' => 0,
		);
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $code     Reason code.
	 * @param int    $position Offset.
	 * @return array{source:string,error:string,position:int}
	 */
	private static function fail( string $code, int $position ): array {
		return array(
			'source'   => '',
			'error'    => $code,
			'position' => $position,
		);
	}

	/**
	 * Multibyte-safe length with an ASCII fast path.
	 *
	 * @param string $value Text.
	 * @return int
	 */
	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
