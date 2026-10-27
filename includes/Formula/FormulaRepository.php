<?php
/**
 * Formula repository: all DB access for the formulas table.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

use RateBridge\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + lookups for formulas.
 */
final class FormulaRepository {

	/**
	 * Table name (with prefix).
	 *
	 * @return string
	 */
	public function table(): string {
		return Installer::formulas_table();
	}

	/**
	 * Fetch one formula by id.
	 *
	 * @param int $id Formula id.
	 * @return FormulaModel|null
	 */
	public function get( int $id ): ?FormulaModel {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $row ? FormulaModel::from_row( $row ) : null;
	}

	/**
	 * Fetch one formula by slug.
	 *
	 * @param string $slug Slug.
	 * @return FormulaModel|null
	 */
	public function get_by_slug( string $slug ): ?FormulaModel {
		global $wpdb;

		if ( '' === $slug ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE slug = %s", $slug ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $row ? FormulaModel::from_row( $row ) : null;
	}

	/**
	 * All formulas.
	 *
	 * @param bool $enabled_only Only enabled rows.
	 * @return FormulaModel[]
	 */
	public function get_all( bool $enabled_only = false ): array {
		global $wpdb;

		$sql  = "SELECT * FROM {$this->table()}";
		$sql .= $enabled_only ? ' WHERE is_enabled = 1' : '';
		$sql .= ' ORDER BY name ASC, id ASC';

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( FormulaModel::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Enabled formulas keyed by id (for select controls).
	 *
	 * @return array<int, string>
	 */
	public function choices(): array {
		$out = array();

		foreach ( $this->get_all( true ) as $formula ) {
			$out[ $formula->id ] = $formula->name;
		}

		return $out;
	}

	/**
	 * Create a formula.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return int New id (0 on failure).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$clean = $this->sanitize( $data, true );

		if ( '' === $clean['name'] || '' === $clean['expression'] ) {
			return 0;
		}

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'name'          => $clean['name'],
				'slug'          => $this->unique_slug( $clean['slug'], 0 ),
				'expression'    => $clean['expression'],
				'description'   => $clean['description'],
				'variables'     => wp_json_encode( $clean['variables'] ),
				'rounding_mode' => $clean['rounding_mode'],
				'rounding_step' => $clean['rounding_step'],
				'is_enabled'    => $clean['is_enabled'],
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * A formula was created.
		 *
		 * @param int          $id      Formula id.
		 * @param FormulaModel $formula The stored formula.
		 */
		do_action( 'rbw_formula_saved', $id, $this->get( $id ) );

		return $id;
	}

	/**
	 * Update a formula.
	 *
	 * @param int                  $id   Formula id.
	 * @param array<string, mixed> $data Raw data.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( $id <= 0 || null === $this->get( $id ) ) {
			return false;
		}

		$clean = $this->sanitize( $data, false );

		if ( empty( $clean ) ) {
			return false;
		}

		if ( array_key_exists( 'slug', $clean ) ) {
			$clean['slug'] = $this->unique_slug( $clean['slug'], $id );
		}

		if ( array_key_exists( 'variables', $clean ) ) {
			$clean['variables'] = wp_json_encode( $clean['variables'] );
		}

		$clean['updated_at'] = current_time( 'mysql', true );

		$formats = array(
			'name'          => '%s',
			'slug'          => '%s',
			'expression'    => '%s',
			'description'   => '%s',
			'variables'     => '%s',
			'rounding_mode' => '%s',
			'rounding_step' => '%f',
			'is_enabled'    => '%d',
			'updated_at'    => '%s',
		);

		$formats_list = array();

		foreach ( array_keys( $clean ) as $key ) {
			$formats_list[] = $formats[ $key ] ?? '%s';
		}

		$result = $wpdb->update( $this->table(), $clean, array( 'id' => $id ), $formats_list, array( '%d' ) );

		if ( false === $result ) {
			return false;
		}

		/**
		 * A formula was saved (created or updated).
		 *
		 * Listeners reprice the products that use it, so an expression change is
		 * never left unapplied.
		 *
		 * @param int          $id      Formula id.
		 * @param FormulaModel $formula The stored formula.
		 */
		do_action( 'rbw_formula_saved', $id, $this->get( $id ) );

		return true;
	}

	/**
	 * Delete a formula.
	 *
	 * @param int $id Formula id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$exist = $this->get( $id );

		if ( null === $exist ) {
			return false;
		}

		$deleted = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			/**
			 * A formula was deleted.
			 *
			 * @param int    $id   Formula id.
			 * @param string $slug Slug of the deleted formula.
			 */
			do_action( 'rbw_formula_deleted', $id, $exist->slug );
		}

		return (bool) $deleted;
	}

	/**
	 * Formulas that reference a currency variable bound to a code.
	 *
	 * Used by the rate-change listener to reprice formula products too.
	 *
	 * @param string $code Currency code.
	 * @return FormulaModel[]
	 */
	public function find_using_currency( string $code ): array {
		global $wpdb;

		$code = strtoupper( trim( $code ) );

		if ( '' === $code ) {
			return array();
		}

		/*
		 * The variables column is JSON. A LIKE on the serialised document is
		 * enough to pre-filter cheaply, then the exact check happens in PHP so a
		 * substring match ("USD" vs "USDX") can never produce a false positive.
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE is_enabled = 1 AND variables LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $code ) . '%'
			)
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$formula = FormulaModel::from_row( $row );

			if ( in_array( $code, $formula->currency_codes(), true ) ) {
				$out[] = $formula;
			}
		}

		return $out;
	}

	/**
	 * How many products use a formula.
	 *
	 * @param int $id Formula id.
	 * @return int
	 */
	public function count_products_using( int $id ): int {
		global $wpdb;

		if ( $id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'_rbw_formula_id',
				(string) $id
			)
		);
	}

	/**
	 * Product ids using a formula (repricing batches).
	 *
	 * @param int $id Formula id.
	 * @return int[]
	 */
	public function product_ids_using( int $id ): array {
		global $wpdb;

		if ( $id <= 0 ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'_rbw_formula_id',
				(string) $id
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Products that use a formula, including variable parents of variations.
	 *
	 * @param int $id Formula id.
	 * @return int[] Post ids to reprice (parents, not variations).
	 */
	public function reprice_target_ids( int $id ): array {
		$targets = array();

		foreach ( $this->product_ids_using( $id ) as $post_id ) {
			$parent = (int) wp_get_post_parent_id( $post_id );

			// A variation is repriced through its parent.
			$targets[] = $parent > 0 ? $parent : $post_id;
		}

		return array_values( array_unique( array_filter( $targets ) ) );
	}

	/**
	 * Slug that is unique in the table.
	 *
	 * @param string $slug      Desired slug.
	 * @param int    $ignore_id Row to ignore.
	 * @return string
	 */
	public function unique_slug( string $slug, int $ignore_id = 0 ): string {
		global $wpdb;

		$base = sanitize_key( $slug );

		if ( '' === $base ) {
			$base = 'formula';
		}

		$candidate = $base;
		$attempt   = 1;

		while ( true ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->table()} WHERE slug = %s AND id <> %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$candidate,
					$ignore_id
				)
			);

			if ( null === $found ) {
				return $candidate;
			}

			++$attempt;
			$candidate = $base . '-' . $attempt;

			if ( $attempt > 100 ) {
				return $base . '-' . wp_generate_password( 6, false, false );
			}
		}
	}

	/**
	 * Sanitise incoming formula data.
	 *
	 * @param array<string, mixed> $data   Raw data.
	 * @param bool                 $is_new Whether defaults must be filled.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $data, bool $is_new ): array {
		$clean = array();

		if ( array_key_exists( 'name', $data ) || $is_new ) {
			$clean['name'] = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		}

		if ( array_key_exists( 'slug', $data ) || $is_new ) {
			$clean['slug'] = sanitize_key( (string) ( $data['slug'] ?? '' ) );

			/*
			 * `sanitize_title()` percent-encodes non-Latin names ("قیمت طلا" ->
			 * "%d9%82..."), which makes an unreadable slug. A Persian name has no
			 * Latin letters to keep, so fall back to a stable, readable default
			 * (`formula`, `formula-2`, …) and let unique_slug() number it.
			 */
			if ( '' === $clean['slug'] && ! empty( $clean['name'] ) ) {
				$candidate = sanitize_title( $clean['name'] );

				if ( '' !== $candidate && ! str_contains( $candidate, '%' ) ) {
					$clean['slug'] = $candidate;
				}
			}
		}

		if ( array_key_exists( 'expression', $data ) || $is_new ) {
			// Keep the raw text: the engine normalises it, and the admin must see
			// exactly what they typed. Only strip tags and control characters.
			$expression = (string) ( $data['expression'] ?? '' );
			$expression = wp_strip_all_tags( $expression );
			$expression = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $expression ) ?? '';

			$clean['expression'] = trim( $expression );
		}

		if ( array_key_exists( 'description', $data ) || $is_new ) {
			$clean['description'] = sanitize_textarea_field( (string) ( $data['description'] ?? '' ) );
		}

		if ( array_key_exists( 'variables', $data ) || $is_new ) {
			$clean['variables'] = FormulaModel::sanitize_variables( $data['variables'] ?? array() );
		}

		if ( array_key_exists( 'rounding_mode', $data ) || $is_new ) {
			$mode = sanitize_key( (string) ( $data['rounding_mode'] ?? '' ) );

			$clean['rounding_mode'] = in_array( $mode, array( 'none', 'nearest', 'ceil', 'floor' ), true ) ? $mode : '';
		}

		if ( array_key_exists( 'rounding_step', $data ) || $is_new ) {
			$step = (float) ( $data['rounding_step'] ?? 0 );

			$clean['rounding_step'] = is_finite( $step ) && $step > 0 ? $step : 0.0;
		}

		if ( array_key_exists( 'is_enabled', $data ) || $is_new ) {
			$clean['is_enabled'] = ! empty( $data['is_enabled'] ) ? 1 : 0;
		}

		return $clean;
	}
}
