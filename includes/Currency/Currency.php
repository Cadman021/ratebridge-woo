<?php
/**
 * Currency value object.
 *
 * @package RateBridge
 */

namespace RateBridge\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only representation of a row from the currencies table.
 */
final class Currency {

	/**
	 * Constructor.
	 *
	 * @param int         $id                      Row ID (0 for unsaved).
	 * @param string      $code                    Unique code, e.g. USD.
	 * @param string      $name                    Display name.
	 * @param string      $symbol                  Display symbol.
	 * @param string      $provider                Provider key.
	 * @param string      $provider_symbol         Symbol understood by the provider.
	 * @param float       $rate                    Store-currency amount per 1 unit (in the stored unit, see Settings).
	 * @param bool        $is_enabled              Whether the currency is refreshed automatically.
	 * @param int         $update_interval_minutes Refresh interval in minutes.
	 * @param string|null $last_updated            UTC datetime string or null.
	 * @param string|null $last_error              Last provider error, if any.
	 * @param int         $sort_order              Admin ordering.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly string $name,
		public readonly string $symbol,
		public readonly string $provider,
		public readonly string $provider_symbol,
		public readonly float $rate,
		public readonly bool $is_enabled,
		public readonly int $update_interval_minutes,
		public readonly ?string $last_updated,
		public readonly ?string $last_error,
		public readonly int $sort_order,
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param object $row Row object from $wpdb.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			(int) ( $row->id ?? 0 ),
			(string) ( $row->code ?? '' ),
			(string) ( $row->name ?? '' ),
			(string) ( $row->symbol ?? '' ),
			(string) ( $row->provider ?? 'manual' ),
			(string) ( $row->provider_symbol ?? '' ),
			(float) ( $row->rate ?? 0 ),
			! empty( $row->is_enabled ),
			(int) ( $row->update_interval_minutes ?? 60 ),
			! empty( $row->last_updated ) ? (string) $row->last_updated : null,
			! empty( $row->last_error ) ? (string) $row->last_error : null,
			(int) ( $row->sort_order ?? 0 )
		);
	}

	/**
	 * Convert back to an array suitable for the repository.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                      => $this->id,
			'code'                    => $this->code,
			'name'                    => $this->name,
			'symbol'                  => $this->symbol,
			'provider'                => $this->provider,
			'provider_symbol'         => $this->provider_symbol,
			'rate'                    => $this->rate,
			'is_enabled'              => $this->is_enabled ? 1 : 0,
			'update_interval_minutes' => $this->update_interval_minutes,
			'last_updated'            => $this->last_updated,
			'last_error'              => $this->last_error,
			'sort_order'              => $this->sort_order,
		);
	}

	/**
	 * Whether the currency has a usable (non-zero) rate.
	 *
	 * @return bool
	 */
	public function has_rate(): bool {
		return $this->rate > 0;
	}

	/**
	 * Whether the refresh interval has elapsed since the last successful update.
	 *
	 * @param int|null $now Timestamp to compare against, defaults to time().
	 * @return bool
	 */
	public function is_due( ?int $now = null ): bool {
		if ( null === $this->last_updated ) {
			return true;
		}

		$now  = $now ?? time();
		$last = strtotime( $this->last_updated . ' UTC' );

		if ( false === $last ) {
			return true;
		}

		return ( $now - $last ) >= max( 5, $this->update_interval_minutes ) * MINUTE_IN_SECONDS;
	}
}
