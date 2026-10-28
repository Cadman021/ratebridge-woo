<?php
/**
 * Recursive-descent parser with precedence climbing.
 *
 * Grammar (lowest to highest precedence):
 *
 *   expression := or
 *   or         := and ( ("or" | "||") and )*
 *   and        := not ( ("and" | "&&") not )*
 *   not        := ("not" | "!") not | equality
 *   equality   := comparison ( ("==" | "!=") comparison )*
 *   comparison := additive ( ("<" | ">" | "<=" | ">=") additive )*
 *   additive   := multiplicative ( ("+" | "-") multiplicative )*
 *   multiplicative := unary ( ("*" | "/" | "%") unary )*
 *   unary      := ("+" | "-") unary | power
 *   power      := primary ( "^" unary )?          // right associative
 *   primary    := number | ident | ident "(" args ")" | "(" expression ")"
 *
 * `^` binds tighter than unary minus on its left operand and is right
 * associative, so `-2^2` is -(2^2) = -4 and `2^3^2` is 2^(3^2) = 512.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an AST from tokens.
 */
final class Parser {

	/**
	 * Maximum AST depth.
	 */
	public const MAX_DEPTH = 40;

	/**
	 * Maximum function call nesting.
	 */
	public const MAX_CALL_DEPTH = 20;

	/**
	 * Current token list.
	 *
	 * @var Token[]
	 */
	private array $tokens = array();

	/**
	 * Cursor.
	 *
	 * @var int
	 */
	private int $index = 0;

	/**
	 * Current call nesting.
	 *
	 * @var int
	 */
	private int $call_depth = 0;

	/**
	 * Current parenthesis nesting.
	 *
	 * `((((1))))` produces a shallow tree but deep *nesting*; both are bounded
	 * so a hand-typed expression cannot exhaust the stack.
	 *
	 * @var int
	 */
	private int $paren_depth = 0;

	/**
	 * Parse a token list into an AST.
	 *
	 * @param Token[] $tokens Tokens from the lexer (END terminated).
	 * @return array{node:Node|null,error:string,position:int,function:string}
	 */
	public static function parse( array $tokens ): array {
		$parser         = new self();
		$parser->tokens = $tokens;

		$node = $parser->parse_expression();

		if ( $node instanceof FormulaError || null === $node ) {
			return $parser->failure( $node );
		}

		$token = $parser->peek();

		if ( Token::END !== $token->type ) {
			// Trailing garbage such as `1 2` or `1e999`.
			return array(
				'node'     => null,
				'error'    => 'unexpected_token',
				'position' => $token->pos,
				'function' => '',
			);
		}

		if ( $node->depth() > self::MAX_DEPTH ) {
			return array(
				'node'     => null,
				'error'    => 'too_deep',
				'position' => 0,
				'function' => '',
			);
		}

		return array(
			'node'     => $node,
			'error'    => '',
			'position' => 0,
			'function' => '',
		);
	}

	/**
	 * Build a failure array.
	 *
	 * @param FormulaError|null $error Error object, if any.
	 * @return array{node:Node|null,error:string,position:int,function:string}
	 */
	private function failure( ?FormulaError $error ): array {
		$position = null !== $error ? $error->position : $this->peek()->pos;
		$code     = null !== $error ? $error->code : 'unexpected_token';

		return array(
			'node'     => null,
			'error'    => $code,
			'position' => $position,
			'function' => null !== $error ? $error->detail : '',
		);
	}

	/**
	 * expression := or
	 *
	 * @return Node|FormulaError
	 */
	private function parse_expression(): Node|FormulaError {
		return $this->parse_or();
	}

	/**
	 * or level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_or(): Node|FormulaError {
		$left = $this->parse_and();

		if ( $left instanceof FormulaError ) {
			return $left;
		}

		while ( $this->peek()->is( 'or' ) || $this->peek()->is( '||' ) ) {
			$token = $this->advance();
			$right = $this->parse_and();

			if ( $right instanceof FormulaError ) {
				return $right;
			}

			$left = Node::binary( 'or', $left, $right, $token->pos );
		}

		return $left;
	}

	/**
	 * and level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_and(): Node|FormulaError {
		$left = $this->parse_not();

		if ( $left instanceof FormulaError ) {
			return $left;
		}

		while ( $this->peek()->is( 'and' ) || $this->peek()->is( '&&' ) ) {
			$token = $this->advance();
			$right = $this->parse_not();

			if ( $right instanceof FormulaError ) {
				return $right;
			}

			$left = Node::binary( 'and', $left, $right, $token->pos );
		}

		return $left;
	}

	/**
	 * not level (prefix, right associative).
	 *
	 * @return Node|FormulaError
	 */
	private function parse_not(): Node|FormulaError {
		$token = $this->peek();

		if ( $token->is( 'not' ) || $token->is( '!' ) ) {
			$this->advance();
			$operand = $this->parse_not();

			if ( $operand instanceof FormulaError ) {
				return $operand;
			}

			return Node::unary( 'not', $operand, $token->pos );
		}

		return $this->parse_equality();
	}

	/**
	 * equality level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_equality(): Node|FormulaError {
		$left = $this->parse_comparison();

		if ( $left instanceof FormulaError ) {
			return $left;
		}

		$equalities = 0;

		while ( $this->peek()->is( '==' ) || $this->peek()->is( '!=' ) ) {
			$token = $this->advance();

			// `1 == 2 == 3` is just as ambiguous as a chained `<`.
			if ( ++$equalities > 1 ) {
				return new FormulaError( 'chained_comparison', $token->pos );
			}

			$right = $this->parse_comparison();

			if ( $right instanceof FormulaError ) {
				return $right;
			}

			$left = Node::binary( $token->value, $left, $right, $token->pos );
		}

		return $left;
	}

	/**
	 * comparison level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_comparison(): Node|FormulaError {
		$left = $this->parse_additive();

		if ( $left instanceof FormulaError ) {
			return $left;
		}

		$seen = 0;

		while ( $this->peek()->is( '<' ) || $this->peek()->is( '>' ) || $this->peek()->is( '<=' ) || $this->peek()->is( '>=' ) ) {
			$token = $this->advance();

			/*
			 * `1 < 2 < 3` is ambiguous (Python evaluates it as a chain, most other
			 * languages compare a boolean). Reject it at compile time and tell the
			 * author to write `(1 < 2) and (2 < 3)`.
			 */
			if ( ++$seen > 1 ) {
				return new FormulaError( 'chained_comparison', $token->pos );
			}

			$right = $this->parse_additive();

			if ( $right instanceof FormulaError ) {
				return $right;
			}

			$left = Node::binary( $token->value, $left, $right, $token->pos );
		}

		return $left;
	}

	/**
	 * additive level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_additive(): Node|FormulaError {
		$left = $this->parse_multiplicative();

		if ( $left instanceof FormulaError ) {
			return $left;
		}

		while ( $this->peek()->is( '+' ) || $this->peek()->is( '-' ) ) {
			$token = $this->advance();
			$right = $this->parse_multiplicative();

			if ( $right instanceof FormulaError ) {
				return $right;
			}

			$left = Node::binary( $token->value, $left, $right, $token->pos );
		}

		return $left;
	}

	/**
	 * multiplicative level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_multiplicative(): Node|FormulaError {
		$left = $this->parse_unary();

		if ( $left instanceof FormulaError ) {
			return $left;
		}

		while ( $this->peek()->is( '*' ) || $this->peek()->is( '/' ) || $this->peek()->is( '%' ) ) {
			$token = $this->advance();
			$right = $this->parse_unary();

			if ( $right instanceof FormulaError ) {
				return $right;
			}

			$left = Node::binary( $token->value, $left, $right, $token->pos );
		}

		return $left;
	}

	/**
	 * unary level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_unary(): Node|FormulaError {
		$token = $this->peek();

		if ( $token->is( '+' ) || $token->is( '-' ) ) {
			$this->advance();
			$operand = $this->parse_unary();

			if ( $operand instanceof FormulaError ) {
				return $operand;
			}

			return Node::unary( $token->value, $operand, $token->pos );
		}

		return $this->parse_power();
	}

	/**
	 * power level (right associative).
	 *
	 * @return Node|FormulaError
	 */
	private function parse_power(): Node|FormulaError {
		$base = $this->parse_primary();

		if ( $base instanceof FormulaError ) {
			return $base;
		}

		if ( $this->peek()->is( '^' ) ) {
			$token = $this->advance();
			// Right associative: recurse into parse_unary so `2^-1` works.
			$exponent = $this->parse_unary();

			if ( $exponent instanceof FormulaError ) {
				return $exponent;
			}

			return Node::binary( '^', $base, $exponent, $token->pos );
		}

		return $base;
	}

	/**
	 * primary level.
	 *
	 * @return Node|FormulaError
	 */
	private function parse_primary(): Node|FormulaError {
		$token = $this->peek();

		if ( Token::NUMBER === $token->type ) {
			$this->advance();

			return Node::number( $token->number, $token->pos );
		}

		if ( Token::IDENT === $token->type ) {
			$this->advance();

			// Function call?
			if ( Token::LPAREN === $this->peek()->type ) {
				return $this->parse_call( $token );
			}

			$lower = strtolower( $token->value );

			if ( 'true' === $lower || 'false' === $lower ) {
				return Node::number( 'true' === $lower ? 1.0 : 0.0, $token->pos );
			}

			if ( Functions::is_function( $token->value ) ) {
				return new FormulaError( 'function_needs_args', $token->pos, $token->value );
			}

			if ( Functions::is_reserved( $token->value ) ) {
				return new FormulaError( 'reserved_name', $token->pos, $token->value );
			}

			return Node::variable( $token->value, $token->pos );
		}

		if ( Token::LPAREN === $token->type ) {
			$this->advance();

			++$this->paren_depth;

			if ( $this->paren_depth > self::MAX_DEPTH ) {
				return new FormulaError( 'too_deep', $token->pos );
			}

			$inner = $this->parse_expression();

			if ( $inner instanceof FormulaError ) {
				return $inner;
			}

			if ( Token::RPAREN !== $this->peek()->type ) {
				return new FormulaError( 'missing_paren', $this->peek()->pos );
			}

			$this->advance();

			--$this->paren_depth;

			return $inner;
		}

		return new FormulaError( 'unexpected_token', $token->pos, $token->value );
	}

	/**
	 * Function call.
	 *
	 * @param Token $name Identifier token.
	 * @return Node|FormulaError
	 */
	private function parse_call( Token $name ): Node|FormulaError {
		$lower = strtolower( $name->value );

		if ( ! Functions::is_function( $lower ) ) {
			return new FormulaError( 'unknown_function', $name->pos, $name->value );
		}

		// `(` — consumed here.
		$this->advance();

		++$this->call_depth;

		if ( $this->call_depth > self::MAX_CALL_DEPTH || $this->call_depth > self::MAX_DEPTH ) {
			return new FormulaError( 'too_deep', $name->pos, $name->value );
		}

		$args = array();

		if ( Token::RPAREN !== $this->peek()->type ) {
			while ( true ) {
				$arg = $this->parse_expression();

				if ( $arg instanceof FormulaError ) {
					return $arg;
				}

				$args[] = $arg;

				if ( Token::COMMA === $this->peek()->type ) {
					$this->advance();
					continue;
				}

				break;
			}
		}

		if ( Token::RPAREN !== $this->peek()->type ) {
			return new FormulaError( 'missing_paren', $this->peek()->pos );
		}

		$this->advance();

		--$this->call_depth;

		$arity = Functions::arity( $lower );

		if ( null === $arity ) {
			return new FormulaError( 'unknown_function', $name->pos, $name->value );
		}

		list( $min, $max ) = $arity;

		if ( count( $args ) < $min || ( null !== $max && count( $args ) > $max ) ) {
			return new FormulaError( 'bad_arity', $name->pos, $name->value );
		}

		return Node::call( $lower, $args, $name->pos );
	}

	/**
	 * Current token.
	 *
	 * @return Token
	 */
	private function peek(): Token {
		return $this->tokens[ $this->index ] ?? new Token( Token::END, '' );
	}

	/**
	 * Consume and return the current token.
	 *
	 * @return Token
	 */
	private function advance(): Token {
		$token = $this->peek();

		if ( Token::END !== $token->type ) {
			++$this->index;
		}

		return $token;
	}
}
