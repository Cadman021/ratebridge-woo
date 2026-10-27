<?php
/**
 * Bulk-edit arithmetic (pure PHP).
 *
 * Turns "set X / increase by % / decrease by amount" into a concrete number.
 * Shared by the WooCommerce bulk-edit handler and `tests/bulk-edit.php`, so the
 * rules are tested without WordPress.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Compat.php';

/**
 * Applies a bulk instruction to a value.
 */
final class BulkChange {

	/**
	 * No change.
	 */
	public const OP_NONE = 'none';

	/**
	 * Set an absolute value.
	 */
	public const OP_SET = 'set';

	/**
	 * Increase by a percentage.
	 */
	public const OP_INCREASE_PERCENT = 'inc_percent';

	/**
	 * Increase by a fixed amount.
	 */
	public const OP_INCREASE_AMOUNT = 'inc_amount';

	/**
	 * Decrease by a percentage.
	 */
	public const OP_DECREASE_PERCENT = 'dec_percent';

	/**
	 * Decrease by a fixed amount.
	 */
	public const OP_DECREASE_AMOUNT = 'dec_amount';

	/**
	 * Clear the value.
	 */
	public const OP_CLEAR = 'clear';

	/**
	 * Operations this class understands.
	 *
	 * @return string[]
	 */
	public static function operations(): array {
		return array(
			self::OP_NONE,
			self::OP_SET,
			self::OP_INCREASE_PERCENT,
			self::OP_INCREASE_AMOUNT,
			self::OP_DECREASE_PERCENT,
			self::OP_DECREASE_AMOUNT,
			self::OP_CLEAR,
		);
	}

	/**
	 * Normalise an operation value.
	 *
	 * @param mixed $operation Raw value.
	 * @return string
	 */
	public static function operation( mixed $operation ): string {
		$operation = is_string( $operation ) ? sanitize_key_compat( $operation ) : '';

		return in_array( $operation, self::operations(), true ) ? $operation : self::OP_NONE;
	}

	/**
	 * Apply an instruction to a current value.
	 *
	 * Rules:
	 *  - `none` returns the current value untouched (and `changed = false`).
	 *  - `clear` returns null (the meta key is deleted).
	 *  - Results are never negative: `0` is the floor.
	 *  - A missing current value counts as 0 for percentage operations on a
	 *    "set" op, but percentage increase of 0 stays 0 (0 + 0%).
	 *  - The result is rounded to 4 decimals (the storage precision).
	 *
	 * @param float|null $current   Current value (null = not set).
	 * @param string     $operation One of the OP_* constants.
	 * @param float      $amount    Operand (absolute value or percentage).
	 * @return array{changed:bool,value:float|null}
	 */
	public static function apply( ?float $current, string $operation, float $amount = 0.0 ): array {
		$operation = self::operation( $operation );
		$current   = null === $current || ! is_finite( $current ) ? null : $current;
		$amount    = is_finite( $amount ) ? $amount : 0.0;

		switch ( $operation ) {
			case self::OP_NONE:
				return array(
					'changed' => false,
					'value'   => $current,
				);

			case self::OP_CLEAR:
				return array(
					'changed' => true,
					'value'   => null,
				);

			case self::OP_SET:
				return array(
					'changed' => true,
					'value'   => round( max( 0.0, $amount ), 4 ),
				);

			case self::OP_INCREASE_PERCENT:
				$base = $current ?? 0.0;

				return array(
					'changed' => true,
					'value'   => round( max( 0.0, $base + ( $base * $amount / 100.0 ) ), 4 ),
				);

			case self::OP_DECREASE_PERCENT:
				$base = $current ?? 0.0;

				return array(
					'changed' => true,
					'value'   => round( max( 0.0, $base - ( $base * $amount / 100.0 ) ), 4 ),
				);

			case self::OP_INCREASE_AMOUNT:
				return array(
					'changed' => true,
					'value'   => round( max( 0.0, ( $current ?? 0.0 ) + $amount ), 4 ),
				);

			case self::OP_DECREASE_AMOUNT:
				return array(
					'changed' => true,
					'value'   => round( max( 0.0, ( $current ?? 0.0 ) - $amount ), 4 ),
				);
		}

		return array(
			'changed' => false,
			'value'   => $current,
		);
	}

	/**
	 * Whether a mode/currency/formula combination is coherent.
	 *
	 * @param string $mode       currency|formula|off.
	 * @param bool   $has_currency Whether a currency is selected.
	 * @param bool   $has_formula  Whether a formula is selected.
	 * @return string Empty when valid, else an error code.
	 */
	public static function validate_mode( string $mode, bool $has_currency, bool $has_formula ): string {
		if ( 'currency' === $mode && ! $has_currency ) {
			return 'currency_required';
		}

		if ( 'formula' === $mode && ! $has_formula ) {
			return 'formula_required';
		}

		return '';
	}
}
