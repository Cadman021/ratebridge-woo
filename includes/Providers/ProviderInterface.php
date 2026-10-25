<?php
/**
 * Provider contract.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Every rate source must implement this interface.
 */
interface ProviderInterface {

	/**
	 * Machine key (unique, sanitize_key() safe), e.g. "manual".
	 *
	 * @return string
	 */
	public function get_key(): string;

	/**
	 * Human readable, translated label for the admin UI.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Fetch rates for the given provider symbols.
	 *
	 * Returned rates are STORE-CURRENCY amounts per 1 unit of the symbol.
	 *
	 * @param string[] $symbols Provider symbol identifiers (e.g. array( 'USD', 'GOLD18' )).
	 * @return array<string, float>|\WP_Error Map of symbol => rate on success, WP_Error on failure.
	 */
	public function fetch_rates( array $symbols ): array|\WP_Error;

	/**
	 * Description of the settings this provider needs.
	 *
	 * Example: array(
	 *     array(
	 *         'key'         => 'api_key',
	 *         'label'       => __( 'API key', 'ratebridge-woo' ),
	 *         'type'        => 'text', // text|password|number|checkbox|select
	 *         'required'    => true,
	 *         'description' => __( '...', 'ratebridge-woo' ),
	 *     ),
	 * )
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array;
}
