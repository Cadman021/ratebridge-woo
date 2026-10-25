<?php
/**
 * Currency repository (all DB access for the currencies table).
 *
 * @package RateBridge
 */

namespace RateBridge\Currency;

use RateBridge\Core\Installer;
use RateBridge\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + query helpers for currencies.
 */
final class CurrencyRepository {

	/**
	 * Table name (with prefix).
	 *
	 * @return string
	 */
	public function table(): string {
		return Installer::currencies_table();
	}

	/**
	 * Get a single currency by ID.
	 *
	 * @param int $id Currency ID.
	 * @return Currency|null
	 */
	public function get( int $id ): ?Currency {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $row ? Currency::from_row( $row ) : null;
	}

	/**
	 * Get a single currency by code.
	 *
	 * @param string $code Currency code.
	 * @return Currency|null
	 */
	public function get_by_code( string $code ): ?Currency {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE code = %s", $code ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $row ? Currency::from_row( $row ) : null;
	}

	/**
	 * All currencies ordered for display.
	 *
	 * @return Currency[]
	 */
	public function get_all(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( Currency::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Enabled currencies only.
	 *
	 * @return Currency[]
	 */
	public function get_enabled(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} WHERE is_enabled = 1 ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( Currency::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Enabled currencies grouped by provider key.
	 *
	 * @return array<string, Currency[]>
	 */
	public function get_enabled_grouped_by_provider(): array {
		$grouped = array();

		foreach ( $this->get_enabled() as $currency ) {
			$grouped[ $currency->provider ][] = $currency;
		}

		return $grouped;
	}

	/**
	 * Create a currency.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return int New row ID (0 on failure).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->sanitize_row_data( $data, true );

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'code'                    => $data['code'] ?? '',
				'name'                    => $data['name'] ?? '',
				'symbol'                  => $data['symbol'] ?? '',
				'provider'                => $data['provider'] ?? 'manual',
				'provider_symbol'         => $data['provider_symbol'] ?? '',
				'rate'                    => $data['rate'] ?? 0.0,
				'is_enabled'              => $data['is_enabled'] ?? 0,
				'update_interval_minutes' => $data['update_interval_minutes'] ?? Settings::sanitize_interval( Settings::get( 'default_update_interval', 60 ) ),
				'last_updated'            => $data['last_updated'] ?? null,
				'last_error'              => $data['last_error'] ?? null,
				'sort_order'              => $data['sort_order'] ?? 0,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a currency by ID.
	 *
	 * @param int                  $id   Currency ID.
	 * @param array<string, mixed> $data Fields to change.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$clean = $this->sanitize_row_data( $data, false );

		if ( empty( $clean ) ) {
			return false;
		}

		$clean['updated_at'] = current_time( 'mysql', true );

		$formats = array(
			'code'                    => '%s',
			'name'                    => '%s',
			'symbol'                  => '%s',
			'provider'                => '%s',
			'provider_symbol'         => '%s',
			'rate'                    => '%f',
			'is_enabled'              => '%d',
			'update_interval_minutes' => '%d',
			'last_updated'            => '%s',
			'last_error'              => '%s',
			'sort_order'              => '%d',
			'updated_at'              => '%s',
		);

		$formats_list = array();

		foreach ( array_keys( $clean ) as $key ) {
			$formats_list[] = $formats[ $key ] ?? '%s';
		}

		$result = $wpdb->update( $this->table(), $clean, array( 'id' => $id ), $formats_list, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Store a successfully fetched rate.
	 *
	 * Never writes a zero or negative rate.
	 *
	 * @param int   $id   Currency ID.
	 * @param float $rate Store-currency amount per 1 unit.
	 * @return bool
	 */
	public function update_rate( int $id, float $rate ): bool {
		global $wpdb;

		if ( $id <= 0 || ! is_finite( $rate ) || $rate <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		$result = $wpdb->update(
			$this->table(),
			array(
				'rate'         => $rate,
				'last_updated' => $now,
				'last_error'   => null,
				'updated_at'   => $now,
			),
			array( 'id' => $id ),
			array( '%f', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Record a provider failure while keeping the previous rate.
	 *
	 * @param int    $id      Currency ID.
	 * @param string $message Error message.
	 * @return bool
	 */
	public function set_error( int $id, string $message ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$result = $wpdb->update(
			$this->table(),
			array(
				'last_error' => mb_substr( $message, 0, 1000 ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Toggle the enabled flag.
	 *
	 * @param int  $id      Currency ID.
	 * @param bool $enabled New state.
	 * @return bool
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		return $this->update( $id, array( 'is_enabled' => $enabled ? 1 : 0 ) );
	}

	/**
	 * Delete a currency.
	 *
	 * @param int $id Currency ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$deleted = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		return (bool) $deleted;
	}

	/**
	 * Whether a code already exists (optionally ignoring one ID).
	 *
	 * @param string $code      Currency code.
	 * @param int    $ignore_id ID to exclude from the check.
	 * @return bool
	 */
	public function code_exists( string $code, int $ignore_id = 0 ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE code = %s AND id <> %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$code,
				$ignore_id
			)
		);

		return null !== $found;
	}

	/**
	 * Sanitize incoming currency data.
	 *
	 * @param array<string, mixed> $data   Raw data.
	 * @param bool                 $is_new Whether defaults should be filled in.
	 * @return array<string, mixed>
	 */
	private function sanitize_row_data( array $data, bool $is_new ): array {
		$clean = array();

		if ( array_key_exists( 'code', $data ) || $is_new ) {
			$clean['code'] = strtoupper( sanitize_text_field( (string) ( $data['code'] ?? '' ) ) );
		}

		if ( array_key_exists( 'name', $data ) || $is_new ) {
			$clean['name'] = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		}

		if ( array_key_exists( 'symbol', $data ) || $is_new ) {
			$clean['symbol'] = sanitize_text_field( (string) ( $data['symbol'] ?? '' ) );
		}

		if ( array_key_exists( 'provider', $data ) || $is_new ) {
			$clean['provider'] = sanitize_key( (string) ( $data['provider'] ?? 'manual' ) );
		}

		if ( array_key_exists( 'provider_symbol', $data ) || $is_new ) {
			$clean['provider_symbol'] = sanitize_text_field( (string) ( $data['provider_symbol'] ?? '' ) );
		}

		if ( array_key_exists( 'rate', $data ) || $is_new ) {
			$rate           = (float) ( $data['rate'] ?? 0 );
			$clean['rate']  = is_finite( $rate ) && $rate > 0 ? $rate : 0.0;
		}

		if ( array_key_exists( 'is_enabled', $data ) || $is_new ) {
			$clean['is_enabled'] = ! empty( $data['is_enabled'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'update_interval_minutes', $data ) || $is_new ) {
			$clean['update_interval_minutes'] = Settings::sanitize_interval( $data['update_interval_minutes'] ?? Settings::get( 'default_update_interval', 60 ) );
		}

		if ( array_key_exists( 'last_updated', $data ) ) {
			$clean['last_updated'] = empty( $data['last_updated'] ) ? null : (string) $data['last_updated'];
		}

		if ( array_key_exists( 'last_error', $data ) ) {
			$clean['last_error'] = empty( $data['last_error'] ) ? null : mb_substr( sanitize_textarea_field( (string) $data['last_error'] ), 0, 1000 );
		}

		if ( array_key_exists( 'sort_order', $data ) || $is_new ) {
			$clean['sort_order'] = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
		}

		return $clean;
	}
}
