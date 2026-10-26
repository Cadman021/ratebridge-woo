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
use RateBridge\Core\UnitMigration;
use RateBridge\Pricing\PriceCalculator;
use RateBridge\Pricing\RateUpdater;
use RateBridge\Pricing\Repricer;
use RateBridge\Product\ProductData;
use RateBridge\Providers\AbstractProvider;
use RateBridge\Providers\ProviderInterface;
use RateBridge\Providers\ProviderRegistry;
use RateBridge\Providers\RequestBudget;

defined('ABSPATH') || exit;

/**
 * Handles all admin-ajax actions (nonce + manage_woocommerce capability).
 */
final class AjaxController
{

	/**
	 * Nonce action shared by all endpoints.
	 */
	public const NONCE_ACTION = 'rbw_admin';

	/**
	 * Nonce request field name.
	 */
	public const NONCE_NAME = 'nonce';

	/**
	 * Product editor live-preview action.
	 *
	 * The JS sends `action=rbw_preview_price`; it is registered as
	 * `wp_ajax_rbw_preview_price` (see tests/ajax-actions.php).
	 */
	public const PREVIEW_ACTION = 'preview_price';

	/**
	 * The one and only list of dispatcher slugs.
	 *
	 * admin.js calls `request( 'save' )`, which posts `action=rbw_save`. Every
	 * slug here is registered as `wp_ajax_rbw_{slug}`, so the two sides can
	 * never drift apart again (see tests/ajax-actions.php).
	 *
	 * @return string[]
	 */
	public static function actions(): array
	{
		return array(
			'toggle',
			'save',
			'delete',
			'update_now',
			'update_all',
			'get_currency',
			'save_settings',
			'get_log',
			'clear_log',
			'save_provider_settings',
			'test_provider',
			'reset_budget',
			'add_preset',
			'get_symbols',
			'preview_price',
			'migrate_unit',
			'dismiss_stale_notice',
		);
	}

	/**
	 * Legacy alias suffix => dispatcher slug.
	 *
	 * Phase 1/2 registered `rbw_save_currency`, `rbw_delete_currency` and
	 * friends. They never matched what the JS sends, but keeping them costs
	 * nothing and avoids breaking any cached admin page.
	 *
	 * @return array<string, string>
	 */
	public static function legacy_aliases(): array
	{
		return array(
			'rbw_toggle_currency'            => 'toggle',
			'rbw_save_currency'              => 'save',
			'rbw_delete_currency'            => 'delete',
			'rbw_update_now'                 => 'update_now',
			'rbw_update_all'                 => 'update_all',
			'rbw_save_settings'              => 'save_settings',
			'rbw_clear_log'                  => 'clear_log',
			'rbw_get_log'                    => 'get_log',
			'rbw_get_currency'               => 'get_currency',
			'rbw_save_provider_settings'     => 'save_provider_settings',
			'rbw_test_provider'              => 'test_provider',
			'rbw_reset_budget'               => 'reset_budget',
			'rbw_add_preset'                 => 'add_preset',
			'rbw_get_symbols'                => 'get_symbols',
			'rbw_preview_price'              => 'preview_price',
			'rbw_migrate_unit'               => 'migrate_unit',
			'rbw_dismiss_stale_notice'       => 'dismiss_stale_notice',
		);
	}

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public function register(): void
	{
		foreach (self::actions() as $slug) {
			add_action('wp_ajax_rbw_' . $slug, array($this, 'handle'));
		}

		// Harmless aliases for the old (never-matching) hook names.
		foreach (array_keys(self::legacy_aliases()) as $alias) {
			if (0 !== strpos($alias, 'rbw_')) {
				continue;
			}

			add_action('wp_ajax_' . $alias, array($this, 'handle'));
		}
	}

	/**
	 * Single dispatcher for every endpoint.
	 *
	 * @return void
	 */
	public function handle(): void
	{
		if (! check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME, false)) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'ratebridge-woo')), 403);
		}

		if (! current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => __('You are not allowed to do this.', 'ratebridge-woo')), 403);
		}

		$action = $this->resolve_action();

		if ('' === $action || ! in_array($action, self::actions(), true)) {
			wp_send_json_error(array('message' => __('Unknown action.', 'ratebridge-woo')), 400);
		}

		switch ($action) {
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
			case 'preview_price':
				$this->preview_price();
				break;
			case 'migrate_unit':
				$this->migrate_unit();
				break;
			case 'dismiss_stale_notice':
				$this->dismiss_stale_notice();
				break;
		}
	}

	/**
	 * Work out which handler to run.
	 *
	 * Primary source is the `rbw_action` field sent by admin.js. If it is
	 * missing (legacy alias or a hand-crafted request) we fall back to the
	 * `action` field, stripping the leading `rbw_` and any legacy suffix.
	 *
	 * @return string Dispatcher slug, or '' when it cannot be determined.
	 */
	private function resolve_action(): string
	{
		$candidate = '';

		if (isset($_REQUEST['rbw_action'])) {
			$candidate = sanitize_key(wp_unslash((string) $_REQUEST['rbw_action']));
		} elseif (isset($_REQUEST['action'])) {
			$candidate = sanitize_key(wp_unslash((string) $_REQUEST['action']));
		}

		// Normalise `rbw_save` -> `save`.
		if (str_starts_with($candidate, 'rbw_')) {
			$candidate = substr($candidate, 4);
		}

		// Legacy names such as `save_currency` -> `save`, but only when the
		// stripped form is a real action (`get_currency` must stay intact).
		if (str_ends_with($candidate, '_currency')) {
			$stripped = substr($candidate, 0, -9);

			if (in_array($stripped, self::actions(), true)) {
				$candidate = $stripped;
			}
		}

		return $candidate;
	}

	/**
	 * Enable/disable a currency.
	 *
	 * @return void
	 */
	private function toggle(): void
	{
		$id      = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$enabled = isset($_POST['enabled']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['enabled']));

		$repo     = new CurrencyRepository();
		$currency = $repo->get($id);

		if (null === $currency) {
			wp_send_json_error(array('message' => __('Currency not found.', 'ratebridge-woo')), 404);
		}

		if (! $repo->set_enabled($id, $enabled)) {
			wp_send_json_error(array('message' => __('Could not save the change.', 'ratebridge-woo')), 500);
		}

		wp_send_json_success(
			array(
				'message'    => $enabled ? __('Currency enabled.', 'ratebridge-woo') : __('Currency disabled.', 'ratebridge-woo'),
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
	private function save(): void
	{
		$repo = new CurrencyRepository();
		$id   = isset($_POST['id']) ? absint($_POST['id']) : 0;

		$code = isset($_POST['code']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['code']))) : '';
		$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
		$code = preg_replace('/[^A-Z0-9_\-]/', '', $code) ?? '';

		if ('' === $code || '' === $name) {
			wp_send_json_error(array('message' => __('Code and name are required.', 'ratebridge-woo')), 400);
		}

		if (strlen($code) > 32) {
			wp_send_json_error(array('message' => __('The code must be 32 characters or fewer.', 'ratebridge-woo')), 400);
		}

		if ($repo->code_exists($code, $id)) {
			wp_send_json_error(array('message' => __('This code is already used by another currency.', 'ratebridge-woo')), 400);
		}

		$provider        = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : 'manual';
		$registry        = ProviderRegistry::instance();
		$provider_object = $registry->get($provider);

		if (null === $provider_object) {
			wp_send_json_error(array('message' => __('Unknown provider.', 'ratebridge-woo')), 400);
		}

		$rate_raw        = isset($_POST['rate']) ? wp_unslash((string) $_POST['rate']) : '';
		$rate_raw        = str_replace(array(',', ' '), '', $rate_raw);
		$rate            = '' === $rate_raw ? 0.0 : (float) $rate_raw;
		$provider_symbol = isset($_POST['provider_symbol']) ? sanitize_text_field(wp_unslash((string) $_POST['provider_symbol'])) : '';

		// Normalise through the provider so a free-text entry lines up with its
		// catalogue keys (e.g. AlanChand `usd` -> `currency:usd`).
		$provider_symbol = $provider_object->normalize_symbol($provider_symbol);

		// Providers with a real catalogue must not silently fall back to the
		// currency code: an empty symbol would never resolve to a rate.
		$known_symbols = $provider_object->get_known_symbols();

		if ('' === $provider_symbol && ! empty($known_symbols)) {
			wp_send_json_error(
				array('message' => __('Select or enter a provider symbol.', 'ratebridge-woo')),
				400
			);
		}

		// Pricing settings (percent 0–1000, fixed amounts never negative).
		$pricing = $this->read_pricing_input();

		if ( is_wp_error( $pricing ) ) {
			wp_send_json_error( array( 'message' => $pricing->get_error_message() ), 400 );
		}

		$data = array(
			'code'                    => $code,
			'name'                    => $name,
			'symbol'                  => isset($_POST['symbol']) ? sanitize_text_field(wp_unslash((string) $_POST['symbol'])) : '',
			'provider'                => $provider,
			'provider_symbol'         => '' !== $provider_symbol ? $provider_symbol : $code,
			'rate'                    => $rate,
			'is_enabled'              => isset($_POST['is_enabled']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['is_enabled'])) ? 1 : 0,
			'update_interval_minutes' => isset($_POST['update_interval_minutes']) ? absint($_POST['update_interval_minutes']) : Settings::get('default_update_interval', 60),
			'sort_order'              => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0,
			'profit_type'             => $pricing['profit_type'],
			'profit_value'            => $pricing['profit_value'],
			'commission_type'         => $pricing['commission_type'],
			'commission_value'        => $pricing['commission_value'],
			'extra_amount'            => $pricing['extra_amount'],
		);

		$before = $id > 0 ? $repo->get($id) : null;

		if ($id > 0) {
			$existing = $before;

			if (null === $existing) {
				wp_send_json_error(array('message' => __('Currency not found.', 'ratebridge-woo')), 404);
			}

			// Keep the current rate if the field was left empty.
			if (0.0 === $rate) {
				unset($data['rate']);
			}

			$saved = $repo->update($id, $data);
		} else {
			$now                = current_time('mysql', true);
			$data['created_at'] = $now;
			$data['updated_at'] = $now;
			$id                 = $repo->create($data);
			$saved              = $id > 0;
		}

		if (! $saved) {
			wp_send_json_error(array('message' => __('Could not save the currency.', 'ratebridge-woo')), 500);
		}

		$currency = $repo->get($id);

		/*
		 * A rate typed by the admin must reprice the products too, exactly like a
		 * fetched rate. The action only fires when the stored value really
		 * changed (the repo keeps the old rate when the field is left empty).
		 */
		if ($currency instanceof Currency && null !== $before) {
			$changed_rate = abs($currency->rate - $before->rate) > RateUpdater::RATE_EPSILON;

			if ($changed_rate) {
				/** This action is documented in includes/Pricing/RateUpdater.php */
				do_action('rbw_rate_updated', $currency->code, (float) $before->rate, (float) $currency->rate, (int) $currency->id);
			}
		}

		wp_send_json_success(
			array(
				'message'  => __('Currency saved.', 'ratebridge-woo'),
				'currency' => $currency instanceof Currency ? $this->currency_payload($currency) : array(),
			)
		);
	}

	/**
	 * Delete a currency.
	 *
	 * @return void
	 */
	private function delete(): void
	{
		$id   = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$repo = new CurrencyRepository();

		if (null === $repo->get($id)) {
			wp_send_json_error(array('message' => __('Currency not found.', 'ratebridge-woo')), 404);
		}

		if (! $repo->delete($id)) {
			wp_send_json_error(array('message' => __('Could not delete the currency.', 'ratebridge-woo')), 500);
		}

		wp_send_json_success(
			array(
				'message' => __('Currency deleted.', 'ratebridge-woo'),
				'id'      => $id,
			)
		);
	}

	/**
	 * Update a single currency now.
	 *
	 * @return void
	 */
	private function update_now(): void
	{
		$id      = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$summary = (new RateUpdater())->update_one($id, 'manual');
		$repo    = new CurrencyRepository();
		$item    = $repo->get($id);

		$payload = array(
			'message'  => (string) ($summary['message'] ?? ''),
			'updated'  => (int) ($summary['updated'] ?? 0),
			'failed'   => (int) ($summary['failed'] ?? 0),
			'currency' => $item instanceof Currency ? $this->currency_payload($item) : array(),
		);

		if (! empty($payload['failed'])) {
			wp_send_json_error($payload, 200);
		}

		wp_send_json_success($payload);
	}

	/**
	 * Update all enabled currencies now.
	 *
	 * @return void
	 */
	private function update_all(): void
	{
		$summary = (new RateUpdater())->update_all_enabled('manual');
		$repo    = new CurrencyRepository();
		$list    = array_map(
			fn(Currency $currency): array => $this->currency_payload($currency),
			$repo->get_all()
		);

		$payload = array(
			'message'    => (string) ($summary['message'] ?? ''),
			'updated'    => (int) ($summary['updated'] ?? 0),
			'failed'     => (int) ($summary['failed'] ?? 0),
			'currencies' => $list,
		);

		if (! empty($payload['failed'])) {
			wp_send_json_error($payload, 200);
		}

		wp_send_json_success($payload);
	}

	/**
	 * Return a single currency (for the edit modal).
	 *
	 * @return void
	 */
	private function get_currency(): void
	{
		$id       = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$currency = (new CurrencyRepository())->get($id);

		if (null === $currency) {
			wp_send_json_error(array('message' => __('Currency not found.', 'ratebridge-woo')), 404);
		}

		wp_send_json_success(
			array(
				'currency' => $this->currency_payload($currency),
			)
		);
	}

	/**
	 * Save the general settings tab.
	 *
	 * @return void
	 */
	private function save_settings(): void
	{
		$old_unit = Settings::store_unit();

		$values = array(
			'store_currency_unit'      => isset($_POST['store_currency_unit']) ? sanitize_key(wp_unslash((string) $_POST['store_currency_unit'])) : '',
			'rounding_mode'            => isset($_POST['rounding_mode']) ? sanitize_key(wp_unslash((string) $_POST['rounding_mode'])) : '',
			'rounding_step'            => isset($_POST['rounding_step']) ? absint($_POST['rounding_step']) : 1,
			'default_update_interval'  => isset($_POST['default_update_interval']) ? absint($_POST['default_update_interval']) : 60,
			'delete_data_on_uninstall' => isset($_POST['delete_data_on_uninstall']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['delete_data_on_uninstall'])),
			'max_rate_age_hours'       => isset($_POST['max_rate_age_hours']) ? absint($_POST['max_rate_age_hours']) : 48,
			'show_rate_in_orders'      => isset($_POST['show_rate_in_orders']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['show_rate_in_orders'])),
			'product_block_enabled'    => isset($_POST['product_block_enabled']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['product_block_enabled'])),
			'product_block_position'   => isset($_POST['product_block_position']) ? sanitize_key(wp_unslash((string) $_POST['product_block_position'])) : 'after_price',
			'product_block_template'   => isset($_POST['product_block_template']) ? wp_unslash((string) $_POST['product_block_template']) : '',
			'date_format'              => isset($_POST['date_format']) ? wp_unslash((string) $_POST['date_format']) : 'Y-m-d H:i',
			'use_persian_digits'       => isset($_POST['use_persian_digits']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['use_persian_digits'])),
		);

		/*
		 * Changing the store unit invalidates every stored rate and every fixed
		 * amount by x10. The UI confirms this first (admin.js) and sends
		 * `confirm_unit_change`, so an unexpected unit change is refused rather
		 * than silently corrupting prices.
		 */
		$unit_changed = isset($values['store_currency_unit'])
			&& in_array($values['store_currency_unit'], array('rial', 'toman'), true)
			&& $values['store_currency_unit'] !== $old_unit;

		$confirmed = isset($_POST['confirm_unit_change']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['confirm_unit_change']));
		$migration = null;

		if ($unit_changed) {
			if (! $confirmed) {
				wp_send_json_error(
					array(
						'message' => __('Changing the store unit converts every rate and every fixed amount. Please confirm the change.', 'ratebridge-woo'),
						'unit_changed' => true,
						'old_unit' => $old_unit,
						'new_unit' => $values['store_currency_unit'],
					),
					409
				);
			}

			// 1. Persist the new unit so rounding/precision reads use it.
			Settings::update($values);

			// 2. Convert every stored store-unit amount (rates, fixed amounts,
			//    product meta, rounding step) and queue one repricing job.
			$migration = (new UnitMigration())->migrate($old_unit, (string) $values['store_currency_unit']);

			if (! empty($migration['codes'])) {
				UnitMigration::queue_reprice($migration['codes']);
			}

			$saved = Settings::all();
		} else {
			$saved = Settings::update($values);
		}

		// Rendered fragments depend on the unit/date/digits settings.
		\RateBridge\Frontend\Frontend::flush_cache();

		$message = $migration && ! empty($migration['changed'])
			? sprintf(
				/* translators: 1: old unit, 2: new unit, 3: currencies, 4: products */
				__('Settings saved. The store unit changed from %1$s to %2$s; %3$d currencies and %4$d products were converted and prices are being recalculated.', 'ratebridge-woo'),
				$old_unit,
				(string) $saved['store_currency_unit'],
				(int) ($migration['currencies'] ?? 0),
				(int) ($migration['products'] ?? 0)
			)
			: __('Settings saved.', 'ratebridge-woo');

		wp_send_json_success(
			array(
				'message'   => $message,
				'settings'  => $saved,
				'migration' => $migration,
			)
		);
	}

	/**
	 * Save a provider's settings (Price sources tab).
	 *
	 * @return void
	 */
	private function save_provider_settings(): void
	{
		$provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';

		if ('' === $provider_key) {
			wp_send_json_error(array('message' => __('Unknown provider.', 'ratebridge-woo')), 400);
		}

		// Collect the raw field values for this provider only.
		$raw = array();

		if (isset($_POST['fields']) && is_array($_POST['fields'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field type below.
			$submitted = wp_unslash($_POST['fields']);

			foreach ((array) $submitted as $field_key => $field_value) {
				$raw[sanitize_key((string) $field_key)] = is_scalar($field_value) ? (string) $field_value : '';
			}
		}

		$service = new ProviderSettings();
		$result  = $service->save($provider_key, $raw);

		if (is_wp_error($result)) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'errors'  => (array) ($result->get_error_data()['errors'] ?? array()),
				),
				400
			);
		}

		$provider = ProviderRegistry::instance()->get($provider_key);

		wp_send_json_success(
			array(
				'message' => __('Provider settings saved.', 'ratebridge-woo'),
				'card'    => $provider instanceof ProviderInterface ? $service->card_model($provider) : array(),
			)
		);
	}

	/**
	 * Perform one test fetch against a provider.
	 *
	 * @return void
	 */
	private function test_provider(): void
	{
		$provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';
		$service      = new ProviderSettings();
		$result       = $service->test_connection($provider_key);

		if (is_wp_error($result)) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'details' => (array) $result->get_error_data(),
				)
			);
		}

		wp_send_json_success($result);
	}

	/**
	 * Reset a provider's request budget counters.
	 *
	 * @return void
	 */
	private function reset_budget(): void
	{
		$provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';
		$provider     = ProviderRegistry::instance()->get($provider_key);

		if (null === $provider) {
			wp_send_json_error(array('message' => __('Unknown provider.', 'ratebridge-woo')), 400);
		}

		// Clear both the quota state and any cached payloads.
		RequestBudget::reset($provider_key);

		if ($provider instanceof AbstractProvider) {
			$provider->flush_cache();
		}

		wp_send_json_success(
			array(
				'message' => __('Request counters reset.', 'ratebridge-woo'),
				'card'    => (new ProviderSettings())->card_model($provider),
			)
		);
	}

	/**
	 * Add a currency from a provider preset in one click.
	 *
	 * @return void
	 */
	private function add_preset(): void
	{
		$provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';
		$symbol       = isset($_POST['symbol']) ? sanitize_text_field(wp_unslash((string) $_POST['symbol'])) : '';
		$code         = isset($_POST['code']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['code']))) : '';
		$name         = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';

		$registry = ProviderRegistry::instance();
		$provider = $registry->get($provider_key);

		if (null === $provider || '' === $symbol) {
			wp_send_json_error(array('message' => __('Invalid preset.', 'ratebridge-woo')), 400);
		}

		$symbol = $provider->normalize_symbol($symbol);
		$known  = $provider->get_known_symbols();

		if (! array_key_exists($symbol, $known)) {
			wp_send_json_error(array('message' => __('This symbol is not offered by the selected source.', 'ratebridge-woo')), 400);
		}

		$repo = new CurrencyRepository();
		$code = preg_replace('/[^A-Z0-9_\-]/', '', $code) ?? '';

		if ('' === $code || '' === $name) {
			wp_send_json_error(array('message' => __('Code and name are required.', 'ratebridge-woo')), 400);
		}

		if ($repo->code_exists($code)) {
			// Tell the UI which free code to suggest instead of a bare error.
			$suggestion = self::unique_code($repo, $code, $provider_key);

			wp_send_json_error(
				array(
					'message'    => __('This code is already used by another currency.', 'ratebridge-woo'),
					'suggestion' => $suggestion,
				),
				400
			);
		}

		$now = current_time('mysql', true);

		$id = $repo->create(
			array(
				'code'                    => $code,
				'name'                    => $name,
				'symbol'                  => '',
				'provider'                => $provider_key,
				'provider_symbol'         => $symbol,
				'rate'                    => 0,
				'is_enabled'              => 0,
				'update_interval_minutes' => (int) Settings::get('default_update_interval', 60),
				'sort_order'              => 0,
				'created_at'              => $now,
				'updated_at'              => $now,
			)
		);

		if ($id <= 0) {
			wp_send_json_error(array('message' => __('Could not save the currency.', 'ratebridge-woo')), 500);
		}

		$currency = $repo->get($id);

		wp_send_json_success(
			array(
				'message'  => __('Currency added from presets. Enable it to start updating.', 'ratebridge-woo'),
				'currency' => $currency instanceof Currency ? $this->currency_payload($currency) : array(),
			)
		);
	}

	/**
	 * Suggest a free currency code derived from a taken one.
	 *
	 * `USD` + provider `navasan` -> `USD_NAVASAN`, then `USD_NAVASAN2`, ...
	 * Always <= 32 chars, always [A-Z0-9_-].
	 *
	 * @param CurrencyRepository $repo         Repository.
	 * @param string             $taken        Existing code.
	 * @param string             $provider_key Provider key used as a suffix.
	 * @return string
	 */
	public static function unique_code(CurrencyRepository $repo, string $taken, string $provider_key): string
	{
		$suffix = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $provider_key) ?? '');
		$base   = substr($taken, 0, max(1, 32 - (strlen($suffix) + 2)));

		$candidate = '' !== $suffix ? $base . '_' . $suffix : $base;
		$attempt   = 1;

		while ($repo->code_exists($candidate)) {
			++$attempt;
			$tail      = (string) $attempt;
			$candidate = substr($base, 0, 32 - (strlen($suffix) + strlen($tail) + 2)) . '_' . $suffix . $tail;

			if ($attempt > 50) {
				break;
			}
		}

		return $candidate;
	}

	/**
	 * Return the known symbols of one provider (for the dialog).
	 *
	 * @return void
	 */
	private function get_symbols(): void
	{
		$provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';
		$registry     = ProviderRegistry::instance();

		wp_send_json_success(
			array(
				'symbols' => $registry->known_symbols($provider_key),
			)
		);
	}

	/**
	 * Return the log entries as HTML-free data.
	 *
	 * @return void
	 */
	private function get_log(): void
	{
		$entries = array();

		foreach (Logger::all() as $entry) {
			$entries[] = array(
				'time'       => isset($entry['time']) ? wp_date('Y-m-d H:i:s', (int) $entry['time']) : '',
				'status'     => isset($entry['status']) ? (string) $entry['status'] : 'error',
				'message'    => isset($entry['message']) ? (string) $entry['message'] : '',
				'trigger'    => isset($entry['trigger']) ? (string) $entry['trigger'] : 'cron',
				'currencies' => isset($entry['currencies']) && is_array($entry['currencies']) ? implode(', ', $entry['currencies']) : '',
			);
		}

		wp_send_json_success(array('entries' => $entries));
	}

	/**
	 * Clear the log.
	 *
	 * @return void
	 */
	private function clear_log(): void
	{
		Logger::clear();

		wp_send_json_success(array('message' => __('Log cleared.', 'ratebridge-woo')));
	}

	/**
	 * Live price preview for the product editor.
	 *
	 * Takes the *unsaved* form values, resolves the currency from the
	 * repository and runs the same PriceCalculator path as the Repricer, so what
	 * the admin sees is exactly what will be stored.
	 *
	 * @return void
	 */
	private function preview_price(): void
	{
		if (! current_user_can('edit_products')) {
			wp_send_json_error(array('message' => __('You are not allowed to do this.', 'ratebridge-woo')), 403);
		}

		$code = isset($_POST['currency']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['currency']))) : '';
		$repo = new CurrencyRepository();
		$currency = '' !== $code ? $repo->get_by_code($code) : null;

		if (null === $currency) {
			wp_send_json_error(array('message' => __('Select a currency first.', 'ratebridge-woo')), 400);
		}

		if (! $currency->is_enabled) {
			wp_send_json_error(array('message' => __('This currency is disabled.', 'ratebridge-woo')), 400);
		}

		if (! $currency->has_rate()) {
			wp_send_json_error(array('message' => __('This currency has no rate yet.', 'ratebridge-woo')), 400);
		}

		$base = ProductData::to_decimal(isset($_POST['base_price']) ? wp_unslash((string) $_POST['base_price']) : 0);

		if ($base <= 0) {
			wp_send_json_error(array('message' => __('Enter the base price first.', 'ratebridge-woo')), 400);
		}

		// Preview-only overrides (never persisted here).
		$mode = PriceCalculator::to_mode(isset($_POST['profit_mode']) ? sanitize_key(wp_unslash((string) $_POST['profit_mode'])) : 'none');

		$profit = $this->preview_rule('profit');
		$commission = $this->preview_rule('commission');
		$extra = $this->preview_rule('extra');
		$extra['type'] = 'fixed';

		$min_price = ProductData::to_decimal(isset($_POST['min_price']) ? wp_unslash((string) $_POST['min_price']) : 0);
		$fluctuation = ProductData::to_decimal(isset($_POST['fluctuation']) ? wp_unslash((string) $_POST['fluctuation']) : 0);

		$post_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$previous = 0.0;

		if ($post_id > 0) {
			$previous = ProductData::to_decimal(get_post_meta($post_id, ProductData::META_LAST_PRICE, true));
		}

		$base_value = $base * $currency->rate;

		$resolved_profit = PriceCalculator::merge_rules($currency->profit_rule(), $profit, $mode, $base_value);
		$profit_amount = PriceCalculator::rule_amount($resolved_profit, $base_value);

		$resolved_commission = PriceCalculator::merge_rules($currency->commission_rule(), $commission, $mode, $base_value + $profit_amount);
		$resolved_extra = PriceCalculator::merge_rules($currency->extra_rule(), $extra, $mode, $base_value + $profit_amount);

		$settings = array(
			'profit' => array('type' => $resolved_profit['type'], 'value' => $resolved_profit['value']),
			'commission' => array('type' => $resolved_commission['type'], 'value' => $resolved_commission['value']),
			'extra' => array('type' => $resolved_extra['type'], 'value' => $resolved_extra['value']),
			'min_price' => $min_price,
			'rounding_mode' => Settings::rounding_mode(),
			'rounding_step' => Settings::rounding_step(),
			'decimals' => Settings::store_decimals(),
			'fluctuation' => $fluctuation,
			'previous_price' => $previous,
		);

		$result = PriceCalculator::calculate($base, $currency->rate, $settings);

		/**
		 * Filter the price produced by the calculator.
		 *
		 * Applies to the live preview and to the stored price, so a filter that
		 * changes the number changes the real price too.
		 *
		 * @param float                $price    Calculated price.
		 * @param float                $base     Base price in the foreign unit.
		 * @param Currency             $currency Currency used.
		 * @param int                  $post_id  Product id (0 for an unsaved product).
		 * @param array<string, mixed> $settings Calculator settings.
		 */
		if ($result['ok']) {
			$filtered = (float) apply_filters('rbw_calculated_price', (float) $result['price'], $base, $currency, $post_id, $settings);

			if (is_finite($filtered) && $filtered > 0) {
				$result['price'] = $filtered;
				$result['breakdown']['final'] = $filtered;
			}
		}

		$sale_base = ProductData::to_decimal(isset($_POST['sale_base_price']) ? wp_unslash((string) $_POST['sale_base_price']) : 0);
		$sale_result = null;

		if ($sale_base > 0 && $result['ok']) {
			$sale_value = $sale_base * $currency->rate;
			$sale_profit = PriceCalculator::merge_rules($currency->profit_rule(), $profit, $mode, $sale_value);
			$sale_profit_amount = PriceCalculator::rule_amount($sale_profit, $sale_value);
			$sale_commission = PriceCalculator::merge_rules($currency->commission_rule(), $commission, $mode, $sale_value + $sale_profit_amount);
			$sale_extra = PriceCalculator::merge_rules($currency->extra_rule(), $extra, $mode, $sale_value + $sale_profit_amount);

			$sale_settings = array_merge(
				$settings,
				array(
					'profit' => array('type' => $sale_profit['type'], 'value' => $sale_profit['value']),
					'commission' => array('type' => $sale_commission['type'], 'value' => $sale_commission['value']),
					'extra' => array('type' => $sale_extra['type'], 'value' => $sale_extra['value']),
					'min_price' => 0.0,
					'fluctuation' => 0.0,
					'previous_price' => 0.0,
				)
			);

			$sale_result = PriceCalculator::calculate($sale_base, $currency->rate, $sale_settings);

			if (is_array($sale_result) && ! empty($sale_result['ok']) && $sale_result['price'] >= $result['price']) {
				// A sale price above the regular price is meaningless.
				$sale_result['warning'] = __('The sale price is not lower than the regular price, so it will not be stored.', 'ratebridge-woo');
			}
		}

		$payload = array(
			'ok' => (bool) $result['ok'],
			'error' => (string) $result['error'],
			'message' => $result['ok'] ? '' : $this->calculator_error_message((string) $result['error']),
			'currency' => array(
				'code' => $currency->code,
				'name' => $currency->name,
				'rate' => $currency->rate,
				'rate_formatted' => SettingsPage::format_rate($currency),
			),
			'price' => $result['ok'] ? (float) $result['price'] : null,
			'price_formatted' => $result['ok'] ? $this->format_money((float) $result['price']) : '',
			'sale_price' => is_array($sale_result) && ! empty($sale_result['ok']) ? (float) $sale_result['price'] : null,
			'sale_price_formatted' => is_array($sale_result) && ! empty($sale_result['ok']) ? $this->format_money((float) $sale_result['price']) : '',
			'sale_warning' => is_array($sale_result) ? (string) ($sale_result['warning'] ?? '') : '',
			'breakdown' => $this->format_breakdown($result),
			'rules' => $result['rules'],
			'converted' => array(
				'profit' => (bool) $resolved_profit['converted'],
				'commission' => (bool) $resolved_commission['converted'],
				'extra' => (bool) $resolved_extra['converted'],
			),
			'unit' => SettingsPage::unit_label(),
			'stale' => (new Repricer())->is_stale($currency),
		);

		if (! $result['ok']) {
			wp_send_json_error($payload, 200);
		}

		wp_send_json_success($payload);
	}

	/**
	 * Read one rule from the (unsaved) preview form.
	 *
	 * @param string $slot profit|commission|extra.
	 * @return array{type:string,value:float}
	 */
	private function preview_rule(string $slot): array
	{
		$type = isset($_POST['rbw_' . $slot . '_type'])
			? PriceCalculator::to_type(sanitize_key(wp_unslash((string) $_POST['rbw_' . $slot . '_type'])))
			: 'percent';

		$value = ProductData::to_decimal(isset($_POST[ $slot . '_value' ]) ? wp_unslash((string) $_POST[ $slot . '_value' ]) : 0);

		$rule = PriceCalculator::sanitize_rule(array('type' => $type, 'value' => $value));

		return is_array($rule) ? $rule : array('type' => 'percent', 'value' => 0.0);
	}

	/**
	 * Convert stored amounts after a store-unit change.
	 *
	 * The General settings tab asks for confirmation, then calls this endpoint
	 * once; UnitMigration converts every amount and queues a repricing job.
	 *
	 * @return void
	 */
	private function migrate_unit(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => __('You are not allowed to do this.', 'ratebridge-woo')), 403);
		}

		$old = sanitize_key(wp_unslash((string) ($_POST['old_unit'] ?? Settings::store_unit())));
		$new = sanitize_key(wp_unslash((string) ($_POST['new_unit'] ?? Settings::store_unit())));

		$result = (new UnitMigration())->migrate($old, $new);

		if (empty($result['changed'])) {
			wp_send_json_success(
				array(
					'message' => __('The store unit did not change, so nothing was converted.', 'ratebridge-woo'),
					'result' => $result,
				)
			);
		}

		UnitMigration::queue_reprice($result['codes']);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: currency rows, 2: products */
					__('Converted %1$d currencies and %2$d products. Prices are being recalculated in the background.', 'ratebridge-woo'),
					(int) $result['currencies'],
					(int) $result['products']
				),
				'result' => $result,
			)
		);
	}

	/**
	 * Dismiss the stale-rate admin notice for the current user.
	 *
	 * Delegated to AdminNotices so the notice and the dismissal stay together.
	 *
	 * @return void
	 */
	private function dismiss_stale_notice(): void
	{
		AdminNotices::dismiss_stale_notice();
	}

	/**
	 * Read and validate the pricing inputs of the currency dialog.
	 *
	 * Percent values must stay in 0–1000, fixed amounts are store-unit amounts
	 * and may never be negative.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function read_pricing_input(): array|WP_Error
	{
		$profit_type = isset($_POST['profit_type']) ? CurrencyRepository::sanitize_type(wp_unslash((string) $_POST['profit_type'])) : 'percent';
		$commission_type = isset($_POST['commission_type']) ? CurrencyRepository::sanitize_type(wp_unslash((string) $_POST['commission_type'])) : 'percent';

		$raw = array(
			'profit_value' => isset($_POST['profit_value']) ? wp_unslash((string) $_POST['profit_value']) : '0',
			'commission_value' => isset($_POST['commission_value']) ? wp_unslash((string) $_POST['commission_value']) : '0',
			'extra_amount' => isset($_POST['extra_amount']) ? wp_unslash((string) $_POST['extra_amount']) : '0',
		);

		foreach (array('profit_value', 'commission_value', 'extra_amount') as $key) {
			$raw[ $key ] = str_replace(array(',', ' '), '', $raw[ $key ]);

			if ('' !== $raw[ $key ] && (! is_numeric($raw[ $key ]) || (float) $raw[ $key ] < 0)) {
				return new WP_Error('rbw_invalid_number', __('Profit, commission and extra amount must be zero or a positive number.', 'ratebridge-woo'));
			}
		}

		foreach (array('profit_value' => $profit_type, 'commission_value' => $commission_type) as $key => $type) {
			if ('percent' === $type && '' !== $raw[ $key ] && (float) $raw[ $key ] > PriceCalculator::MAX_PERCENT) {
				return new WP_Error('rbw_invalid_percent', __('Percentages must be between 0 and 1000.', 'ratebridge-woo'));
			}
		}

		return array(
			'profit_type' => $profit_type,
			'profit_value' => CurrencyRepository::sanitize_value($raw['profit_value'], $profit_type),
			'commission_type' => $commission_type,
			'commission_value' => CurrencyRepository::sanitize_value($raw['commission_value'], $commission_type),
			'extra_amount' => CurrencyRepository::sanitize_value($raw['extra_amount'], 'fixed'),
		);
	}

	/**
	 * Persian label for a percent/fixed selector.
	 *
	 * @param string $type percent|fixed.
	 * @return string
	 */
	private function type_label(string $type): string
	{
		return 'fixed' === $type ? __('Fixed amount', 'ratebridge-woo') : __('Percent', 'ratebridge-woo');
	}

	/**
	 * Persian message for a calculator reason code.
	 *
	 * @param string $code Reason code.
	 * @return string
	 */
	private function calculator_error_message(string $code): string
	{
		return match ($code) {
			PriceCalculator::ERROR_INVALID_RATE => __('The currency rate is not valid.', 'ratebridge-woo'),
			PriceCalculator::ERROR_INVALID_BASE => __('The base price is not valid.', 'ratebridge-woo'),
			PriceCalculator::ERROR_INVALID_SETTING => __('A pricing setting is out of range.', 'ratebridge-woo'),
			PriceCalculator::ERROR_NON_FINITE => __('The calculation produced a non-finite number.', 'ratebridge-woo'),
			default => __('The calculation produced a price of zero or less.', 'ratebridge-woo'),
		};
	}

	/**
	 * Format a money amount for display.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function format_money(float $amount): string
	{
		return number_format_i18n($amount, Settings::store_decimals());
	}

	/**
	 * Formatted breakdown rows for the preview panel.
	 *
	 * @param array<string, mixed> $result Calculator result.
	 * @return array<int, array<string, string>>
	 */
	private function format_breakdown(array $result): array
	{
		$breakdown = is_array($result['breakdown'] ?? null) ? $result['breakdown'] : array();
		$rows = array();

		$map = array(
			'base_value' => __('Base value (price × rate)', 'ratebridge-woo'),
			'profit' => __('Profit', 'ratebridge-woo'),
			'commission' => __('Commission', 'ratebridge-woo'),
			'extra' => __('Extra amount', 'ratebridge-woo'),
			'before_min' => __('Total before the minimum', 'ratebridge-woo'),
			'after_min' => __('After the minimum', 'ratebridge-woo'),
			'rounded' => __('After rounding', 'ratebridge-woo'),
			'final' => __('Final price', 'ratebridge-woo'),
		);

		foreach ($map as $key => $label) {
			if (! isset($breakdown[ $key ]) || ! is_numeric($breakdown[ $key ])) {
				continue;
			}

			// Skip empty middle rows to keep the panel short.
			if (in_array($key, array('profit', 'commission', 'extra'), true) && (float) $breakdown[ $key ] <= 0) {
				continue;
			}

			if (in_array($key, array('after_min', 'rounded'), true) && (float) $breakdown[ $key ] === (float) ($breakdown['before_min'] ?? 0)) {
				continue;
			}

			$rows[] = array(
				'key' => $key,
				'label' => $label,
				'value' => $this->format_money((float) $breakdown[ $key ]),
			);
		}

		return $rows;
	}

	/**
	 * Serialisable currency payload for JS.
	 *
	 * @param Currency $currency Currency.
	 * @return array<string, mixed>
	 */
	private function currency_payload(Currency $currency): array
	{
		return array(
			'id'                      => $currency->id,
			'code'                    => $currency->code,
			'name'                    => $currency->name,
			'symbol'                  => $currency->symbol,
			'provider'                => $currency->provider,
			'provider_symbol'         => $currency->provider_symbol,
			'rate'                    => $currency->rate,
			'rate_formatted'          => SettingsPage::format_rate($currency),
			'is_enabled'              => $currency->is_enabled,
			'update_interval_minutes' => $currency->update_interval_minutes,
			'last_updated'            => SettingsPage::format_datetime($currency->last_updated),
			'last_error'              => (string) $currency->last_error,
			'sort_order'              => $currency->sort_order,
			'profit_type'             => $currency->profit_type,
			'profit_value'            => $currency->profit_value,
			'commission_type'         => $currency->commission_type,
			'commission_value'        => $currency->commission_value,
			'extra_amount'            => $currency->extra_amount,
			'profit_type_label'       => $this->type_label( $currency->profit_type ),
			'commission_type_label'   => $this->type_label( $currency->commission_type ),
		);
	}
}
