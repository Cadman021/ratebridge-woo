<?php
/**
 * Provider settings screen logic: schema rendering data, saving, testing.
 *
 * @package RateBridge
 */

namespace RateBridge\Admin;

use RateBridge\Providers\AbstractProvider;
use RateBridge\Providers\ProviderInterface;
use RateBridge\Providers\ProviderRegistry;
use RateBridge\Providers\RequestBudget;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the provider schema to the admin UI without leaking secrets.
 */
final class ProviderSettings {

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private ProviderRegistry $registry;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry|null $registry Registry.
	 */
	public function __construct( ?ProviderRegistry $registry = null ) {
		$this->registry = $registry ?? ProviderRegistry::instance();
	}

	/**
	 * Mask a secret for display: bullets plus the last 4 characters.
	 *
	 * @param string $secret Stored secret.
	 * @return string Empty string when nothing is stored.
	 */
	public static function mask( string $secret ): string {
		$secret = trim( $secret );

		if ( '' === $secret ) {
			return '';
		}

		$length = mb_strlen( $secret );

		if ( $length <= 4 ) {
			return str_repeat( '•', 8 );
		}

		return str_repeat( '•', 8 ) . mb_substr( $secret, -4 );
	}

	/**
	 * Whether a stored secret exists.
	 *
	 * @param string $secret Stored secret.
	 * @return bool
	 */
	public static function has_secret( string $secret ): bool {
		return '' !== trim( $secret );
	}

	/**
	 * Sanitise one submitted value according to its field definition.
	 *
	 * @param array<string, mixed> $field   Field definition.
	 * @param mixed                $raw     Raw submitted value.
	 * @param string               $old     Existing stored value.
	 * @return mixed Sanitised value.
	 */
	public static function sanitize_field( array $field, mixed $raw, string $old = '' ): mixed {
		$type = (string) ( $field['type'] ?? 'text' );

		switch ( $type ) {
			case 'password':
				$value = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';

				// Empty submission keeps the existing secret.
				return '' === $value ? $old : sanitize_text_field( $value );

			case 'number':
				$value = is_numeric( $raw ) ? (int) $raw : (int) ( $field['default'] ?? 0 );
				$min   = isset( $field['min'] ) ? (int) $field['min'] : 0;

				return max( $min, $value );

			case 'checkbox':
				return ! empty( $raw ) && '0' !== (string) $raw;

			case 'select':
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? array_keys( $field['options'] ) : array();
				$value   = is_string( $raw ) ? sanitize_key( wp_unslash( $raw ) ) : '';

				return in_array( $value, $options, true ) ? $value : (string) ( $field['default'] ?? '' );

			case 'text':
			default:
				$value = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';

				return '' === $value ? (string) ( $field['default'] ?? '' ) : $value;
		}
	}

	/**
	 * Validate a provider's settings, returning field-level errors.
	 *
	 * @param ProviderInterface    $provider Provider.
	 * @param array<string, mixed> $values   Sanitised values.
	 * @return array<string, string> field key => error message.
	 */
	public static function validate( ProviderInterface $provider, array $values ): array {
		$errors = array();

		foreach ( $provider->get_settings_fields() as $field ) {
			$key      = (string) ( $field['key'] ?? '' );
			$required = ! empty( $field['required'] );

			if ( '' === $key || ! $required ) {
				continue;
			}

			$value = $values[ $key ] ?? '';

			if ( is_string( $value ) && '' === trim( $value ) ) {
				$errors[ $key ] = sprintf(
					/* translators: %s: field label */
					__( '%s is required.', 'ratebridge-woo' ),
					(string) ( $field['label'] ?? $key )
				);
			}
		}

		return $errors;
	}

	/**
	 * Save submitted settings for a provider.
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $raw          Raw submitted values (unslashed).
	 * @return array<string, mixed>|WP_Error `array( 'errors' => array, 'saved' => array )`.
	 */
	public function save( string $provider_key, array $raw ): array|WP_Error {
		$provider = $this->registry->get( $provider_key );

		if ( ! $provider instanceof AbstractProvider ) {
			return new WP_Error(
				'rbw_unknown_provider',
				__( 'Unknown provider.', 'ratebridge-woo' )
			);
		}

		$old = $provider->get_settings();
		$new = array();

		foreach ( $provider->get_settings_fields() as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$old_value = isset( $old[ $key ] ) && is_scalar( $old[ $key ] ) ? (string) $old[ $key ] : '';

			// Unchecked checkboxes and absent fields are not submitted at all.
			$raw_value = array_key_exists( $key, $raw ) ? $raw[ $key ] : null;

			$new[ $key ] = self::sanitize_field( $field, $raw_value, $old_value );
		}

		$errors = self::validate( $provider, $new );

		if ( ! empty( $errors ) ) {
			// Keep previously stored values; nothing is written on validation failure.
			return new WP_Error(
				'rbw_validation_failed',
				implode( ' ', $errors ),
				array( 'errors' => $errors )
			);
		}

		$provider->save_settings( $new );

		/*
		 * A changed key/token must un-block the provider immediately: the sticky
		 * auth_failed state is tied to the credential fingerprint.
		 */
		if ( RequestBudget::fingerprint( $old ) !== RequestBudget::fingerprint( $new ) ) {
			RequestBudget::clear_auth_failed( $provider_key );
		}

		return array(
			'errors' => array(),
			'saved'  => $new,
		);
	}

	/**
	 * Build the display model for one provider card.
	 *
	 * Secrets are replaced with a mask; raw values never leave this method.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return array<string, mixed>
	 */
	public function card_model( ProviderInterface $provider ): array {
		$stored = $provider->get_settings();
		$fields = array();
		$state  = RequestBudget::get( $provider->get_key() );

		foreach ( $provider->get_settings_fields() as $field ) {
			$key   = (string) ( $field['key'] ?? '' );
			$type  = (string) ( $field['type'] ?? 'text' );
			$value = $stored[ $key ] ?? ( $field['default'] ?? '' );

			$display = '';

			if ( 'password' === $type ) {
				$display = self::mask( is_string( $value ) ? $value : '' );
			} elseif ( 'checkbox' === $type ) {
				$display = ! empty( $value ) ? '1' : '0';
			} else {
				$display = is_scalar( $value ) ? (string) $value : '';
			}

			$fields[] = array(
				'key'         => $key,
				'label'       => (string) ( $field['label'] ?? $key ),
				'type'        => $type,
				'required'    => ! empty( $field['required'] ),
				'description' => (string) ( $field['description'] ?? '' ),
				'hint'        => (string) ( $field['hint'] ?? '' ),
				'options'     => isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array(),
				'min'         => isset( $field['min'] ) ? (int) $field['min'] : null,
				'step'        => isset( $field['step'] ) ? (int) $field['step'] : null,
				'value'       => $display,
				'has_value'   => 'password' === $type
					? self::has_secret( is_string( $value ) ? $value : '' )
					: '' !== $display,
			);
		}

		return array(
			'key'                  => $provider->get_key(),
			'label'                => $provider->get_label(),
			'fields'               => $fields,
			'known_symbols_count'  => count( $provider->get_known_symbols() ),
			'test_symbols'         => $this->test_symbols( $provider ),
			'budget'               => $this->budget_model( $provider ),
			'uses_network'         => $provider->uses_network(),
			'configured'           => $this->is_configured( $provider ),
		);
	}

	/**
	 * Budget display block for one provider.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return array<string, mixed>
	 */
	public function budget_model( ProviderInterface $provider ): array {
		$key            = $provider->get_key();
		$state          = RequestBudget::get( $key );
		$monthly_limit  = method_exists( $provider, 'get_monthly_limit' ) ? $provider->get_monthly_limit() : 0;
		$min_interval   = method_exists( $provider, 'get_min_interval_minutes' ) ? $provider->get_min_interval_minutes() : 0;
		$effective      = method_exists( $provider, 'get_effective_min_interval_minutes' ) ? $provider->get_effective_min_interval_minutes() : $min_interval;
		$used           = RequestBudget::used_this_period( $key );
		$daily_limit    = method_exists( $provider, 'get_daily_limit' ) ? $provider->get_daily_limit() : 0;
		$used_today     = RequestBudget::used_today( $key );
		$next_allowed   = RequestBudget::next_allowed_at( $key, $min_interval, $monthly_limit );

		// Auth state: explicitly failing credentials win over any quota message.
		$auth_failed = RequestBudget::is_auth_failed( $key, $provider->get_settings() );

		$status = 'ok';

		if ( $auth_failed ) {
			$status = 'needs_key';
		} elseif ( $daily_limit > 0 && $used_today >= $daily_limit ) {
			$status = 'exhausted';
		} elseif ( $monthly_limit > 0 && $used >= $monthly_limit ) {
			$status = 'exhausted';
		} elseif ( '' !== (string) $state['last_error'] ) {
			$status = 'error';
		}

		return array(
			'used'             => $used,
			'limit'            => $monthly_limit,
			'daily_limit'      => $daily_limit,
			'used_today'       => $used_today,
			'day'              => RequestBudget::current_day(),
			'auth_failed'      => $auth_failed,
			'auth_status'      => (int) $state['auth_status'],
			'auth_message'     => (string) $state['auth_message'],
			'status'           => $status,
			'source_updated'   => (int) $state['source_updated'],
			'source_updated_fmt' => (int) $state['source_updated'] > 0
				? wp_date( 'Y-m-d H:i', (int) $state['source_updated'] )
				: '',
			'period'           => RequestBudget::current_period(),
			'last_request'     => (int) $state['last_request'],
			'last_request_fmt' => (int) $state['last_request'] > 0
				? wp_date( 'Y-m-d H:i', (int) $state['last_request'] )
				: '',
			'last_status'      => (int) $state['last_status'],
			'next_allowed'     => $next_allowed,
			'next_allowed_fmt' => $next_allowed > 0 ? wp_date( 'Y-m-d H:i', $next_allowed ) : '',
			'min_interval'     => $min_interval,
			'effective'        => $effective,
			'failure_backoff'  => RequestBudget::FAILURE_BACKOFF_MINUTES,
			'last_error'       => (string) $state['last_error'],
		);
	}

	/**
	 * Mark the credentials as good after a successful manual test.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return void
	 */
	public function note_test_success( ProviderInterface $provider ): void {
		RequestBudget::clear_auth_failed( $provider->get_key() );
	}

	/**
	 * Whether the provider has every required setting filled in.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return bool
	 */
	public function is_configured( ProviderInterface $provider ): bool {
		$stored = $provider->get_settings();

		foreach ( $provider->get_settings_fields() as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}

			$key   = (string) ( $field['key'] ?? '' );
			$value = $stored[ $key ] ?? '';

			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Symbols used by the "test connection" button.
	 *
	 * Providers expose a small safe set; otherwise the first two known symbols
	 * are used.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return string[]
	 */
	public function test_symbols( ProviderInterface $provider ): array {
		if ( method_exists( $provider, 'get_test_symbols' ) ) {
			$symbols = (array) $provider->get_test_symbols();

			if ( ! empty( $symbols ) ) {
				return array_values( array_map( 'strval', $symbols ) );
			}
		}

		return array_slice( array_keys( $provider->get_known_symbols() ), 0, 2 );
	}

	/**
	 * Perform a single test fetch and shape the result for the UI.
	 *
	 * Consumes budget exactly like a normal update: the request is refused when
	 * the quota or interval does not allow it, and the cache is bypassed only
	 * when the budget permits a real call.
	 *
	 * @param string $provider_key Provider key.
	 * @return array<string, mixed>|WP_Error
	 */
	public function test_connection( string $provider_key ): array|WP_Error {
		$provider = $this->registry->get( $provider_key );

		if ( ! $provider instanceof ProviderInterface ) {
			return new WP_Error( 'rbw_unknown_provider', __( 'Unknown provider.', 'ratebridge-woo' ) );
		}

		if ( ! $provider instanceof AbstractProvider || ! $provider->uses_network() ) {
			return new WP_Error(
				'rbw_no_network',
				__( 'This source does not use a network connection, so there is nothing to test.', 'ratebridge-woo' )
			);
		}

		if ( ! $this->is_configured( $provider ) ) {
			return new WP_Error(
				'rbw_not_configured',
				__( 'Fill in the required settings and save before testing the connection.', 'ratebridge-woo' )
			);
		}

		$symbols = $this->test_symbols( $provider );

		if ( empty( $symbols ) ) {
			return new WP_Error(
				'rbw_no_test_symbols',
				__( 'No test symbol is available for this source.', 'ratebridge-woo' )
			);
		}

		$started = microtime( true );
		$rates   = $provider->fetch_rates( $symbols );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		$state = RequestBudget::get( $provider_key );

		if ( is_wp_error( $rates ) ) {
			return new WP_Error(
				$rates->get_error_code(),
				$provider->scrub_secrets( $rates->get_error_message() ),
				array(
					'http_status' => (int) $state['last_status'],
					'tested'      => $symbols,
					'elapsed_ms'  => $elapsed,
				)
			);
		}

		// A successful manual test proves the credentials work.
		RequestBudget::clear_auth_failed( $provider_key );

		// Only the first 3 pairs are shown; values are never secrets.
		$sample = array();

		foreach ( array_slice( $rates, 0, 3, true ) as $symbol => $rate ) {
			$sample[ (string) $symbol ] = (float) $rate;
		}

		return array(
			'ok'          => true,
			'http_status' => (int) $state['last_status'],
			'tested'      => $symbols,
			'parsed'      => count( $rates ),
			'sample'      => $sample,
			'elapsed_ms'  => $elapsed,
			'budget'      => $this->budget_model( $provider ),
		);
	}
}
