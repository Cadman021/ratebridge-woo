<?php
/**
 * Dependency-free test suite for the formula engine.
 *
 * Run from the plugin root:
 *     php tests/formula-engine.php
 *
 * No WordPress is loaded: the whole `RateBridge\Formula` namespace is pure PHP.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/Formula/Normalizer.php';
require_once __DIR__ . '/../includes/Formula/Lexer.php';
require_once __DIR__ . '/../includes/Formula/Node.php';
require_once __DIR__ . '/../includes/Formula/FormulaError.php';
require_once __DIR__ . '/../includes/Formula/Functions.php';
require_once __DIR__ . '/../includes/Formula/Parser.php';
require_once __DIR__ . '/../includes/Formula/Evaluator.php';
require_once __DIR__ . '/../includes/Formula/CompiledFormula.php';
require_once __DIR__ . '/../includes/Formula/Formula.php';

use RateBridge\Formula\CompiledFormula;
use RateBridge\Formula\Formula;
use RateBridge\Formula\FormulaError;
use RateBridge\Formula\Result;

/**
 * Tiny PASS/FAIL collector.
 */
final class FAssert {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Record a boolean check.
	 *
	 * @param string $label Description.
	 * @param bool   $ok    Result.
	 * @param string $info  Extra info on failure.
	 * @return bool
	 */
	public static function check( string $label, bool $ok, string $info = '' ): bool {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );
		} else {
			++self::$failed;
			printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
		}

		return $ok;
	}

	/**
	 * Assert a compiled expression evaluates to an expected float.
	 *
	 * @param string               $expr     Expression.
	 * @param float                $expected Expected value.
	 * @param array<string, float> $vars     Variable values.
	 * @param float                $epsilon  Tolerance.
	 * @return void
	 */
	public static function value( string $expr, float $expected, array $vars = array(), float $epsilon = 0.000001 ): void {
		$compiled = Formula::compile( $expr );

		if ( $compiled instanceof FormulaError ) {
			self::check(
				sprintf( '%s => %s', $expr, self::fmt( $expected ) ),
				false,
				'compile error: ' . $compiled->code . ' @' . $compiled->position
			);

			return;
		}

		$result = $compiled->evaluate( $vars );

		if ( $result instanceof FormulaError ) {
			self::check(
				sprintf( '%s => %s', $expr, self::fmt( $expected ) ),
				false,
				'eval error: ' . $result->code
			);

			return;
		}

		self::check(
			sprintf( '%s => %s', $expr, self::fmt( $expected ) ),
			abs( $result->value - $expected ) <= $epsilon,
			'got ' . self::fmt( $result->value )
		);
	}

	/**
	 * Assert compilation fails with a specific code.
	 *
	 * @param string $expr     Expression.
	 * @param string $code     Expected error code.
	 * @param int    $position Expected position (-1 = do not check).
	 * @return void
	 */
	public static function error( string $expr, string $code, int $position = -1 ): void {
		$compiled = Formula::compile( $expr );

		if ( ! $compiled instanceof FormulaError ) {
			self::check( sprintf( '%s => error %s', self::short( $expr ), $code ), false, 'compiled successfully' );

			return;
		}

		$ok = $compiled->code === $code;

		if ( $ok && $position >= 0 ) {
			$ok = $compiled->position === $position;
		}

		self::check(
			sprintf( '%s => error %s%s', self::short( $expr ), $code, $position >= 0 ? '@' . $position : '' ),
			$ok,
			'got ' . $compiled->code . '@' . $compiled->position
		);
	}

	/**
	 * Assert evaluation fails (compiles but cannot be evaluated).
	 *
	 * @param string               $expr Expression.
	 * @param string               $code Expected error code.
	 * @param array<string, float> $vars Variables.
	 * @return void
	 */
	public static function eval_error( string $expr, string $code, array $vars = array() ): void {
		$compiled = Formula::compile( $expr );
		$label    = sprintf( '%s => eval error %s', self::short( $expr ), $code );

		if ( $compiled instanceof FormulaError ) {
			self::check( $label, false, 'compile error: ' . $compiled->code );

			return;
		}

		$result = $compiled->evaluate( $vars );

		self::check(
			$label,
			$result instanceof FormulaError && $result->code === $code,
			$result instanceof FormulaError ? 'got ' . $result->code : 'got value ' . self::fmt( $result->value )
		);
	}

	/**
	 * Shorten an expression for display.
	 *
	 * @param string $expr Expression.
	 * @return string
	 */
	private static function short( string $expr ): string {
		$flat = preg_replace( '/\s+/', ' ', trim( $expr ) ) ?? $expr;
		$flat = function_exists( 'mb_substr' ) ? mb_substr( $flat, 0, 44 ) : substr( $flat, 0, 44 );

		return '"' . $flat . '"';
	}

	/**
	 * Format a number for display.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function fmt( float $value ): string {
		return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
	}
}

echo "RateBridge Woo — formula engine\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Precedence, associativity, parentheses, unary signs\n";
/* ------------------------------------------------------------------------- */
FAssert::value( '2+3*4', 14.0 );
FAssert::value( '2*3+4', 10.0 );
FAssert::value( '(2+3)*4', 20.0 );
FAssert::value( '2+3*4-6/2', 11.0 );
FAssert::value( '10-2-3', 5.0 );           // left associative
FAssert::value( '100/10/2', 5.0 );         // left associative
FAssert::value( '2^3^2', 512.0 );          // right associative
FAssert::value( '-2^2', -4.0 );            // unary minus binds looser than ^
FAssert::value( '(-2)^2', 4.0 );
FAssert::value( '-(2^2)', -4.0 );
FAssert::value( '2^-1', 0.5 );             // negative exponent
FAssert::value( '-(-3)', 3.0 );
FAssert::value( '+-+3', -3.0 );
FAssert::value( '--3', 3.0 );
FAssert::value( '((((5))))', 5.0 );
FAssert::value( '2*(3+(4*5))', 46.0 );
FAssert::value( '10%3', 1.0 );
FAssert::value( '-10%3', -1.0 );           // fmod keeps the sign of the dividend
FAssert::value( '2*3^2', 18.0 );           // ^ binds tighter than *
FAssert::value( '1+2*3^2', 19.0 );

/* ------------------------------------------------------------------------- */
echo "\n[2] Numbers and decimals\n";
/* ------------------------------------------------------------------------- */
FAssert::value( '1', 1.0 );
FAssert::value( '1.5', 1.5 );
FAssert::value( '0.25', 0.25 );
FAssert::value( '.5', 0.5 );
FAssert::value( '0.1+0.2', 0.3 );
FAssert::value( '1234567890', 1234567890.0 );
FAssert::value( '26051530*8', 208412240.0 );
FAssert::value( '1.5*2', 3.0 );
FAssert::error( '1.', 'bad_number' );
FAssert::error( '1.2.3', 'bad_number' );
FAssert::error( str_repeat( '9', 31 ), 'number_too_long' );

/* ------------------------------------------------------------------------- */
echo "\n[3] Persian / Arabic normalisation\n";
/* ------------------------------------------------------------------------- */
FAssert::value( '۲+۳', 5.0 );
FAssert::value( '۱۲۳', 123.0 );
FAssert::value( '١٢٣', 123.0 );
FAssert::value( '۲.۵*۲', 5.0 );
FAssert::value( '۲٫۵*۲', 5.0 );            // Arabic decimal separator
FAssert::value( '۲×۳', 6.0 );              // ×
FAssert::value( '۶÷۳', 2.0 );              // ÷
FAssert::value( '۵−۳', 2.0 );              // U+2212 minus
FAssert::value( '（۲+۳）×۲', 10.0 );        // full-width parentheses
FAssert::value( "max(۱, ۲)", 2.0 );
FAssert::value( "min(۱، ۲)", 1.0 );        // Persian comma as argument separator
FAssert::value( "2\u{200C}+\u{200C}3", 5.0 ); // ZWNJ stripped
FAssert::value( "2\u{200F}*3", 6.0 );      // RLM stripped
FAssert::value( "  \u{200C} 7  ", 7.0 );
FAssert::value( "1\u{00A0}+\u{00A0}1", 2.0 ); // NBSP stripped

$norm = RateBridge\Formula\Normalizer::normalize( '۲٫۵×۳' );
FAssert::check( 'normalizer produces ASCII', $norm['source'] === '2.5*3', $norm['source'] );

// A thousands separator inside a number is a hard error (documented rule).
FAssert::error( '26,051,530', 'unexpected_token' );

/* ------------------------------------------------------------------------- */
echo "\n[4] Functions\n";
/* ------------------------------------------------------------------------- */
FAssert::value( 'min(3,1,2)', 1.0 );
FAssert::value( 'max(3,1,2)', 3.0 );
// Documented rule: round() is PHP's half-away-from-zero (2.5 -> 3, -2.5 -> -3).
FAssert::value( 'round(2.5)', 3.0 );
FAssert::value( 'round(2.4)', 2.0 );
FAssert::value( 'round(2.567,2)', 2.57 );
FAssert::value( 'round(-2.5)', -3.0 );
FAssert::value( 'ceil(2.1)', 3.0 );
FAssert::value( 'floor(2.9)', 2.0 );
FAssert::value( 'abs(-7)', 7.0 );
FAssert::value( 'pow(2,10)', 1024.0 );
FAssert::value( 'sqrt(81)', 9.0 );
FAssert::value( 'clamp(15,0,10)', 10.0 );
FAssert::value( 'clamp(-5,0,10)', 0.0 );
FAssert::value( 'clamp(5,10,0)', 5.0 );     // reversed bounds are normalised
FAssert::value( 'percent(200,15)', 30.0 );
FAssert::value( 'percent(1000,2.5)', 25.0 );
FAssert::value( 'MIN(3,4)', 3.0 );          // case insensitive
FAssert::value( 'max(min(1,2),3)', 3.0 );
FAssert::value( 'round(max(1.4,2.6),0)', 3.0 );

// Lazy `if`: the unused branch must not be evaluated.
FAssert::value( 'if(1, 10, 1/0)', 10.0 );
FAssert::value( 'if(0, 1/0, 20)', 20.0 );
FAssert::value( 'if(1>2, 100, 200)', 200.0 );
FAssert::value( 'if(1==1, 5, 6)', 5.0 );
FAssert::value( 'if(not 0, 7, 8)', 7.0 );

FAssert::error( 'nope(1)', 'unknown_function' );
FAssert::error( 'min()', 'bad_arity' );
FAssert::error( 'clamp(1,2)', 'bad_arity' );
FAssert::error( 'sqrt(1,2)', 'bad_arity' );
// min/max are variadic: many arguments are fine.
FAssert::value( 'max(1,2,3,4,5,6,7,8,9,10,11,12)', 12.0 );
FAssert::error( 'abs', 'function_needs_args' );

/* ------------------------------------------------------------------------- */
echo "\n[5] Comparisons and logic\n";
/* ------------------------------------------------------------------------- */
FAssert::value( '1<2', 1.0 );
FAssert::value( '2<1', 0.0 );
FAssert::value( '2<=2', 1.0 );
FAssert::value( '3>=4', 0.0 );
FAssert::value( '3>2', 1.0 );
FAssert::value( '1==1', 1.0 );
FAssert::value( '1!=2', 1.0 );
FAssert::value( '1 and 1', 1.0 );
FAssert::value( '1 and 0', 0.0 );
FAssert::value( '0 or 1', 1.0 );
FAssert::value( '0 or 0', 0.0 );
FAssert::value( '1 && 0', 0.0 );
FAssert::value( '1 || 0', 1.0 );
FAssert::value( 'not 0', 1.0 );
FAssert::value( 'not 5', 0.0 );
FAssert::value( '!0', 1.0 );
FAssert::value( 'true', 1.0 );
FAssert::value( 'false', 0.0 );
FAssert::value( '1>0 and 2>1', 1.0 );
FAssert::value( '0 or 0 and 1', 0.0 );      // and binds tighter than or
FAssert::value( 'not 1 == 1', 0.0 );        // not binds looser than ==
FAssert::value( '(1 or 0) and 1', 1.0 );

/* ------------------------------------------------------------------------- */
echo "\n[6] Runtime errors\n";
/* ------------------------------------------------------------------------- */
FAssert::eval_error( '1/0', 'division_by_zero' );
FAssert::eval_error( '1%0', 'division_by_zero' );
FAssert::eval_error( 'sqrt(-1)', 'negative_sqrt' );
FAssert::eval_error( '10^400', 'not_finite' );
FAssert::eval_error( 'x+1', 'unknown_variable' );
FAssert::eval_error( 'x+1', 'unknown_variable', array( 'y' => 2.0 ) );

/* ------------------------------------------------------------------------- */
echo "\n[7] Syntax errors, positions and limits\n";
/* ------------------------------------------------------------------------- */
FAssert::error( '', 'empty' );
FAssert::error( '   ', 'empty' );
FAssert::error( '1+', 'unexpected_token' );
FAssert::error( '*2', 'unexpected_token' );
FAssert::error( '(1+2', 'missing_paren' );
FAssert::error( '1+2)', 'unexpected_token' );
FAssert::error( '1 2', 'unexpected_token' );
FAssert::error( 'min(1,', 'unexpected_token' );
FAssert::error( str_repeat( '1', 2001 ), 'too_long' );
FAssert::error( '1e999', 'unexpected_token' );

// Error positions point into the normalised source.
$err = Formula::compile( '1 + * 2' );
FAssert::check(
	'error position is reported',
	$err instanceof FormulaError && $err->position === 4,
	$err instanceof FormulaError ? (string) $err->position : 'no error'
);

// Depth limit: 60 nested parentheses exceed MAX_DEPTH after parsing.
$deep = str_repeat( '(', 60 ) . '1' . str_repeat( ')', 60 );
FAssert::error( $deep, 'too_deep' );

// Call nesting limit.
$nested = str_repeat( 'abs(', 25 ) . '1' . str_repeat( ')', 25 );
FAssert::error( $nested, 'too_deep' );

// Token limit.
$tokens = implode( '+', array_fill( 0, 300, '1' ) );
FAssert::error( $tokens, 'too_many_tokens' );

/* ------------------------------------------------------------------------- */
echo "\n[8] Reserved names\n";
/* ------------------------------------------------------------------------- */
FAssert::error( 'and', 'unexpected_token' );
FAssert::check(
	'function names cannot be used as variables',
	! Formula::is_valid_variable_name( 'min' ) && ! Formula::is_valid_variable_name( 'if' )
);
FAssert::check(
	'and/or/not/true/false are reserved',
	! Formula::is_valid_variable_name( 'and' )
	&& ! Formula::is_valid_variable_name( 'true' )
	&& ! Formula::is_valid_variable_name( 'not' )
);
FAssert::check( 'gold18 is a valid name', Formula::is_valid_variable_name( 'gold18' ) );
FAssert::check( '_w is a valid name', Formula::is_valid_variable_name( '_w' ) );
FAssert::check( '1abc is invalid', ! Formula::is_valid_variable_name( '1abc' ) );
FAssert::check( 'a-b is invalid', ! Formula::is_valid_variable_name( 'a-b' ) );
FAssert::check( 'Persian name is invalid', ! Formula::is_valid_variable_name( 'وزن' ) );
FAssert::check( '41 chars is invalid', ! Formula::is_valid_variable_name( str_repeat( 'a', 41 ) ) );

/* ------------------------------------------------------------------------- */
echo "\n[9] Injection suite (nothing may execute)\n";
/* ------------------------------------------------------------------------- */
$injections = array(
	"system('id')"          => 'unknown_function',
	'`id`'                  => 'unexpected_char',
	'$x'                    => 'unexpected_char',
	'1;2'                   => 'unexpected_char',
	'1//2'                  => 'unexpected_char',
	'1/*2*/'                => 'unexpected_char',
	'\\'                    => 'unexpected_char',
	'{1}'                   => 'unexpected_char',
	'[1]'                   => 'unexpected_char',
	'1 or phpinfo()'        => 'unknown_function',
	'echo 1'                => 'unexpected_token',
	'nothing@@'             => 'unexpected_char',
	"1\x00+2"               => 'unexpected_char',
	'١٢٣system(1)'          => 'unexpected_token',
);

foreach ( $injections as $payload => $expected ) {
	$result = Formula::compile( $payload );
	$label  = sprintf( 'injection %s is rejected', json_encode( $payload ) );
	$ok     = ! $result instanceof CompiledFormula;

	FAssert::check( $label, $ok, 'COMPILED! that is a security bug' );
}

// Apostrophes/quotes are just unknown characters.
FAssert::error( "1'2", 'unexpected_char' );
FAssert::error( '1"2', 'unexpected_char' );

// A very long number is rejected before it can become INF.
FAssert::error( str_repeat( '9', 40 ), 'number_too_long' );

/* ------------------------------------------------------------------------- */
echo "\n[10] API: variables, functions, trace, check()\n";
/* ------------------------------------------------------------------------- */
$compiled = Formula::compile( 'weight * gold18 * (1 + wage / 100)' );
FAssert::check( 'compiles', $compiled instanceof CompiledFormula );

if ( $compiled instanceof CompiledFormula ) {
	$vars = $compiled->variables();
	FAssert::check( 'variables() lists all three', $vars === array( 'weight', 'gold18', 'wage' ), implode( ',', $vars ) );
	FAssert::check( 'functions() is empty', array() === $compiled->functions() );
	FAssert::check( 'depth is sane', $compiled->depth() <= 10 && $compiled->depth() >= 4, (string) $compiled->depth() );

	$result = $compiled->evaluate( array( 'weight' => 8, 'gold18' => 26051530, 'wage' => 15 ) );

	FAssert::check( 'evaluates', $result instanceof Result );
	if ( $result instanceof Result ) {
		// 8 * 26051530 = 208,412,240; * 1.15 = 239,674,076
		FAssert::check(
			'gold example value',
			abs( $result->value - 239674076.0 ) < 0.01,
			(string) $result->value
		);
		FAssert::check(
			'trace contains the values used',
			isset( $result->trace['weight'], $result->trace['gold18'], $result->trace['wage'] )
				&& 8.0 === $result->trace['weight']
		);
	}

	FAssert::check(
		'missing() reports absent variables',
		array( 'wage' ) === $compiled->missing( array( 'weight' => 1, 'gold18' => 2 ) )
	);
	FAssert::check( 'value() convenience helper', null === Formula::compile( 'a/0' )->value( array( 'a' => 1 ) ) );
}

$checked = Formula::check( 'base * usd + extra', array( 'base', 'usd', 'unused_one' ) );
FAssert::check( 'check() ok', true === $checked['ok'] );
FAssert::check( 'check() undeclared', array( 'extra' ) === $checked['undeclared'], implode( ',', $checked['undeclared'] ) );
FAssert::check( 'check() unused', array( 'unused_one' ) === $checked['unused'], implode( ',', $checked['unused'] ) );

$bad = Formula::check( '1 +', array() );
FAssert::check( 'check() reports errors', false === $bad['ok'] && 'unexpected_token' === $bad['code'] );

/* ------------------------------------------------------------------------- */
echo "\n[11] Gold formula reference case\n";
/* ------------------------------------------------------------------------- */
/*
 * weight=8 g, gold18=26,051,530, wage=15%, profit=7%:
 *   8 * 26051530 = 208,412,240
 *   * 1.15       = 239,674,076
 *   * 1.07       = 256,451,261.32
 */
$gold = Formula::compile( 'weight * gold18 * (1 + wage / 100) * (1 + profit / 100)' );
FAssert::check( 'gold formula compiles', $gold instanceof CompiledFormula );

if ( $gold instanceof CompiledFormula ) {
	$out = $gold->evaluate(
		array(
			'weight' => 8,
			'gold18' => 26051530,
			'wage'   => 15,
			'profit' => 7,
		)
	);

	FAssert::check( 'gold formula evaluates', $out instanceof Result );

	if ( $out instanceof Result ) {
		FAssert::check(
			'gold total is 256,451,261.32',
			abs( $out->value - 256451261.32 ) < 0.01,
			(string) $out->value
		);
	}
}

// Persian-typed version of the same formula must give the same result.
$gold_fa = Formula::compile( 'وزن' === 'x' ? '' : 'weight × gold18 × (۱ + wage ÷ ۱۰۰) × (۱ + profit ÷ ۱۰۰)' );
FAssert::check( 'Persian operators version compiles', $gold_fa instanceof CompiledFormula );

if ( $gold_fa instanceof CompiledFormula ) {
	$out = $gold_fa->evaluate( array( 'weight' => 8, 'gold18' => 26051530, 'wage' => 15, 'profit' => 7 ) );
	FAssert::check(
		'Persian operators give the same total',
		$out instanceof Result && abs( $out->value - 256451261.32 ) < 0.01,
		$out instanceof Result ? (string) $out->value : 'error'
	);
}

/* ------------------------------------------------------------------------- */
echo "\n[12] Determinism and re-use\n";
/* ------------------------------------------------------------------------- */
$repeat = Formula::compile( 'a*2+1' );
$ok     = true;

if ( $repeat instanceof CompiledFormula ) {
	for ( $i = 0; $i < 100; $i++ ) {
		$r = $repeat->evaluate( array( 'a' => (float) $i ) );

		if ( ! $r instanceof Result || abs( $r->value - ( $i * 2 + 1 ) ) > 0.000001 ) {
			$ok = false;
			break;
		}
	}
}

FAssert::check( 'one compile, many evaluations are stable', $ok );

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", FAssert::$passed, FAssert::$failed );

exit( FAssert::$failed > 0 ? 1 : 0 );
