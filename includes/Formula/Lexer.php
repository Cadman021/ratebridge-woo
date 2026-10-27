<?php
/**
 * Token definitions and the lexer.
 *
 * Pure PHP, no WordPress dependency. The lexer turns normalised ASCII source
 * into a flat token list; it never evaluates anything and rejects anything it
 * does not understand (which is what keeps the interpreter injection-proof).
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * A single token.
 */
final class Token {

	/**
	 * Token kinds.
	 */
	public const NUMBER      = 'number';
	public const IDENT       = 'ident';
	public const OPERATOR    = 'operator';
	public const LPAREN      = 'lparen';
	public const RPAREN      = 'rparen';
	public const COMMA       = 'comma';
	public const END         = 'end';

	/**
	 * Constructor.
	 *
	 * @param string $type   One of the class constants.
	 * @param string $value  Raw text ('' for structural tokens).
	 * @param float  $number Numeric value for NUMBER tokens.
	 * @param int    $pos    Character offset in the normalised source.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $value,
		public readonly float $number = 0.0,
		public readonly int $pos = 0
	) {}

	/**
	 * Whether this is the given operator/keyword.
	 *
	 * @param string $value Operator text.
	 * @return bool
	 */
	public function is( string $value ): bool {
		return $this->type === self::OPERATOR && $this->value === $value;
	}

	/**
	 * Debug representation (used by the tests).
	 *
	 * @return string
	 */
	public function to_string(): string {
		return self::NUMBER === $this->type
			? 'number(' . $this->value . ')'
			: $this->type . '(' . $this->value . ')';
	}
}

/**
 * Turns normalised source into tokens.
 */
final class Lexer {

	/**
	 * Hard token limit (protects the parser from absurd input).
	 */
	public const MAX_TOKENS = 400;

	/**
	 * Maximum length of a single number literal.
	 */
	public const MAX_NUMBER_LENGTH = 30;

	/**
	 * Multi-character operators, longest first so `<=` wins over `<`.
	 *
	 * @var string[]
	 */
	private const OPERATORS = array( '<=', '>=', '==', '!=', '&&', '||', '+', '-', '*', '/', '%', '^', '<', '>', '!' );

	/**
	 * Tokenize normalised source.
	 *
	 * @param string $source Normalised ASCII source.
	 * @return array{tokens:Token[],error:string,position:int}
	 */
	public static function tokenize( string $source ): array {
		$tokens = array();
		$length = strlen( $source );
		$i      = 0;

		while ( $i < $length ) {
			$char = $source[ $i ];

			// Whitespace (the normalizer already collapsed runs).
			if ( ' ' === $char ) {
				++$i;
				continue;
			}

			if ( count( $tokens ) >= self::MAX_TOKENS ) {
				return self::fail( 'too_many_tokens', $i );
			}

			// Numbers: digits with an optional single decimal point.
			if ( self::is_digit( $char ) || ( '.' === $char && $i + 1 < $length && self::is_digit( $source[ $i + 1 ] ) ) ) {
				$start = $i;
				$seen  = 0;

				while ( $i < $length && ( self::is_digit( $source[ $i ] ) || '.' === $source[ $i ] ) ) {
					if ( '.' === $source[ $i ] ) {
						++$seen;

						if ( $seen > 1 ) {
							return self::fail( 'bad_number', $i );
						}
					}

					++$i;
				}

				$literal = substr( $source, $start, $i - $start );

				if ( strlen( $literal ) > self::MAX_NUMBER_LENGTH ) {
					return self::fail( 'number_too_long', $start );
				}

				// Reject a trailing dot: "1." is a typo, not a number.
				if ( '.' === substr( $literal, -1 ) ) {
					return self::fail( 'bad_number', $start );
				}

				$number = (float) $literal;

				if ( ! is_finite( $number ) ) {
					return self::fail( 'number_out_of_range', $start );
				}

				/*
				 * Exponent notation is not part of the grammar. `1e999` arrives
				 * here as NUMBER(1) followed by IDENT(e999) and is rejected by
				 * the parser as an unexpected token — no special case needed.
				 */
				$tokens[] = new Token( Token::NUMBER, $literal, $number, $start );
				continue;
			}

			// Identifiers / keywords / function names: ASCII only.
			if ( self::is_ident_start( $char ) ) {
				$start = $i;

				while ( $i < $length && self::is_ident_part( $source[ $i ] ) ) {
					++$i;
				}

				$text = substr( $source, $start, $i - $start );

				if ( strlen( $text ) > 40 ) {
					return self::fail( 'identifier_too_long', $start );
				}

				$lower = strtolower( $text );

				// Keywords behave as operators so the parser stays simple.
				if ( in_array( $lower, array( 'and', 'or', 'not' ), true ) ) {
					$tokens[] = new Token( Token::OPERATOR, $lower, 0.0, $start );
					continue;
				}

				$tokens[] = new Token( Token::IDENT, $text, 0.0, $start );
				continue;
			}

			if ( '(' === $char ) {
				$tokens[] = new Token( Token::LPAREN, '(', 0.0, $i );
				++$i;
				continue;
			}

			if ( ')' === $char ) {
				$tokens[] = new Token( Token::RPAREN, ')', 0.0, $i );
				++$i;
				continue;
			}

			if ( ',' === $char ) {
				$tokens[] = new Token( Token::COMMA, ',', 0.0, $i );
				++$i;
				continue;
			}

			// Operators (longest match first).
			$matched = '';

			foreach ( self::OPERATORS as $operator ) {
				if ( substr( $source, $i, strlen( $operator ) ) === $operator ) {
					$matched = $operator;
					break;
				}
			}

			if ( '' !== $matched ) {
				$tokens[] = new Token( Token::OPERATOR, $matched, 0.0, $i );
				$i      += strlen( $matched );
				continue;
			}

			return self::fail( 'unexpected_char', $i );
		}

		if ( count( $tokens ) >= self::MAX_TOKENS ) {
			return self::fail( 'too_many_tokens', $length );
		}

		$tokens[] = new Token( Token::END, '', 0.0, $length );

		return array(
			'tokens'   => $tokens,
			'error'    => '',
			'position' => 0,
		);
	}

	/**
	 * ASCII digit test.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private static function is_digit( string $char ): bool {
		return $char >= '0' && $char <= '9';
	}

	/**
	 * Identifier start: ASCII letter or underscore.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private static function is_ident_start( string $char ): bool {
		return ( $char >= 'a' && $char <= 'z' ) || ( $char >= 'A' && $char <= 'Z' ) || '_' === $char;
	}

	/**
	 * Identifier continuation.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private static function is_ident_part( string $char ): bool {
		return self::is_ident_start( $char ) || self::is_digit( $char );
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $code     Reason code.
	 * @param int    $position Offset.
	 * @return array{tokens:Token[],error:string,position:int}
	 */
	private static function fail( string $code, int $position ): array {
		return array(
			'tokens'   => array(),
			'error'    => $code,
			'position' => $position,
		);
	}
}
