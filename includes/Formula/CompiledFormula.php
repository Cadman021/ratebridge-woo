<?php
/**
 * Compiled formula: compile once, evaluate many times.
 *
 * Holds the normalised source and the AST. Nothing is ever written to disk, so
 * there is no generated code to include.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * A successfully compiled expression.
 */
final class CompiledFormula {

	/**
	 * Constructor.
	 *
	 * @param string   $source Normalised source.
	 * @param string   $raw    Original expression.
	 * @param Node     $ast    Parsed AST.
	 * @param string[] $vars   Identifiers used, in first-seen order.
	 * @param string[] $funcs  Function names used (unique).
	 */
	public function __construct(
		private readonly string $source,
		private readonly string $raw,
		private readonly Node $ast,
		private readonly array $vars,
		private readonly array $funcs
	) {}

	/**
	 * Normalised expression.
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Original expression as typed.
	 *
	 * @return string
	 */
	public function raw(): string {
		return $this->raw;
	}

	/**
	 * Identifiers referenced by the expression (deduplicated, first-seen order).
	 *
	 * @return string[]
	 */
	public function variables(): array {
		return $this->vars;
	}

	/**
	 * Function names used.
	 *
	 * @return string[]
	 */
	public function functions(): array {
		return $this->funcs;
	}

	/**
	 * AST depth (for diagnostics/limits).
	 *
	 * @return int
	 */
	public function depth(): int {
		return $this->ast->depth();
	}

	/**
	 * Evaluate with the given variable values.
	 *
	 * @param array<string, float|int|string> $values Variable values.
	 * @return Result|FormulaError
	 */
	public function evaluate( array $values ): Result|FormulaError {
		$evaluator = new Evaluator( $values );

		return $evaluator->evaluate( $this->ast );
	}

	/**
	 * Evaluate and return a plain float, or null on error.
	 *
	 * Convenience for call sites that only need the number.
	 *
	 * @param array<string, float|int|string> $values Variable values.
	 * @return float|null
	 */
	public function value( array $values ): ?float {
		$result = $this->evaluate( $values );

		return $result instanceof Result ? $result->value : null;
	}

	/**
	 * Variables that were not supplied (helpers for the UI).
	 *
	 * @param array<string, mixed> $values Supplied values.
	 * @return string[]
	 */
	public function missing( array $values ): array {
		$missing = array();

		foreach ( $this->vars as $name ) {
			if ( ! array_key_exists( $name, $values ) || ! is_numeric( $values[ $name ] ) ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}
}
