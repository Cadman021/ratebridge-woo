<?php
/**
 * AJAX controller for the currency admin screen.
 *
 * @package RateBridge
 */

namespace RateBridge\Admin;

use RateBridge\Core\Logger;
use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Pricing\RateUpdater;
use RateBridge\Providers\AbstractProvider;
use RateBridge\Providers\ProviderInterface;
use RateBridge\Providers\ProviderRegistry;
use RateBridge\Providers\RequestBudget;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all admin-ajax actions (nonce + manage_woocommerce capability).
 */
final class AjaxController {

	/**
	 * Nonce action shared by all endpoints.
	 */
	public const NONCE_ACTION = 'rbw_admin';

	/**
	 * Nonce request field name.
	 */
	public const NONCE_NAME = 'nonce';

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$actions = array(
			'rbw_toggle_currency',
			'rbw_save_currency',
			'rbw_delete_currency',
			'rbw_update_now',
			'rbw_update_all',
			'rbw_save_settings',
			'rbw_clear_log',
			'rbw_get_log',
			'rbw_get_currency',
			'rbw_save_provider_settings',
			'rbw_test_provider',
			'rbw_reset_budget',
			'rbw_add_preset',
			'rbw_get_symbols',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'handle' ) );
		}
	}

	/**
	 * Single dispatcher for every endpoint.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_NAME, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'ratebridge-woo' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ratebridge-woo' ) ), 403 );
		}

		$action = isset( $_REQUEST['rbw_action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['rbw_action'] ) ) : '';

		switch ( $action ) {
			case 'toggle':
				$this->toggle();
				break;
			case 'save':
				$this->save();
				break;
			case 'delete':
				$this->delete();
				break;
			case 'update_now':
				$this->update_now();
				break;
			case 'update_all':
				$this->update_all();
				break;
			case 'get_currency':
				$this->get_currency();
				break;
			case 'save_settings':
				$this->save_settings();
				break;
			case 'get_log':
				$this->get_log();
				break;
			case 'clear_log':
				$this->clear_log();
				break;
			case 'save_provider_settings':
				$this->save_provider_settings();
				break;
			case 'test_provider':
				$this->test_provider();
				break;
			case 'reset_budget':
				$this->reset_budget();
				break;
			case 'add_preset':
				$this->add_preset();
				break;
			case 'get_symbols':
				$this->get_symbols();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'ratebridge-woo' ) ), 400 );
		}
	}

	/**
	 * Enable/disable a currency.
	 *
	 * @return void
	 */
	private function toggle(): void {
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['enabled'] ) );

		$repo     = new CurrencyRepository();
		$currency = $repo->get( $id );

		if ( null === $currency ) {
			wp_send_json_error( array( 'message' => __( 'Currency not found.', 'ratebridge-woo' ) ), 404 );
		}

		if ( ! $repo->set_enabled( $id, $enabled ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the change.', 'ratebridge-woo' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message'    => $enabled ? __( 'Currency enabled.', 'ratebridge-woo' ) : __( 'Currency disabled.', 'ratebridge-woo' ),
				'id'         => $id,
				'is_enabled' => $enabled,
			)
		);
	}

	/**
	 * Create or update a currency.
	 *
	 * @return void
	 */
	private function save(): void {
		$repo = new CurrencyRepository();
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$code = isset( $_POST['code'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$code = preg_replace( '/[^A-Z0-9_\-]/', '', $code ) ?? '';

		if ( '' === $code || '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Code and name are required.', 'ratebridge-woo' ) ), 400 );
		}

		if ( strlen( $code ) > 32 ) {
			wp_send_json_error( array( 'message' => __( 'The code must be 32 characters or fewer.', 'ratebridge-woo' ) ), 400 );
		}

		if ( $repo->code_exists( $code, $id ) ) {
			wp_send_json_error( array( 'message' => __( 'This code is already used by another currency.', 'ratebridge-woo' ) ), 400 );
		}

		$provider        = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : 'manual';
		$registry        = ProviderRegistry::instance();
		$provider_object = $registry->get( $provider );

		if ( null === $provider_object ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'ratebridge-woo' ) ), 400 );
		}

		$rate_raw        = isset( $_POST['rate'] ) ? wp_unslash( (string) $_POST['rate'] ) : '';
		$rate_raw        = str_replace( array( ',', ' ' ), '', $rate_raw );
		$rate            = '' === $rate_raw ? 0.0 : (float) $rate_raw;
		$provider_symbol = isset( $_POST['provider_symbol'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['provider_symbol'] ) ) : '';

		$data = array(
			'code'                    => $code,
			'name'                    => $name,
			'symbol'                  => isset( $_POST['symbol'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['symbol'] ) ) : '',
			'provider'                => $provider,
			'provider_symbol'         => '' !== $provider_symbol ? $provider_symbol : $code,
			'rate'                    => $rate,
			'is_enabled'              => isset( $_POST['is_enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['is_enabled'] ) ) ? 1 : 0,
			'update_interval_minutes' => isset( $_POST['update_interval_minutes'] ) ? absint( $_POST['update_interval_minutes'] ) : Settings::get( 'default_update_interval', 60 ),
			'sort_order'              => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
		);

		if ( $id > 0 ) {
			$existing = $repo->get( $id );

			if ( null === $existing ) {
				wp_send_json_error( array( 'message' => __( 'Currency not found.', 'ratebridge-woo' ) ), 404 );
			}

			// Keep the current rate if the field was left empty.
			if ( 0.0 === $rate ) {
				unset( $data['rate'] );
			}

			$saved = $repo->update( $id, $data );
		} else {
			$now                = current_time( 'mysql', true );
			$data['created_at'] = $now;
			$data['updated_at'] = $now;
			$id                 = $repo->create( $data );
			$saved              = $id > 0;
		}

		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the currency.', 'ratebridge-woo' ) ), 500 );
		}

		$currency = $repo->get( $id );

		wp_send_json_success(
			array(
				'message'  => __( 'Currency saved.', 'ratebridge-woo' ),
				'currency' => $currency instanceof Currency ? $this->currency_payload( $currency ) : array(),
			)
		);
	}

	/**
	 * Delete a currency.
	 *
	 * @return void
	 */
	private function delete(): void {
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$repo = new CurrencyRepository();

		if ( null === $repo->get( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Currency not found.', 'ratebridge-woo' ) ), 404 );
		}

		if ( ! $repo->delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete the currency.', 'ratebridge-woo' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Currency deleted.', 'ratebridge-woo' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * Update a single currency now.
	 *
	 * @return void
	 */
	private function update_now(): void {
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$summary = ( new RateUpdater() )->update_one( $id, 'manual' );
		$repo    = new CurrencyRepository();
		$item    = $repo->get( $id );

		$payload = array(
			'message'  => (string) ( $summary['message'] ?? '' ),
			'updated'  => (int) ( $summary['updated'] ?? 0 ),
			'failed'   => (int) ( $summary['failed'] ?? 0 ),
			'currency' => $item instanceof Currency ? $this->currency_payload( $item ) : array(),
		);

		if ( ! empty( $payload['failed'] ) ) {
			wp_send_json_error( $payload, 200 );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Update all enabled currencies now.
	 *
	 * @return void
	 */
	private function update_all(): void {
		$summary = ( new RateUpdater() )->update_all_enabled( 'manual' );
		$repo    = new CurrencyRepository();
		$list    = array_map(
			fn ( Currency $currency ): array => $this->currency_payload( $currency ),
			$repo->get_all()
		);

		$payload = array(
			'message'    => (string) ( $summary['message'] ?? '' ),
			'updated'    => (int) ( $summary['updated'] ?? 0 ),
			'failed'     => (int) ( $summary['failed'] ?? 0 ),
			'currencies' => $list,
		);

		if ( ! empty( $payload['failed'] ) ) {
			wp_send_json_error( $payload, 200 );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Return a single currency (for the edit modal).
	 *
	 * @return void
	 */
	private function get_currency(): void {
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$currency = ( new CurrencyRepository() )->get( $id );

		if ( null === $currency ) {
			wp_send_json_error( array( 'message' => __( 'Currency not found.', 'ratebridge-woo' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'currency' => $this->currency_payload( $currency ),
			)
		);
	}

	/**
	 * Save the general settings tab.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		$values = array(
			'store_currency_unit'      => isset( $_POST['store_currency_unit'] ) ? sanitize_key( wp_unslash( (string) $_POST['store_currency_unit'] ) ) : '',
			'rounding_mode'            => isset( $_POST['rounding_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['rounding_mode'] ) ) : '',
			'rounding_step'            => isset( $_POST['rounding_step'] ) ? absint( $_POST['rounding_step'] ) : 1,
			'default_update_interval'  => isset( $_POST['default_update_interval'] ) ? absint( $_POST['default_update_interval'] ) : 60,
			'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['delete_data_on_uninstall'] ) ),
		);

		$saved = Settings::update( $values );

		wp_send_json_success(
			array(
				'message'  => __( 'Settings saved.', 'ratebridge-woo' ),
				'settings' => $saved,
			)
		);
	}

	/**
	 * Save a provider's settings (Price sources tab).
	 *
	 * @return void
	 */
	private function save_provider_settings(): void {
		$provider_key = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';

		if ( '' === $provider_key ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'ratebridge-woo' ) ), 400 );
		}

		// Collect the raw field values for this provider only.
		$raw = array();

		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field type below.
			$submitted = wp_unslash( $_POST['fields'] );

			foreach ( (array) $submitted as $field_key => $field_value ) {
				$raw[ sanitize_key( (string) $field_key ) ] = is_scalar( $field_value ) ? (string) $field_value : '';
			}
		}

		$service = new ProviderSettings();
		$result  = $service->save( $provider_key, $raw );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'errors'  => (array) ( $result->get_error_data()['errors'] ?? array() ),
				),
				400
			);
		}

		$provider = ProviderRegistry::instance()->get( $provider_key );

		wp_send_json_success(
			array(
				'message' => __( 'Provider settings saved.', 'ratebridge-woo' ),
				'card'    => $provider instanceof ProviderInterface ? $service->card_model( $provider ) : array(),
			)
		);
	}

	/**
	 * Perform one test fetch against a provider.
	 *
	 * @return void
	 */
	private function test_provider(): void {
		$provider_key = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';
		$service      = new ProviderSettings();
		$result       = $service->test_connection( $provider_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'details' => (array) $result->get_error_data(),
				)
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Reset a provider's request budget counters.
	 *
	 * @return void
	 */
	private function reset_budget(): void {
		$provider_key = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';
		$provider     = ProviderRegistry::instance()->get( $provider_key );

		if ( null === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'ratebridge-woo' ) ), 400 );
		}

		// Clear both the quota state and any cached payloads.
		RequestBudget::reset( $provider_key );

		if ( $provider instanceof AbstractProvider ) {
			$provider->flush_cache();
		}

		wp_send_json_success(
			array(
				'message' => __( 'Request counters reset.', 'ratebridge-woo' ),
				'card'    => ( new ProviderSettings() )->card_model( $provider ),
			)
		);
	}

	/**
	 * Add a currency from a provider preset in one click.
	 *
	 * @return void
	 */
	private function add_preset(): void {
		$provider_key = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';
		$symbol       = isset( $_POST['symbol'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['symbol'] ) ) : '';
		$code         = isset( $_POST['code'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) ) : '';
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';

		$registry = ProviderRegistry::instance();
		$provider = $registry->get( $provider_key );

		if ( null === $provider || '' === $symbol ) {
			wp_send_json_error( array( 'message' => __( 'Invalid preset.', 'ratebridge-woo' ) ), 400 );
		}

		$known = $provider->get_known_symbols();

		if ( ! array_key_exists( $symbol, $known ) ) {
			wp_send_json_error( array( 'message' => __( 'This symbol is not offered by the selected source.', 'ratebridge-woo' ) ), 400 );
		}

		$repo = new CurrencyRepository();
		$code = preg_replace( '/[^A-Z0-9_\-]/', '', $code ) ?? '';

		if ( '' === $code || '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Code and name are required.', 'ratebridge-woo' ) ), 400 );
		}

		if ( $repo->code_exists( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'This code is already used by another currency.', 'ratebridge-woo' ) ), 400 );
		}

		$now = current_time( 'mysql', true );

		$id = $repo->create(
			array(
				'code'                    => $code,
				'name'                    => $name,
				'symbol'                  => '',
				'provider'                => $provider_key,
				'provider_symbol'         => $symbol,
				'rate'                    => 0,
				'is_enabled'              => 0,
				'update_interval_minutes' => (int) Settings::get( 'default_update_interval', 60 ),
				'sort_order'              => 0,
				'created_at'              => $now,
				'updated_at'              => $now,
			)
		);

		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the currency.', 'ratebridge-woo' ) ), 500 );
		}

		$currency = $repo->get( $id );

		wp_send_json_success(
			array(
				'message'  => __( 'Currency added from presets. Enable it to start updating.', 'ratebridge-woo' ),
				'currency' => $currency instanceof Currency ? $this->currency_payload( $currency ) : array(),
			)
		);
	}

	/**
	 * Return the known symbols of one provider (for the dialog).
	 *
	 * @return void
	 */
	private function get_symbols(): void {
		$provider_key = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';
		$registry     = ProviderRegistry::instance();

		wp_send_json_success(
			array(
				'symbols' => $registry->known_symbols( $provider_key ),
			)
		);
	}

	/**
	 * Return the log entries as HTML-free data.
	 *
	 * @return void
	 */
	private function get_log(): void {
		$entries = array();

		foreach ( Logger::all() as $entry ) {
			$entries[] = array(
				'time'       => isset( $entry['time'] ) ? wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) : '',
				'status'     => isset( $entry['status'] ) ? (string) $entry['status'] : 'error',
				'message'    => isset( $entry['message'] ) ? (string) $entry['message'] : '',
				'trigger'    => isset( $entry['trigger'] ) ? (string) $entry['trigger'] : 'cron',
				'currencies' => isset( $entry['currencies'] ) && is_array( $entry['currencies'] ) ? implode( ', ', $entry['currencies'] ) : '',
			);
		}

		wp_send_json_success( array( 'entries' => $entries ) );
	}

	/**
	 * Clear the log.
	 *
	 * @return void
	 */
	private function clear_log(): void {
		Logger::clear();

		wp_send_json_success( array( 'message' => __( 'Log cleared.', 'ratebridge-woo' ) ) );
	}

	/**
	 * Serialisable currency payload for JS.
	 *
	 * @param Currency $currency Currency.
	 * @return array<string, mixed>
	 */
	private function currency_payload( Currency $currency ): array {
		return array(
			'id'                      => $currency->id,
			'code'                    => $currency->code,
			'name'                    => $currency->name,
			'symbol'                  => $currency->symbol,
			'provider'                => $currency->provider,
			'provider_symbol'         => $currency->provider_symbol,
			'rate'                    => $currency->rate,
			'rate_formatted'          => SettingsPage::format_rate( $currency ),
			'is_enabled'              => $currency->is_enabled,
			'update_interval_minutes' => $currency->update_interval_minutes,
			'last_updated'            => SettingsPage::format_datetime( $currency->last_updated ),
			'last_error'              => (string) $currency->last_error,
			'sort_order'              => $currency->sort_order,
		);
	}
}
