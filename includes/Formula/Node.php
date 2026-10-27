<?php
/**
 * AST node types.
 *
 * Tiny immutable value objects: the evaluator switches on `kind`, so the tree
 * can be inspected (variables, tracer) without reflection.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * One AST node.
 */
final class Node {

	public const NUMBER = 'number';
	public const VAR    = 'var';
	public const UNARY  = 'unary';
	public const BINARY = 'binary';
	public const CALL   = 'call';

	/**
	 * Constructor.
	 *
	 * @param string       $kind     One of the class constants.
	 * @param float        $number   Literal value for NUMBER.
	 * @param string       $name     Variable or function name.
	 * @param string       $operator Unary/binary operator.
	 * @param Node[]       $children Child nodes.
	 * @param int          $pos      Offset in the normalised source.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly float $number = 0.0,
		public readonly string $name = '',
		public readonly string $operator = '',
		public readonly array $children = array(),
		public readonly int $pos = 0
	) {}

	/**
	 * Number literal.
	 *
	 * @param float $value Value.
	 * @param int   $pos   Offset.
	 * @return self
	 */
	public static function number( float $value, int $pos ): self {
		return new self( self::NUMBER, $value, '', '', array(), $pos );
	}

	/**
	 * Variable reference.
	 *
	 * @param string $name Variable name.
	 * @param int    $pos  Offset.
	 * @return self
	 */
	public static function variable( string $name, int $pos ): self {
		return new self( self::VAR, 0.0, $name, '', array(), $pos );
	}

	/**
	 * Unary operation.
	 *
	 * @param string $operator Operator.
	 * @param Node   $operand  Operand.
	 * @param int    $pos      Offset.
	 * @return self
	 */
	public static function unary( string $operator, Node $operand, int $pos ): self {
		return new self( self::UNARY, 0.0, '', $operator, array( $operand ), $pos );
	}

	/**
	 * Binary operation.
	 *
	 * @param string $operator Operator.
	 * @param Node   $left     Left side.
	 * @param Node   $right    Right side.
	 * @param int    $pos      Offset.
	 * @return self
	 */
	public static function binary( string $operator, Node $left, Node $right, int $pos ): self {
		return new self( self::BINARY, 0.0, '', $operator, array( $left, $right ), $pos );
	}

	/**
	 * Function call.
	 *
	 * @param string $name Function name.
	 * @param Node[] $args Arguments.
	 * @param int    $pos  Offset.
	 * @return self
	 */
	public static function call( string $name, array $args, int $pos ): self {
		return new self( self::CALL, 0.0, strtolower( $name ), '', array_values( $args ), $pos );
	}

	/**
	 * Height of the subtree (used to enforce the depth limit).
	 *
	 * @return int
	 */
	public function depth(): int {
		$max = 0;

		foreach ( $this->children as $child ) {
			$max = max( $max, $child->depth() );
		}

		return 1 + $max;
	}
}
