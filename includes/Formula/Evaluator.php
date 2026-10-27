<?php
/**
 * AST evaluator.
 *
 * Walks the tree; there is no loop, no recursion beyond the AST depth (already
 * bounded) and no dynamic call. Every arithmetic result is checked for
 * finiteness so NaN/INF can never leak into a product price.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Result of an evaluation.
 */
final class Result {

	/**
	 * Constructor.
	 *
	 * @param float                $value Numeric result.
	 * @param array<string, float> $trace Variable values that were read.
	 */
	public function __construct(
		public readonly float $value,
		public readonly array $trace = array()
	) {}

	/**
	 * Serialise for JSON responses.
	 *
	 * @return array{value:float,trace:array<string,float>}
	 */
	public function to_array(): array {
		return array(
			'value' => $this->value,
			'trace' => $this->trace,
		);
	}
}

/**
 * Evaluates an AST against variable values.
 */
final class Evaluator {

	/**
	 * Variable values.
	 *
	 * @var array<string, float>
	 */
	private array $values;

	/**
	 * Variables actually read (for the trace).
	 *
	 * @var array<string, float>
	 */
	private array $trace = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, float|int|string> $values Variable values.
	 */
	public function __construct( array $values ) {
		$this->values = array();

		foreach ( $values as $name => $value ) {
			if ( is_numeric( $value ) ) {
				$this->values[ (string) $name ] = (float) $value;
			}
		}
	}

	/**
	 * Evaluate a node.
	 *
	 * @param Node $node Root node.
	 * @return Result|FormulaError
	 */
	public function evaluate( Node $node ): Result|FormulaError {
		$value = $this->node( $node );

		if ( $value instanceof FormulaError ) {
			return $value;
		}

		if ( ! is_finite( $value ) ) {
			return new FormulaError( 'not_finite', $node->pos );
		}

		return new Result( $value, $this->trace );
	}

	/**
	 * Evaluate one node to a float.
	 *
	 * @param Node $node Node.
	 * @return float|FormulaError
	 */
	private function node( Node $node ): float|FormulaError {
		switch ( $node->kind ) {
			case Node::NUMBER:
				return $node->number;

			case Node::VAR:
				if ( ! array_key_exists( $node->name, $this->values ) ) {
					return new FormulaError( 'unknown_variable', $node->pos, $node->name );
				}

				$this->trace[ $node->name ] = $this->values[ $node->name ];

				return $this->values[ $node->name ];

			case Node::UNARY:
				$operand = $this->node( $node->children[0] );

				if ( $operand instanceof FormulaError ) {
					return $operand;
				}

				return match ( $node->operator ) {
					'-'   => -$operand,
					'+'   => $operand,
					'not' => 0.0 === $operand ? 1.0 : 0.0,
					default => new FormulaError( 'unknown_operator', $node->pos, $node->operator ),
				};

			case Node::BINARY:
				return $this->binary( $node );

			case Node::CALL:
				return $this->call( $node );
		}

		return new FormulaError( 'unexpected_token', $node->pos );
	}

	/**
	 * Binary operations.
	 *
	 * `and`/`or` short-circuit so the unused side is never evaluated.
	 *
	 * @param Node $node Binary node.
	 * @return float|FormulaError
	 */
	private function binary( Node $node ): float|FormulaError {
		$left_node  = $node->children[0];
		$right_node = $node->children[1];

		$left = $this->node( $left_node );

		if ( $left instanceof FormulaError ) {
			return $left;
		}

		if ( 'and' === $node->operator ) {
			return 0.0 === $left ? 0.0 : $this->truth( $this->node( $right_node ), $node->pos );
		}

		if ( 'or' === $node->operator ) {
			return 0.0 !== $left ? 1.0 : $this->truth( $this->node( $right_node ), $node->pos );
		}

		$right = $this->node( $right_node );

		if ( $right instanceof FormulaError ) {
			return $right;
		}

		$result = match ( $node->operator ) {
			'+'  => $left + $right,
			'-'  => $left - $right,
			'*'  => $left * $right,
			'/'  => 0.0 === $right ? null : $left / $right,
			'%'  => 0.0 === $right ? null : fmod( $left, $right ),
			'^'  => $left ** $right,
			'<'  => $left < $right ? 1.0 : 0.0,
			'>'  => $left > $right ? 1.0 : 0.0,
			'<=' => $left <= $right ? 1.0 : 0.0,
			'>=' => $left >= $right ? 1.0 : 0.0,
			'==' => abs( $left - $right ) < 1e-9 ? 1.0 : 0.0,
			'!=' => abs( $left - $right ) >= 1e-9 ? 1.0 : 0.0,
			default => null,
		};

		if ( null === $result ) {
			$code = ( '/' === $node->operator || '%' === $node->operator )
				? 'division_by_zero'
				: 'unknown_operator';

			return new FormulaError( $code, $node->pos, $node->operator );
		}

		if ( ! is_finite( $result ) ) {
			return new FormulaError( 'not_finite', $node->pos, $node->operator );
		}

		return (float) $result;
	}

	/**
	 * Coerce an evaluated side of and/or to 0/1.
	 *
	 * @param float|FormulaError $value Value.
	 * @param int                $pos   Offset.
	 * @return float|FormulaError
	 */
	private function truth( float|FormulaError $value, int $pos ): float|FormulaError {
		if ( $value instanceof FormulaError ) {
			return $value;
		}

		unset( $pos );

		return 0.0 !== $value ? 1.0 : 0.0;
	}

	/**
	 * Function call.
	 *
	 * @param Node $node Call node.
	 * @return float|FormulaError
	 */
	private function call( Node $node ): float|FormulaError {
		$name = $node->name;

		if ( ! Functions::is_function( $name ) ) {
			return new FormulaError( 'unknown_function', $node->pos, $name );
		}

		// Lazy `if`: only the chosen branch is evaluated.
		if ( Functions::is_lazy( $name ) ) {
			$condition = $this->node( $node->children[0] );

			if ( $condition instanceof FormulaError ) {
				return $condition;
			}

			$branch = 0.0 !== $condition ? $node->children[1] : $node->children[2];

			return $this->node( $branch );
		}

		$args = array();

		foreach ( $node->children as $child ) {
			$value = $this->node( $child );

			if ( $value instanceof FormulaError ) {
				return $value;
			}

			$args[] = $value;
		}

		$arity = Functions::arity( $name );

		if ( null === $arity ) {
			return new FormulaError( 'unknown_function', $node->pos, $name );
		}

		list( $min, $max ) = $arity;

		if ( count( $args ) < $min || ( null !== $max && count( $args ) > $max ) ) {
			return new FormulaError( 'bad_arity', $node->pos, $name );
		}

		$result = Functions::apply( $name, $args );

		if ( $result instanceof FormulaError ) {
			return new FormulaError( $result->code, $node->pos, '' !== $result->detail ? $result->detail : $name );
		}

		if ( ! is_finite( $result ) ) {
			return new FormulaError( 'not_finite', $node->pos, $name );
		}

		return $result;
	}
}
