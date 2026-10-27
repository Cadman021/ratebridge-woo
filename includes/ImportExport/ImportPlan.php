<?php
/**
 * Package import diffing (pure PHP).
 *
 * Computes, for every section, what would happen: create / update / skip /
 * invalid. The WordPress layer only applies this plan, so the dry-run the admin
 * sees is produced by exactly the same code that later executes.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Conflict policies.
 */
final class ImportPlan {

	/**
	 * Skip existing rows (default).
	 */
	public const POLICY_SKIP = 'skip';

	/**
	 * Update existing rows with the package values.
	 */
	public const POLICY_UPDATE = 'update';

	/**
	 * Conflict policy options.
	 *
	 * @return string[]
	 */
	public static function policies(): array {
		return array( self::POLICY_SKIP, self::POLICY_UPDATE );
	}

	/**
	 * Normalise a policy value.
	 *
	 * @param mixed $policy Raw policy.
	 * @return string
	 */
	public static function policy( mixed $policy ): string {
		return self::POLICY_UPDATE === $policy ? self::POLICY_UPDATE : self::POLICY_SKIP;
	}

	/**
	 * Conversion factor between two store units.
	 *
	 * Uses the same relationship as `UnitMigration`: 1 Toman = 10 Rial.
	 *
	 * @param string $from Source unit.
	 * @param string $to   Target unit.
	 * @return float
	 */
	public static function factor( string $from, string $to ): float {
		$from = Package::unit( $from );
		$to   = Package::unit( $to );

		if ( $from === $to ) {
			return 1.0;
		}

		return 'toman' === $from ? 10.0 : 0.1;
	}

	/**
	 * Convert a store-unit amount (rounded to 4 decimals to avoid float dust).
	 *
	 * Percentages must NOT pass through this function — they are unit-less.
	 *
	 * @param float  $amount Amount in the source unit.
	 * @param string $from   Source unit.
	 * @param string $to     Target unit.
	 * @return float
	 */
	public static function convert_amount( float $amount, string $from, string $to ): float {
		if ( ! is_finite( $amount ) ) {
			return 0.0;
		}

		return round( $amount * self::factor( $from, $to ), 4 );
	}

	/**
	 * Whether two units differ (so the UI must ask about conversion).
	 *
	 * @param string $a Unit.
	 * @param string $b Unit.
	 * @return bool
	 */
	public static function units_differ( string $a, string $b ): bool {
		return Package::unit( $a ) !== Package::unit( $b );
	}

	/**
	 * Convert a currency row's money amounts for an import.
	 *
	 * Percent values are left untouched: only `fixed` types and the extra amount
	 * are store amounts.
	 *
	 * @param array<string, mixed> $row     Currency row from the package.
	 * @param string               $from    Package unit.
	 * @param string               $to      Site unit.
	 * @param bool                 $convert Whether to convert at all.
	 * @return array<string, mixed>
	 */
	public static function convert_currency_row( array $row, string $from, string $to, bool $convert ): array {
		if ( ! $convert || ! self::units_differ( $from, $to ) ) {
			return $row;
		}

		if ( 'fixed' === ( $row['profit_type'] ?? 'percent' ) && isset( $row['profit_value'] ) ) {
			$row['profit_value'] = self::convert_amount( (float) $row['profit_value'], $from, $to );
		}

		if ( 'fixed' === ( $row['commission_type'] ?? 'percent' ) && isset( $row['commission_value'] ) ) {
			$row['commission_value'] = self::convert_amount( (float) $row['commission_value'], $from, $to );
		}

		if ( isset( $row['extra_amount'] ) ) {
			$row['extra_amount'] = self::convert_amount( (float) $row['extra_amount'], $from, $to );
		}

		return $row;
	}

	/**
	 * Convert a formula's money-type variable defaults.
	 *
	 * @param array<string, mixed> $formula Formula row from the package.
	 * @param string               $from    Package unit.
	 * @param string               $to      Site unit.
	 * @param bool                 $convert Whether to convert at all.
	 * @return array<string, mixed>
	 */
	public static function convert_formula_row( array $formula, string $from, string $to, bool $convert ): array {
		if ( ! $convert || ! self::units_differ( $from, $to ) ) {
			return $formula;
		}

		$variables = $formula['variables'] ?? array();

		if ( is_string( $variables ) ) {
			$decoded   = json_decode( $variables, true );
			$variables = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $variables ) ) {
			return $formula;
		}

		foreach ( $variables as $index => $variable ) {
			if ( ! is_array( $variable ) ) {
				continue;
			}

			if ( 'custom' !== ( $variable['type'] ?? '' ) || empty( $variable['is_money'] ) ) {
				continue;
			}

			foreach ( array( 'default', 'min', 'max' ) as $key ) {
				if ( isset( $variable[ $key ] ) && is_numeric( $variable[ $key ] ) ) {
					$variables[ $index ][ $key ] = self::convert_amount( (float) $variable[ $key ], $from, $to );
				}
			}
		}

		$formula['variables'] = $variables;

		return $formula;
	}
}
