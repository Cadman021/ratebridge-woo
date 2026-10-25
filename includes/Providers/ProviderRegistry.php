<?php
/**
 * Provider registry.
 *
 * @package RateBridge
 */

namespace RateBridge\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Collects providers registered through the `rbw_register_providers` filter.
 */
final class ProviderRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved providers keyed by provider key.
	 *
	 * @var array<string, ProviderInterface>|null
	 */
	private ?array $providers = null;

	/**
	 * Private constructor (use instance()).
	 */
	private function __construct() {}

	/**
	 * Get the shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * All registered providers, keyed by provider key.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		$built_in = array(
			new ManualProvider(),
			new MockProvider(),
		);

		/**
		 * Filter the registered rate providers.
		 *
		 * @param ProviderInterface[] $providers Provider instances.
		 */
		$providers = apply_filters( 'rbw_register_providers', $built_in );

		$resolved = array();

		if ( is_array( $providers ) ) {
			foreach ( $providers as $provider ) {
				if ( $provider instanceof ProviderInterface ) {
					$resolved[ $provider->get_key() ] = $provider;
				}
			}
		}

		$this->providers = $resolved;

		return $this->providers;
	}

	/**
	 * Get one provider by key.
	 *
	 * @param string $key Provider key.
	 * @return ProviderInterface|null
	 */
	public function get( string $key ): ?ProviderInterface {
		$all = $this->all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Get a provider or a WP_Error.
	 *
	 * @param string $key Provider key.
	 * @return ProviderInterface|WP_Error
	 */
	public function get_or_error( string $key ): ProviderInterface|WP_Error {
		$provider = $this->get( $key );

		if ( null === $provider ) {
			return new WP_Error(
				'rbw_unknown_provider',
				sprintf(
					/* translators: %s: provider key */
					__( 'Unknown rate provider: %s', 'ratebridge-woo' ),
					$key
				)
			);
		}

		return $provider;
	}

	/**
	 * Keys => labels map for select boxes.
	 *
	 * @return array<string, string>
	 */
	public function choices(): array {
		$choices = array();

		foreach ( $this->all() as $key => $provider ) {
			$choices[ $key ] = $provider->get_label();
		}

		return $choices;
	}

	/**
	 * Reset the cache (mainly for tests).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->providers = null;
	}
}
