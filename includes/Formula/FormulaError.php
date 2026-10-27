<?php
/**
 * Structured formula error.
 *
 * Carries a machine code (stable, used by the WordPress layer to pick a Persian
 * message), the character offset in the *normalised* source, and an optional
 * detail (the offending function or variable name).
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * An error produced by any stage of the pipeline.
 */
final class FormulaError {

	/**
	 * Constructor.
	 *
	 * @param string $code     Machine code, e.g. `unknown_function`.
	 * @param int    $position Character offset in the normalised source.
	 * @param string $detail   Extra context (function/variable name).
	 */
	public function __construct(
		public readonly string $code,
		public readonly int $position = 0,
		public readonly string $detail = ''
	) {}

	/**
	 * Serialise for JSON responses.
	 *
	 * @return array{code:string,position:int,detail:string}
	 */
	public function to_array(): array {
		return array(
			'code'     => $this->code,
			'position' => $this->position,
			'detail'   => $this->detail,
		);
	}
}
