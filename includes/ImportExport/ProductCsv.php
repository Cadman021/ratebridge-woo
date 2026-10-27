<?php
/**
 * Products CSV: column map, row serialisation and row validation.
 *
 * Pure PHP where it matters (serialise / validate a row from plain arrays), so
 * `tests/csv.php` covers the tricky parts. The WordPress-facing bits (streaming
 * export, chunked import) live in `ProductCsv`-adjacent methods below, which only
 * read via `wc_get_products` in pages.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

use RateBridge\Formula\FormulaModel;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Compat.php';

/**
 * Column definitions + row helpers for the products CSV.
 */
final class ProductCsv {

	/**
	 * Rows per export page.
	 */
	public const EXPORT_CHUNK = 200;

	/**
	 * Rows per import chunk (browser-driven).
	 */
	public const IMPORT_CHUNK = 100;

	/**
	 * Maximum rows accepted on import.
	 */
	public const MAX_ROWS = 20000;

	/**
	 * Maximum file size in bytes (5 MB).
	 */
	public const MAX_BYTES = 5242880;

	/**
	 * Ordered column list (also the export header).
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array(
			'id',
			'parent_id',
			'sku',
			'name',
			'type',
			'mode',
			'currency',
			'base_price',
			'sale_base_price',
			'min_price',
			'fluctuation',
			'profit_mode',
			'profit_type',
			'profit_value',
			'commission_type',
			'commission_value',
			'extra_amount',
			'formula_slug',
			'formula_vars',
		);
	}

	/**
	 * Columns the importer requires (plus one identifier).
	 *
	 * @return string[]
	 */
	public static function required_columns(): array {
		return array( 'mode' );
	}

	/**
	 * Header row for the export.
	 *
	 * @return string
	 */
	public static function header(): string {
		return Csv::serialize_row( self::columns() );
	}

	/**
	 * Serialise one product row.
	 *
	 * @param array<string, mixed> $row Cell values keyed by column.
	 * @return string
	 */
	public static function row( array $row ): string {
		$cells = array();

		foreach ( self::columns() as $column ) {
			$value = $row[ $column ] ?? '';
			$cells[] = is_scalar( $value ) ? (string) $value : '';
		}

		return Csv::serialize_row( $cells );
	}

	/**
	 * Map a header row to column indexes (case/space insensitive).
	 *
	 * @param string[] $header Header cells.
	 * @return array{map:array<string,int>,missing:string[],error:string}
	 */
	public static function map_header( array $header ): array {
		$known = self::columns();
		$map   = array();

		foreach ( $header as $index => $cell ) {
			$name = Csv::normalize_header( (string) $cell );

			if ( in_array( $name, $known, true ) && ! isset( $map[ $name ] ) ) {
				$map[ $name ] = (int) $index;
			}
		}

		$missing = array_values( array_diff( self::required_columns(), array_keys( $map ) ) );
		$error   = '';

		if ( ! empty( $missing ) ) {
			$error = 'missing_columns';
		} elseif ( ! isset( $map['id'] ) && ! isset( $map['sku'] ) ) {
			$error = 'missing_identifier';
		}

		return array(
			'map'     => $map,
			'missing' => $missing,
			'error'   => $error,
		);
	}

	/**
	 * Build a column => raw cell map for one CSV row.
	 *
	 * @param string[]        $cells Row cells.
	 * @param array<string, int> $map  Header map.
	 * @return array<string, string> Unescaped cell values.
	 */
	public static function extract( array $cells, array $map ): array {
		$out = array();

		foreach ( $map as $column => $index ) {
			$out[ $column ] = Csv::unescape_cell( isset( $cells[ $index ] ) ? (string) $cells[ $index ] : '' );
		}

		return $out;
	}

	/**
	 * Validate one extracted row.
	 *
	 * Pure: the caller supplies the set of valid currency codes, formula slugs and
	 * the custom variable names of each formula.
	 *
	 * @param array<string, string> $cells        Column => value.
	 * @param array<string, mixed>  $context      {
	 *     @type string[] $currencies  Valid currency codes.
	 *     @type array<string, array{enabled:bool,slugs:?string[],custom:string[]}> $formulas Formula info by slug.
	 *     @type string   $store_unit  Site unit.
	 *     @type string   $file_unit   Unit of money cells in the file.
	 *     @type bool     $convert     Whether to convert money amounts.
	 * }
	 * @return array{ok:bool,error:string,data:array<string,mixed>}
	 */
	public static function validate_row( array $cells, array $context ): array {
		$mode = isset( $cells['mode'] ) ? sanitize_key_compat( Csv::normalize_number( $cells['mode'] ) ) : '';

		if ( ! in_array( $mode, array( 'currency', 'formula' ), true ) ) {
			return self::row_error( 'bad_mode' );
		}

		$currency = isset( $cells['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $cells['currency'] ) ) ?? '' ) : '';
		$formula  = isset( $cells['formula_slug'] ) ? sanitize_key_compat( trim( $cells['formula_slug'] ) ) : '';

		$valid_currencies = isset( $context['currencies'] ) ? (array) $context['currencies'] : array();
		$formulas         = isset( $context['formulas'] ) && is_array( $context['formulas'] ) ? $context['formulas'] : array();

		if ( 'currency' === $mode ) {
			if ( '' === $currency ) {
				return self::row_error( 'currency_required' );
			}

			if ( ! empty( $valid_currencies ) && ! in_array( $currency, $valid_currencies, true ) ) {
				return self::row_error( 'unknown_currency' );
			}
		} else {
			if ( '' === $formula ) {
				return self::row_error( 'formula_required' );
			}

			if ( ! isset( $formulas[ $formula ] ) ) {
				return self::row_error( 'unknown_formula' );
			}

			if ( empty( $formulas[ $formula ]['enabled'] ) ) {
				return self::row_error( 'formula_disabled' );
			}
		}

		// Numbers: Persian digits, `٫`, thousands separators normalised first.
		$numeric = array( 'base_price', 'sale_base_price', 'min_price', 'fluctuation', 'profit_value', 'commission_value', 'extra_amount' );
		$money   = array( 'min_price', 'fluctuation', 'extra_amount' );
		$values  = array();

		$convert = ! empty( $context['convert'] );
		$from    = Package::unit( $context['file_unit'] ?? 'toman' );
		$to      = Package::unit( $context['store_unit'] ?? $from );

		foreach ( $numeric as $column ) {
			$raw = isset( $cells[ $column ] ) ? Csv::normalize_number( (string) $cells[ $column ] ) : '';

			if ( '' === $raw ) {
				$values[ $column ] = null;
				continue;
			}

			// Remove thousand separators but keep the decimal point.
			$raw = str_replace( ',', '', $raw );

			if ( ! is_numeric( $raw ) ) {
				return self::row_error( 'bad_number', $column );
			}

			$value = (float) $raw;

			if ( ! is_finite( $value ) || $value < 0 ) {
				return self::row_error( 'negative_number', $column );
			}

			if ( $convert && ImportPlan::units_differ( $from, $to ) ) {
				$is_fixed = in_array( $column, $money, true );

				if ( 'profit_value' === $column ) {
					$is_fixed = 'fixed' === sanitize_key_compat( (string) ( $cells['profit_type'] ?? 'percent' ) );
				}

				if ( 'commission_value' === $column ) {
					$is_fixed = 'fixed' === sanitize_key_compat( (string) ( $cells['commission_type'] ?? 'percent' ) );
				}

				if ( $is_fixed ) {
					$value = ImportPlan::convert_amount( $value, $from, $to );
				}
			}

			$values[ $column ] = $value;
		}

		// Enumerations.
		$profit_mode = isset( $cells['profit_mode'] ) ? sanitize_key_compat( $cells['profit_mode'] ) : '';
		$profit_mode = in_array( $profit_mode, array( 'none', 'add', 'replace' ), true ) ? $profit_mode : '';

		if ( '' === $profit_mode && '' !== trim( (string) ( $cells['profit_mode'] ?? '' ) ) ) {
			return self::row_error( 'bad_enum', 'profit_mode' );
		}

		$profit_type = isset( $cells['profit_type'] ) ? sanitize_key_compat( $cells['profit_type'] ) : '';
		$profit_type = in_array( $profit_type, array( 'percent', 'fixed' ), true ) ? $profit_type : 'percent';

		$commission_type = isset( $cells['commission_type'] ) ? sanitize_key_compat( $cells['commission_type'] ) : '';
		$commission_type = in_array( $commission_type, array( 'percent', 'fixed' ), true ) ? $commission_type : 'percent';

		// Formula variable overrides: JSON object, declared custom names only.
		$overrides = array();

		if ( 'formula' === $mode && '' !== trim( (string) ( $cells['formula_vars'] ?? '' ) ) ) {
			$decoded = json_decode( (string) $cells['formula_vars'], true );

			if ( ! is_array( $decoded ) ) {
				return self::row_error( 'bad_formula_vars' );
			}

			$allowed = isset( $formulas[ $formula ]['custom'] ) ? (array) $formulas[ $formula ]['custom'] : array();

			foreach ( $decoded as $name => $value ) {
				$name = (string) $name;

				if ( ! in_array( $name, $allowed, true ) ) {
					return self::row_error( 'unknown_formula_var', $name );
				}

				$number = is_numeric( $value ) ? (float) $value : null;

				if ( null === $number || ! is_finite( $number ) || $number < 0 ) {
					return self::row_error( 'bad_formula_var_value', $name );
				}

				$overrides[ $name ] = $number;
			}
		}

		return array(
			'ok'    => true,
			'error' => '',
			'data'  => array(
				'mode'            => $mode,
				'currency'        => 'currency' === $mode ? $currency : '',
				'formula_slug'    => 'formula' === $mode ? $formula : '',
				'formula_vars'    => $overrides,
				'base_price'      => $values['base_price'],
				'sale_base_price' => $values['sale_base_price'],
				'min_price'       => $values['min_price'],
				'fluctuation'     => $values['fluctuation'],
				'profit_mode'     => $profit_mode,
				'profit_type'     => $profit_type,
				'profit_value'    => $values['profit_value'],
				'commission_type' => $commission_type,
				'commission_value' => $values['commission_value'],
				'extra_amount'    => $values['extra_amount'],
			),
		);
	}

	/**
	 * Build a row error.
	 *
	 * @param string $code   Error code.
	 * @param string $detail Detail.
	 * @return array{ok:bool,error:string,data:array<string,mixed>}
	 */
	private static function row_error( string $code, string $detail = '' ): array {
		return array(
			'ok'    => false,
			'error' => '' === $detail ? $code : $code . ':' . $detail,
			'data'  => array(),
		);
	}
}
