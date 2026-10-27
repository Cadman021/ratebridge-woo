<?php
/**
 * WordPress-facing products CSV service.
 *
 * Export streams paginated `wc_get_products()` results straight to the output
 * (never loading the catalogue into memory). Import is driven by the browser in
 * chunks of 100 rows and applies rows through the same meta-writing code the
 * product editor uses.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

use RateBridge\Core\Settings;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Product\ProductData;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Products CSV export/import.
 */
final class ProductsCsvService {

	/**
	 * Temporary upload directory (inside uploads).
	 */
	public const TMP_DIR = 'ratebridge-woo/tmp';

	/**
	 * Option holding pending import sessions (non-autoloaded).
	 */
	public const SESSION_OPTION = 'rbw_csv_sessions';

	/**
	 * Hours after which a pending file is deleted.
	 */
	public const TMP_TTL_HOURS = 1;

	/* --------------------------------------------------------------------- */
	/* Export                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Stream the CSV to the browser.
	 *
	 * @return void
	 */
	public function stream_export(): void {
		$filename = 'ratebridge-products-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			return;
		}

		fwrite( $out, Csv::bom() );
		fwrite( $out, ProductCsv::header() );

		$page = 1;

		do {
			$ids = $this->export_page( $page );

			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );

				if ( $product instanceof WC_Product ) {
					fwrite( $out, ProductCsv::row( $this->row_for( $product ) ) );
				}
			}

			++$page;
		} while ( count( $ids ) === ProductCsv::EXPORT_CHUNK );

		fclose( $out );
	}

	/**
	 * One page of managed product ids (simple + variable parents + variations).
	 *
	 * @param int $page Page number (1-based).
	 * @return int[]
	 */
	private function export_page( int $page ): array {
		global $wpdb;

		$per_page = ProductCsv::EXPORT_CHUNK;
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		/*
		 * Managed = `_rbw_enabled` = yes. The variation's parent is managed too, so
		 * variations of a managed parent are included even without their own flag.
		 */
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
				 WHERE p.post_type IN ('product', 'product_variation')
				 ORDER BY p.ID ASC
				 LIMIT %d OFFSET %d",
				ProductData::META_ENABLED,
				'yes',
				$per_page,
				$offset
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Build the export cells for one product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, string>
	 */
	public function row_for( WC_Product $product ): array {
		$id        = (int) $product->get_id();
		$data      = ProductData::read( $id );
		$parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		// Variations inherit the parent's mode/currency/formula when they have none.
		$mode = ProductData::mode( $id );

		if ( $parent_id > 0 && ! ProductData::is_formula_mode( $id ) && '' === ProductData::currency_code( $id ) ) {
			$mode = ProductData::mode( $parent_id );
		}

		$currency = ProductData::currency_code( $id );

		if ( '' === $currency && $parent_id > 0 ) {
			$currency = ProductData::currency_code( $parent_id );
		}

		$formula_slug = '';
		$formula_id   = ProductData::formula_id( $id );

		if ( 0 === $formula_id && $parent_id > 0 ) {
			$formula_id = ProductData::formula_id( $parent_id );
		}

		if ( $formula_id > 0 ) {
			$formula = ( new FormulaRepository() )->get( $formula_id );
			$formula_slug = $formula instanceof FormulaModel ? $formula->slug : '';
		}

		$overrides = ProductData::formula_overrides( $id );

		if ( empty( $overrides ) && $parent_id > 0 ) {
			$overrides = ProductData::formula_overrides( $parent_id );
		}

		$type = $product->is_type( 'variation' ) ? 'variation' : ( $product->is_type( 'variable' ) ? 'variable' : 'simple' );

		$row = array(
			'id'               => (string) $id,
			'parent_id'        => (string) $parent_id,
			'sku'              => (string) $product->get_sku(),
			'name'             => $product->get_name(),
			'type'             => $type,
			'mode'             => $mode,
			'currency'         => $currency,
			'base_price'       => $this->num( $data['base_price'] ),
			'sale_base_price'  => $this->num( $data['sale_price'] ),
			'min_price'        => $this->num( $data['min_price'] ),
			'fluctuation'      => $this->num( $data['fluctuation'] ),
			'profit_mode'      => (string) $data['profit_mode'],
			'profit_type'      => (string) ( $data['profit']['type'] ?? 'percent' ),
			'profit_value'     => $this->num( (float) ( $data['profit']['value'] ?? 0 ) ),
			'commission_type'  => (string) ( $data['commission']['type'] ?? 'percent' ),
			'commission_value' => $this->num( (float) ( $data['commission']['value'] ?? 0 ) ),
			'extra_amount'     => $this->num( (float) ( $data['extra']['value'] ?? 0 ) ),
			'formula_slug'     => $formula_slug,
			'formula_vars'     => empty( $overrides ) ? '' : (string) wp_json_encode( $overrides, JSON_UNESCAPED_UNICODE ),
		);

		return $row;
	}

	/**
	 * Format a number for CSV (empty when zero/absent).
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function num( float $value ): string {
		if ( $value <= 0 ) {
			return '';
		}

		$formatted = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/* --------------------------------------------------------------------- */
	/* Import                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate an uploaded CSV and create a chunked import session.
	 *
	 * @param string $path      Absolute path to the uploaded file.
	 * @param string $file_unit Unit of money cells in the file.
	 * @return array<string, mixed>|null Null on a hard error.
	 */
	public function prepare_session( string $path, string $file_unit ): ?array {
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		if ( strlen( $raw ) > ProductCsv::MAX_BYTES ) {
			return null;
		}

		$delimiter = Csv::detect_delimiter( substr( $raw, 0, 8192 ) );
		$parsed    = Csv::parse( $raw, $delimiter );

		if ( '' !== $parsed['error'] ) {
			return null;
		}

		$rows = $parsed['rows'];

		// Drop trailing blank rows.
		$rows = array_values(
			array_filter(
				$rows,
				static fn ( array $row ): bool => array_filter( $row, static fn ( $cell ) => '' !== trim( (string) $cell ) ) !== array()
			)
		);

		if ( count( $rows ) < 1 ) {
			return null;
		}

		$header = array_shift( $rows );

		if ( count( $rows ) > ProductCsv::MAX_ROWS ) {
			return null;
		}

		$mapped = ProductCsv::map_header( (array) $header );

		if ( '' !== $mapped['error'] ) {
			return array(
				'error'   => $mapped['error'],
				'missing' => $mapped['missing'],
			);
		}

		return array(
			'delimiter' => $delimiter,
			'map'       => $mapped['map'],
			'rows'      => $rows,
			'file_unit' => Package::unit( $file_unit ),
		);
	}

	/**
	 * Validate one chunk of rows and return the plan for them.
	 *
	 * @param array<int, string[]>   $rows     Raw rows of the chunk.
	 * @param array<string, int>     $map      Header map.
	 * @param string                 $file_unit Unit in the file.
	 * @param int                    $offset   Row offset (for numbering).
	 * @return array<string, mixed> {
	 *     @type array $apply    [ post_id => data ] for valid rows.
	 *     @type array $messages [ { row, message } ].
	 *     @type array $sample   First rows showing old -> new.
	 *     @type int   $valid
	 *     @type int   $invalid
	 * }
	 */
	public function plan_chunk( array $rows, array $map, string $file_unit, int $offset = 0 ): array {
		$context = $this->validation_context( $file_unit );
		$apply   = array();
		$messages = array();
		$sample  = array();
		$valid   = 0;
		$invalid = 0;

		foreach ( $rows as $index => $cells ) {
			$row_number = $offset + $index + 2; // +1 header, +1 human numbering.
			$extracted  = ProductCsv::extract( (array) $cells, $map );

			$post_id = $this->match_product( $extracted );

			if ( 0 === $post_id ) {
				++$invalid;
				$messages[] = array(
					'row'     => $row_number,
					'message' => __( 'No product matches this id or SKU (the importer never creates products).', 'ratebridge-woo' ),
				);
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$invalid;
				$messages[] = array(
					'row'     => $row_number,
					'message' => __( 'You are not allowed to edit this product.', 'ratebridge-woo' ),
				);
				continue;
			}

			$checked = ProductCsv::validate_row( $extracted, $context );

			if ( ! $checked['ok'] ) {
				++$invalid;
				$messages[] = array(
					'row'     => $row_number,
					'message' => $this->row_error_message( $checked['error'] ),
				);
				continue;
			}

			$product = wc_get_product( $post_id );

			if ( ! $product instanceof WC_Product ) {
				++$invalid;
				$messages[] = array(
					'row'     => $row_number,
					'message' => __( 'The product could not be loaded.', 'ratebridge-woo' ),
				);
				continue;
			}

			$apply[ $post_id ] = $checked['data'];
			++$valid;

			if ( count( $sample ) < 5 ) {
				$sample[] = array(
					'id'   => $post_id,
					'name' => $product->get_name(),
					'old'  => array(
						'base_price' => (string) ProductData::base_price( $post_id ),
						'mode'       => ProductData::mode( $post_id ),
						'currency'   => ProductData::currency_code( $post_id ),
					),
					'new'  => array(
						'base_price' => null === $checked['data']['base_price'] ? '' : (string) $checked['data']['base_price'],
						'mode'       => $checked['data']['mode'],
						'currency'   => $checked['data']['currency'],
					),
				);
			}
		}

		return array(
			'apply'    => $apply,
			'messages' => $messages,
			'sample'   => $sample,
			'valid'    => $valid,
			'invalid'  => $invalid,
		);
	}

	/**
	 * Apply a validated chunk of rows.
	 *
	 * @param array<int, array<string, mixed>> $apply Post id => validated data.
	 * @return int[] Touched post ids (for async repricing).
	 */
	public function apply_chunk( array $apply ): array {
		$touched = array();

		foreach ( $apply as $post_id => $data ) {
			$post_id = (int) $post_id;

			if ( $post_id <= 0 || ! is_array( $data ) ) {
				continue;
			}

			$this->apply_row( $post_id, $data );
			$touched[] = $post_id;
		}

		return $touched;
	}

	/**
	 * Write one validated row to the product meta.
	 *
	 * Uses exactly the same keys/sanitising rules as the product editor.
	 *
	 * @param int                  $post_id Product id.
	 * @param array<string, mixed> $data    Validated row data.
	 * @return void
	 */
	public function apply_row( int $post_id, array $data ): void {
		update_post_meta( $post_id, ProductData::META_ENABLED, 'yes' );

		$mode = (string) ( $data['mode'] ?? 'currency' );

		if ( ProductData::MODE_FORMULA === $mode ) {
			update_post_meta( $post_id, ProductData::META_MODE, ProductData::MODE_FORMULA );

			$formula = ( new FormulaRepository() )->get_by_slug( (string) ( $data['formula_slug'] ?? '' ) );

			if ( $formula instanceof FormulaModel ) {
				update_post_meta( $post_id, ProductData::META_FORMULA_ID, (string) $formula->id );
			}

			$overrides = (array) ( $data['formula_vars'] ?? array() );

			if ( empty( $overrides ) ) {
				delete_post_meta( $post_id, ProductData::META_FORMULA_VARS );
			} else {
				update_post_meta( $post_id, ProductData::META_FORMULA_VARS, wp_json_encode( $overrides ) );
			}
		} else {
			delete_post_meta( $post_id, ProductData::META_MODE );
			delete_post_meta( $post_id, ProductData::META_FORMULA_ID );
			delete_post_meta( $post_id, ProductData::META_FORMULA_VARS );

			if ( '' !== (string) ( $data['currency'] ?? '' ) ) {
				update_post_meta( $post_id, ProductData::META_CURRENCY, (string) $data['currency'] );
			}
		}

		$map = array(
			'base_price'      => ProductData::META_BASE_PRICE,
			'sale_base_price' => ProductData::META_SALE_BASE_PRICE,
			'min_price'       => ProductData::META_MIN_PRICE,
			'fluctuation'     => ProductData::META_FLUCTUATION,
			'profit_value'    => ProductData::META_PROFIT_VALUE,
			'commission_value' => ProductData::META_COMMISSION_VALUE,
			'extra_amount'    => ProductData::META_EXTRA_AMOUNT,
		);

		foreach ( $map as $key => $meta_key ) {
			$value = $data[ $key ] ?? null;

			if ( null === $value || ! is_numeric( $value ) ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			update_post_meta( $post_id, $meta_key, wc_format_decimal( (float) $value, 4 ) );
		}

		if ( isset( $data['profit_type'] ) ) {
			update_post_meta( $post_id, ProductData::META_PROFIT_TYPE, (string) $data['profit_type'] );
		}

		if ( isset( $data['commission_type'] ) ) {
			update_post_meta( $post_id, ProductData::META_COMMISSION_TYPE, (string) $data['commission_type'] );
		}

		if ( '' !== (string) ( $data['profit_mode'] ?? '' ) ) {
			update_post_meta( $post_id, ProductData::META_PROFIT_MODE, (string) $data['profit_mode'] );
		}

		// The price must be recalculated from the new settings.
		delete_post_meta( $post_id, ProductData::META_LAST_ERROR );
	}

	/**
	 * Validation context (valid codes, formula info) built once per request.
	 *
	 * @param string $file_unit Unit of money cells.
	 * @return array<string, mixed>
	 */
	private function validation_context( string $file_unit ): array {
		$formulas = array();

		foreach ( ( new FormulaRepository() )->get_all() as $formula ) {
			$formulas[ $formula->slug ] = array(
				'enabled' => $formula->is_enabled,
				'custom'  => array_column( $formula->variables_of_type( FormulaModel::TYPE_CUSTOM ), 'name' ),
			);
		}

		$codes = array();

		foreach ( ( new \RateBridge\Currency\CurrencyRepository() )->get_all() as $currency ) {
			$codes[] = $currency->code;
		}

		return array(
			'currencies' => $codes,
			'formulas'   => $formulas,
			'store_unit' => Settings::store_unit(),
			'file_unit'  => Package::unit( $file_unit ),
			'convert'    => ImportPlan::units_differ( $file_unit, Settings::store_unit() ),
		);
	}

	/**
	 * Find the product id for a row (id first, then SKU).
	 *
	 * @param array<string, string> $cells Row cells.
	 * @return int 0 when nothing matches.
	 */
	private function match_product( array $cells ): int {
		$id = isset( $cells['id'] ) ? (int) trim( $cells['id'] ) : 0;

		if ( $id > 0 ) {
			$product = wc_get_product( $id );

			if ( $product instanceof WC_Product ) {
				return $id;
			}
		}

		$sku = isset( $cells['sku'] ) ? trim( $cells['sku'] ) : '';

		if ( '' !== $sku ) {
			$found = wc_get_product_id_by_sku( $sku );

			if ( $found > 0 ) {
				return (int) $found;
			}
		}

		return 0;
	}

	/**
	 * Persian message for a row error code.
	 *
	 * @param string $code Error code (may be `code:detail`).
	 * @return string
	 */
	private function row_error_message( string $code ): string {
		$parts  = explode( ':', $code, 2 );
		$base   = $parts[0];
		$detail = $parts[1] ?? '';

		switch ( $base ) {
			case 'bad_mode':
				return __( 'The mode must be “currency” or “formula”.', 'ratebridge-woo' );
			case 'currency_required':
				return __( 'Currency mode needs a currency.', 'ratebridge-woo' );
			case 'unknown_currency':
				return __( 'The currency code does not exist on this site.', 'ratebridge-woo' );
			case 'formula_required':
				return __( 'Formula mode needs a formula slug.', 'ratebridge-woo' );
			case 'unknown_formula':
				return __( 'The formula slug does not exist on this site.', 'ratebridge-woo' );
			case 'formula_disabled':
				return __( 'The formula is disabled.', 'ratebridge-woo' );
			case 'bad_number':
				return sprintf(
					/* translators: %s: column name */
					__( 'Column “%s” is not a number.', 'ratebridge-woo' ),
					$detail
				);
			case 'negative_number':
				return sprintf(
					/* translators: %s: column name */
					__( 'Column “%s” must not be negative.', 'ratebridge-woo' ),
					$detail
				);
			case 'bad_enum':
				return sprintf(
					/* translators: %s: column name */
					__( 'Column “%s” has an invalid value.', 'ratebridge-woo' ),
					$detail
				);
			case 'bad_formula_vars':
				return __( 'formula_vars must be a JSON object.', 'ratebridge-woo' );
			case 'unknown_formula_var':
				return sprintf(
					/* translators: %s: variable name */
					__( '“%s” is not a custom variable of this formula.', 'ratebridge-woo' ),
					$detail
				);
			case 'bad_formula_var_value':
				return sprintf(
					/* translators: %s: variable name */
					__( 'The value of “%s” must be a non-negative number.', 'ratebridge-woo' ),
					$detail
				);
		}

		return __( 'The row is not valid.', 'ratebridge-woo' );
	}

	/* --------------------------------------------------------------------- */
	/* Temporary files                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Ensure the private temp directory exists with deny rules.
	 *
	 * @return string|false Absolute directory path, or false on failure.
	 */
	public function tmp_dir(): string|false {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::TMP_DIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Deny web access: Apache rule + a guard file for other servers.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private dir guard.
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Delete temp files older than the TTL (daily cron).
	 *
	 * @return int Number of files removed.
	 */
	public function cleanup_tmp(): int {
		$dir = $this->tmp_dir();

		if ( false === $dir ) {
			return 0;
		}

		$files = glob( $dir . '/import-*.csv' );
		$now   = time();
		$count = 0;

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $mtime && ( $now - $mtime ) > self::TMP_TTL_HOURS * HOUR_IN_SECONDS ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				++$count;
			}
		}

		return $count;
	}
}
