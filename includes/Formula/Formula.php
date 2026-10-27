<?php
/**
 * Formula facade: the only entry point the rest of the plugin needs.
 *
 * Pipeline: Normalizer → Lexer → Parser → AST → Evaluator.
 * Pure PHP with no WordPress dependency, so `tests/formula-engine.php` runs it
 * from the command line.
 *
 * Safety notes (see README for the full language reference):
 *  - no eval(), create_function(), preg_replace/e, include of generated code,
 *    call_user_func on user input, or variable variables;
 *  - only the whitelisted functions in `Functions` can be called;
 *  - length, token, depth and nesting limits are enforced while compiling.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles expressions and reports everything the admin needs to know.
 */
final class Formula {

	/**
	 * Compile an expression.
	 *
	 * @param string $expression Expression as typed by the admin.
	 * @return CompiledFormula|FormulaError
	 */
	public static function compile( string $expression ): CompiledFormula|FormulaError {
		$normalized = Normalizer::normalize( $expression );

		if ( '' !== $normalized['error'] ) {
			return new FormulaError( $normalized['error'], $normalized['position'] );
		}

		$lexed = Lexer::tokenize( $normalized['source'] );

		if ( '' !== $lexed['error'] ) {
			return new FormulaError( $lexed['error'], $lexed['position'] );
		}

		$parsed = Parser::parse( $lexed['tokens'] );

		if ( '' !== $parsed['error'] || ! $parsed['node'] instanceof Node ) {
			return new FormulaError(
				$parsed['error'],
				$parsed['position'],
				(string) $parsed['function']
			);
		}

		$vars  = array();
		$funcs = array();

		self::collect( $parsed['node'], $vars, $funcs );

		$reserved = array_values( array_intersect( $vars, Functions::reserved() ) );

		if ( ! empty( $reserved ) ) {
			return new FormulaError( 'reserved_name', 0, (string) $reserved[0] );
		}

		return new CompiledFormula(
			$normalized['source'],
			$expression,
			$parsed['node'],
			$vars,
			$funcs
		);
	}

	/**
	 * Compile and collect diagnostics in one pass (used by the AJAX validator).
	 *
	 * @param string   $expression      Expression.
	 * @param string[] $declared        Declared variable names.
	 * @param string[] $reserved_extra  Extra names that may not be variables.
	 * @return array<string, mixed> {
	 *     @type bool     $ok         Whether it compiled.
	 *     @type string   $code       Error code ('' when ok).
	 *     @type int      $position   Error offset in the normalised source.
	 *     @type string   $detail     Error detail.
	 *     @type string   $source     Normalised source ('' on error).
	 *     @type string[] $variables  Identifiers used.
	 *     @type string[] $functions  Functions used.
	 *     @type string[] $undeclared Variables used but not declared.
	 *     @type string[] $unused     Declared but not used.
	 * }
	 */
	public static function check( string $expression, array $declared = array(), array $reserved_extra = array() ): array {
		$compiled = self::compile( $expression );

		if ( $compiled instanceof FormulaError ) {
			return array(
				'ok'         => false,
				'code'       => $compiled->code,
				'position'   => $compiled->position,
				'detail'     => $compiled->detail,
				'source'     => '',
				'variables'  => array(),
				'functions'  => array(),
				'undeclared' => array(),
				'unused'     => array(),
			);
		}

		$used     = $compiled->variables();
		$declared = array_values( array_unique( array_map( 'strval', $declared ) ) );

		$clash = array_values( array_intersect( $declared, Functions::reserved() ) );

		if ( ! empty( $clash ) ) {
			return array(
				'ok'         => false,
				'code'       => 'reserved_name',
				'position'   => 0,
				'detail'     => (string) $clash[0],
				'source'     => $compiled->source(),
				'variables'  => $used,
				'functions'  => $compiled->functions(),
				'undeclared' => array(),
				'unused'     => array(),
			);
		}

		return array(
			'ok'         => true,
			'code'       => '',
			'position'   => 0,
			'detail'     => '',
			'source'     => $compiled->source(),
			'variables'  => $used,
			'functions'  => $compiled->functions(),
			'undeclared' => array_values( array_diff( $used, $declared ) ),
			'unused'     => array_values( array_diff( $declared, $used ) ),
		);
	}

	/**
	 * Validate a variable name (usable as an identifier).
	 *
	 * @param string $name Proposed name.
	 * @return bool
	 */
	public static function is_valid_variable_name( string $name ): bool {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,39}$/', $name ) ) {
			return false;
		}

		return ! Functions::is_reserved( $name );
	}

	/**
	 * Convert Persian/Arabic digits to ASCII (public helper for the UI layer).
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function to_ascii_digits( string $value ): string {
		return str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			$value
		);
	}

	/**
	 * Walk the AST collecting variables and functions.
	 *
	 * @param Node     $node  Node.
	 * @param string[] $vars  Variables (by reference).
	 * @param string[] $funcs Functions (by reference).
	 * @return void
	 */
	private static function collect( Node $node, array &$vars, array &$funcs ): void {
		if ( Node::VAR === $node->kind ) {
			if ( ! in_array( $node->name, $vars, true ) ) {
				$vars[] = $node->name;
			}

			return;
		}

		if ( Node::CALL === $node->kind && ! in_array( $node->name, $funcs, true ) ) {
			$funcs[] = $node->name;
		}

		foreach ( $node->children as $child ) {
			self::collect( $child, $vars, $funcs );
		}
	}
}
