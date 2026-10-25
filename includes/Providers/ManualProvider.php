<?php
/**
 * Manual provider: rates typed in by the administrator.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * No HTTP calls. Rates are entered manually in the admin UI, so this
 * provider never overwrites them.
 */
final class ManualProvider extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 */
	public function get_key(): string {
		return 'manual';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Manual (typed by admin)', 'ratebridge-woo' );
	}

	/**
	 * Manual rates are never fetched automatically.
	 *
	 * @param string[] $symbols Provider symbols.
	 * @return array<string, float>|WP_Error
	 */
	public function fetch_rates( array $symbols ): array|\WP_Error {
		return new WP_Error(
			'rbw_manual_provider',
			__( 'Manual currencies are not updated automatically. Edit the currency to change its rate.', 'ratebridge-woo' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_response( string $body, array $args = array() ): array|\WP_Error {
		return new WP_Error( 'rbw_manual_provider', __( 'The manual provider does not parse remote responses.', 'ratebridge-woo' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array();
	}

	/**
	 * Manual currencies have no provider catalogue.
	 *
	 * @return array<string, string>
	 */
	public function get_known_symbols(): array {
		return array();
	}
}
