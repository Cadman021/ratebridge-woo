<?php
/**
 * Whitelisted function table.
 *
 * There is no dynamic dispatch on user input: the evaluator looks the name up in
 * this fixed list and calls one of the closures below. Unknown names never reach
 * a call, they are rejected by the parser.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Function metadata and implementations.
 */
final class Functions {

	/**
	 * Minimum/maximum arity per function (max = null means variadic).
	 *
	 * @return array<string, array{0:int,1:int|null}>
	 */
	public static function signatures(): array {
		return array(
			'min'     => array( 1, null ),
			'max'     => array( 1, null ),
			'round'   => array( 1, 2 ),
			'ceil'    => array( 1, 1 ),
			'floor'   => array( 1, 1 ),
			'abs'     => array( 1, 1 ),
			'pow'     => array( 2, 2 ),
			'sqrt'    => array( 1, 1 ),
			'clamp'   => array( 3, 3 ),
			'if'      => array( 3, 3 ),
			'percent' => array( 2, 2 ),
		);
	}

	/**
	 * Reserved words that can never be variable names.
	 *
	 * @return string[]
	 */
	public static function reserved(): array {
		return array_merge( array_keys( self::signatures() ), array( 'and', 'or', 'not', 'true', 'false' ) );
	}

	/**
	 * Whether a name is a known function.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public static function is_function( string $name ): bool {
		return array_key_exists( strtolower( $name ), self::signatures() );
	}

	/**
	 * Whether a name is reserved.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_reserved( string $name ): bool {
		return in_array( strtolower( $name ), self::reserved(), true );
	}

	/**
	 * Arity of a function.
	 *
	 * @param string $name Function name.
	 * @return array{0:int,1:int|null}|null
	 */
	public static function arity( string $name ): ?array {
		return self::signatures()[ strtolower( $name ) ] ?? null;
	}

	/**
	 * Whether a function must evaluate its arguments lazily.
	 *
	 * `if` only evaluates the branch it needs, which matters for `x / y` in an
	 * unused branch.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public static function is_lazy( string $name ): bool {
		return 'if' === strtolower( $name );
	}

	/**
	 * Apply a function to already-evaluated arguments.
	 *
	 * @param string  $name Function name (already validated).
	 * @param float[] $args Arguments.
	 * @return float|FormulaError
	 */
	public static function apply( string $name, array $args ): float|FormulaError {
		$name = strtolower( $name );

		switch ( $name ) {
			case 'min':
				return (float) min( $args );

			case 'max':
				return (float) max( $args );

			case 'round':
				$decimals = isset( $args[1] ) ? (int) max( 0, min( 10, round( $args[1] ) ) ) : 0;

				return (float) round( $args[0], $decimals );

			case 'ceil':
				return (float) ceil( $args[0] );

			case 'floor':
				return (float) floor( $args[0] );

			case 'abs':
				return (float) abs( $args[0] );

			case 'pow':
				$result = $args[0] ** $args[1];

				return is_finite( $result ) ? (float) $result : new FormulaError( 'not_finite', 0, 'pow' );

			case 'sqrt':
				if ( $args[0] < 0 ) {
					return new FormulaError( 'negative_sqrt', 0, 'sqrt' );
				}

				return (float) sqrt( $args[0] );

			case 'clamp':
				$low  = min( $args[1], $args[2] );
				$high = max( $args[1], $args[2] );

				return (float) max( $low, min( $high, $args[0] ) );

			case 'percent':
				return (float) ( $args[0] * $args[1] / 100.0 );
		}

		return new FormulaError( 'unknown_function', 0, $name );
	}
}
