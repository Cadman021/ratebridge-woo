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
use RateBridge\ImportExport\AdminController;
use RateBridge\ImportExport\Importer;
use RateBridge\ImportExport\ImportPlan;
use RateBridge\ImportExport\Package;
use RateBridge\ImportExport\ProductCsv;
use RateBridge\ImportExport\ProductsCsvService;
use RateBridge\Formula\Formula;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Core\UnitMigration;
use RateBridge\Formula\FormulaModel as FormulaModelValue;
use RateBridge\Pricing\FormulaPricer;
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
			'validate_formula',
			'test_formula',
			'save_formula',
			'delete_formula',
			'duplicate_formula',
			'toggle_formula',
			'add_formula_samples',
			'export_info',
			'import_package_dry_run',
			'import_package_execute',
			'list_backups',
			'restore_backup',
			'import_products_csv',
			'import_products_chunk',
		);
	}

	/**
	 * Capability required per dispatcher action.
	 *
	 * Everything defaults to `manage_woocommerce` (the plugin's own settings).
	 * Actions that only touch product data are open to users who can edit
	 * products, so a shop manager with `edit_products` but without
	 * `manage_woocommerce` can still use the live price preview and the CSV
	 * import. Each handler keeps its own per-object `edit_post` check.
	 *
	 * @return array<string, string> action => capability
	 */
	public static function capabilities(): array
	{
		$map = array();

		foreach (self::actions() as $action) {
			$map[ $action ] = 'manage_woocommerce';
		}

		$map['preview_price'] = 'edit_products';
		$map['import_products_csv'] = 'edit_products';
		$map['import_products_chunk'] = 'edit_products';

		return $map;
	}

	/**
	 * Capability for one action.
	 *
	 * @param string $action Dispatcher slug.
	 * @return string
	 */
	public static function capability_for(string $action): string
	{
		return self::capabilities()[ $action ] ?? 'manage_woocommerce';
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
			'rbw_validate_formula'           => 'validate_formula',
			'rbw_test_formula'               => 'test_formula',
			'rbw_save_formula'               => 'save_formula',
			'rbw_delete_formula'             => 'delete_formula',
			'rbw_toggle_formula'             => 'toggle_formula',
			'rbw_import_package_dry_run'     => 'import_package_dry_run',
			'rbw_import_package_execute'     => 'import_package_execute',
			'rbw_restore_backup'             => 'restore_backup',
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

		$action = $this->resolve_action();

		if ('' === $action || ! in_array($action, self::actions(), true)) {
			// Capability is checked only after the action is known to be ours.
			if (! current_user_can('manage_woocommerce')) {
				wp_send_json_error(array('message' => __('You are not allowed to do this.', 'ratebridge-woo')), 403);
			}

			wp_send_json_error(array('message' => __('Unknown action.', 'ratebridge-woo')), 400);
		}

		// Single capability gate, resolved from the action map.
		if (! current_user_can(self::capability_for($action))) {
			wp_send_json_error(array('message' => __('You are not allowed to do this.', 'ratebridge-woo')), 403);
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
			case 'validate_formula':
				$this->validate_formula();
				break;
			case 'test_formula':
				$this->test_formula();
				break;
			case 'save_formula':
				$this->save_formula();
				break;
			case 'delete_formula':
				$this->delete_formula();
				break;
			case 'duplicate_formula':
				$this->duplicate_formula();
				break;
			case 'toggle_formula':
				$this->toggle_formula();
				break;
			case 'add_formula_samples':
				$this->add_formula_samples();
				break;
			case 'export_info':
				$this->export_info();
				break;
			case 'import_package_dry_run':
				$this->import_package_dry_run();
				break;
			case 'import_package_execute':
				$this->import_package_execute();
				break;
			case 'list_backups':
				$this->list_backups();
				break;
			case 'restore_backup':
				$this->restore_backup();
				break;
			case 'import_products_csv':
				$this->import_products_csv();
				break;
			case 'import_products_chunk':
				$this->import_products_chunk();
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

		/*
		 * Formula mode has its own preview: the expression, not a base price,
		 * produces the number. Answered before any currency validation so a
		 * formula product needs no currency at all.
		 */
		$pricing_mode = isset($_POST['mode']) ? sanitize_key(wp_unslash((string) $_POST['mode'])) : ProductData::MODE_CURRENCY;

		if (ProductData::MODE_FORMULA === $pricing_mode) {
			$this->preview_formula_price();
			return;
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
	 * Preview a price for a product in formula mode.
	 *
	 * @return void
	 */
	private function preview_formula_price(): void
	{
		$formula_id = isset($_POST['formula_id']) ? absint($_POST['formula_id']) : 0;
		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$repo = new FormulaRepository();
		$formula = $repo->get($formula_id);

		if (! $formula instanceof FormulaModel) {
			wp_send_json_error(array('message' => __('Select a formula first.', 'ratebridge-woo')), 400);
		}

		$product = $product_id > 0 ? wc_get_product($product_id) : null;

		if (! $product instanceof \WC_Product) {
			wp_send_json_error(array('message' => __('Save the product once before previewing a formula price.', 'ratebridge-woo')), 400);
		}

		// Preview-only overrides typed in the form (nothing is saved here).
		$posted = isset($_POST['formula_vars']) && is_array($_POST['formula_vars']) ? wp_unslash($_POST['formula_vars']) : array();
		$overrides = array();

		foreach ($formula->variables_of_type(FormulaModel::TYPE_CUSTOM) as $variable) {
			$name = (string) $variable['name'];

			if (isset($posted[ $name ]) && '' !== trim((string) $posted[ $name ]) && is_numeric($posted[ $name ])) {
				$overrides[ $name ] = (float) $posted[ $name ];
			}
		}

		// Temporarily write the overrides so the pricer reads them like the editor
		// would after saving; restore immediately afterwards.
		$previous_meta = get_post_meta($product_id, ProductData::META_FORMULA_VARS, true);

		if (empty($overrides)) {
			delete_post_meta($product_id, ProductData::META_FORMULA_VARS);
		} else {
			update_post_meta($product_id, ProductData::META_FORMULA_VARS, wp_json_encode($overrides));
		}

		$pricer = new FormulaPricer();
		$previous_price = ProductData::to_decimal(get_post_meta($product_id, ProductData::META_LAST_PRICE, true));
		$calc = $pricer->calculate($product, $formula, $previous_price, false);

		// Restore the stored overrides: a preview must never persist anything.
		if ('' === $previous_meta || null === $previous_meta) {
			delete_post_meta($product_id, ProductData::META_FORMULA_VARS);
		} else {
			update_post_meta($product_id, ProductData::META_FORMULA_VARS, $previous_meta);
		}

		$rows = array();

		foreach ((array) ($calc['breaks'] ?? array()) as $row) {
			$rows[] = array(
				'key' => (string) ($row['key'] ?? ''),
				'label' => (string) ($row['label'] ?? ''),
				'value' => $this->format_money((float) ($row['value'] ?? 0)),
			);
		}

		$variables = array();

		foreach ((array) ($calc['variables'] ?? array()) as $name => $value) {
			$variables[] = array(
				'name' => (string) $name,
				'value' => is_numeric($value) ? $this->format_money((float) $value) : (string) $value,
			);
		}

		$repricer = new Repricer();
		$payload = array(
			'ok' => (bool) $calc['ok'],
			'code' => (string) $calc['code'],
			'message' => $calc['ok'] ? '' : $repricer->formula_error_message((string) $calc['code'], $formula),
			'formula' => array('id' => $formula->id, 'name' => $formula->name),
			'price' => $calc['ok'] ? (float) $calc['price'] : null,
			'price_formatted' => $calc['ok'] ? $this->format_money((float) $calc['price']) : '',
			'value_formatted' => $this->format_money((float) $calc['value']),
			'breakdown' => $rows,
			'variables' => $variables,
			'kept' => (bool) ($calc['kept'] ?? false),
			'unit' => SettingsPage::unit_label(),
		);

		if (! $calc['ok']) {
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
	 * Read the variables posted by the formula builder.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read_formula_variables(): array
	{
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
		$raw = isset($_POST['variables']) && is_array($_POST['variables']) ? wp_unslash($_POST['variables']) : array();
		$list = array();

		foreach ($raw as $row) {
			if (! is_array($row)) {
				continue;
			}

			$name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';

			if (! Formula::is_valid_variable_name($name)) {
				continue;
			}

			$type = isset($row['type']) ? sanitize_key((string) $row['type']) : 'custom';

			$item = array(
				'name' => $name,
				'label' => isset($row['label']) ? sanitize_text_field((string) $row['label']) : $name,
				'type' => $type,
			);

			if (FormulaModel::TYPE_CURRENCY === $type) {
				$code = isset($row['currency_code']) ? strtoupper(sanitize_text_field((string) $row['currency_code'])) : '';
				$item['currency_code'] = preg_replace('/[^A-Z0-9_\-]/', '', $code) ?? '';
			}

			if (FormulaModel::TYPE_PRODUCT === $type) {
				$field = isset($row['product_field']) ? sanitize_key((string) $row['product_field']) : 'weight';
				$item['product_field'] = $field;
			}

			if (FormulaModel::TYPE_CUSTOM === $type) {
				$item['default'] = isset($row['default']) ? (float) $row['default'] : 0.0;
				$item['is_money'] = ! empty($row['is_money']);
				$item['min'] = isset($row['min']) && '' !== $row['min'] ? (float) $row['min'] : null;
				$item['max'] = isset($row['max']) && '' !== $row['max'] ? (float) $row['max'] : null;
			}

			$list[] = $item;
		}

		return $list;
	}

	/**
	 * Validate an expression against the declared variables.
	 *
	 * @return void
	 */
	private function validate_formula(): void
	{
		$expression = isset($_POST['expression']) ? wp_strip_all_tags(wp_unslash((string) $_POST['expression'])) : '';
		$variables = $this->read_formula_variables();
		$declared = array_column($variables, 'name');

		$report = Formula::check($expression, $declared);

		if (! $report['ok']) {
			wp_send_json_error(
				array(
					'message' => $this->formula_message((string) $report['code']),
					'code' => (string) $report['code'],
					'position' => (int) $report['position'],
					'detail' => (string) $report['detail'],
					'expression' => $expression,
				),
				200
			);
		}

		wp_send_json_success(
			array(
				'message' => __('The formula is valid.', 'ratebridge-woo'),
				'source' => (string) $report['source'],
				'variables' => (array) $report['variables'],
				'functions' => (array) $report['functions'],
				'undeclared' => (array) $report['undeclared'],
				'unused' => (array) $report['unused'],
			)
		);
	}

	/**
	 * Evaluate an expression with sample values (nothing is saved).
	 *
	 * @return void
	 */
	private function test_formula(): void
	{
		$expression = isset($_POST['expression']) ? wp_strip_all_tags(wp_unslash((string) $_POST['expression'])) : '';
		$variables = $this->read_formula_variables();
		$declared = array_column($variables, 'name');

		$report = Formula::check($expression, $declared);

		if (! $report['ok']) {
			wp_send_json_error(
				array(
					'message' => $this->formula_message((string) $report['code']),
					'code' => (string) $report['code'],
					'position' => (int) $report['position'],
				),
				200
			);
		}

		$compiled = Formula::compile($expression);

		if (! $compiled instanceof \RateBridge\Formula\CompiledFormula) {
			wp_send_json_error(array('message' => $this->formula_message($compiled->code)), 200);
		}

		// Sample values posted from the test panel.
		$raw_values = isset($_POST['values']) && is_array($_POST['values']) ? wp_unslash($_POST['values']) : array();
		$values = array();
		$used_defaults = array();

		foreach ($variables as $variable) {
			$name = (string) $variable['name'];

			if (isset($raw_values[$name]) && '' !== $raw_values[$name] && is_numeric($raw_values[$name])) {
				$values[$name] = (float) $raw_values[$name];
				continue;
			}

			if (FormulaModel::TYPE_CURRENCY === $variable['type']) {
				$currency = (new CurrencyRepository())->get_by_code((string) ($variable['currency_code'] ?? ''));

				if ($currency instanceof Currency && $currency->has_rate()) {
					$values[$name] = $currency->rate;
					continue;
				}
			}

			if (FormulaModel::TYPE_CUSTOM === $variable['type'] && isset($variable['default'])) {
				$values[$name] = (float) $variable['default'];
				$used_defaults[] = $name;
			}
		}

		$result = $compiled->evaluate($values);

		if (! $result instanceof \RateBridge\Formula\Result) {
			wp_send_json_error(
				array(
					'message' => $this->formula_message($result->code),
					'code' => $result->code,
					'variables' => $values,
				),
				200
			);
		}

		wp_send_json_success(
			array(
				'message' => __('The formula was evaluated.', 'ratebridge-woo'),
				'value' => $result->value,
				'value_formatted' => SettingsPage::format_number($result->value),
				'trace' => $result->trace,
				'used_defaults' => $used_defaults,
				'unit' => SettingsPage::unit_label(),
			)
		);
	}

	/**
	 * Create or update a formula.
	 *
	 * @return void
	 */
	private function save_formula(): void
	{
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
		$expression = isset($_POST['expression']) ? wp_strip_all_tags(wp_unslash((string) $_POST['expression'])) : '';
		$variables = $this->read_formula_variables();

		if ('' === $name || '' === trim($expression)) {
			wp_send_json_error(array('message' => __('Name and expression are required.', 'ratebridge-woo')), 400);
		}

		$report = Formula::check($expression, array_column($variables, 'name'));

		if (! $report['ok']) {
			wp_send_json_error(
				array(
					'message' => $this->formula_message((string) $report['code']),
					'code' => (string) $report['code'],
					'position' => (int) $report['position'],
				),
				400
			);
		}

		$data = array(
			'name' => $name,
			'slug' => isset($_POST['slug']) ? sanitize_key(wp_unslash((string) $_POST['slug'])) : '',
			'expression' => $expression,
			'description' => isset($_POST['description']) ? wp_unslash((string) $_POST['description']) : '',
			'variables' => $variables,
			'rounding_mode' => isset($_POST['rounding_mode']) ? sanitize_key(wp_unslash((string) $_POST['rounding_mode'])) : '',
			'rounding_step' => isset($_POST['rounding_step']) ? (float) $_POST['rounding_step'] : 0.0,
			'is_enabled' => isset($_POST['is_enabled']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['is_enabled'])),
		);

		$repo = new FormulaRepository();

		if ($id > 0) {
			if (! $repo->update($id, $data)) {
				wp_send_json_error(array('message' => __('Could not save the formula.', 'ratebridge-woo')), 500);
			}
		} else {
			$id = $repo->create($data);

			if ($id <= 0) {
				wp_send_json_error(array('message' => __('Could not save the formula.', 'ratebridge-woo')), 500);
			}
		}

		$formula = $repo->get($id);

		wp_send_json_success(
			array(
				'message' => __('Formula saved. Products using it will be recalculated.', 'ratebridge-woo'),
				'formula' => $formula instanceof FormulaModel ? $this->formula_payload($formula) : array(),
			)
		);
	}

	/**
	 * Delete a formula (blocked while products use it unless confirmed).
	 *
	 * @return void
	 */
	private function delete_formula(): void
	{
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$detach = isset($_POST['detach']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['detach']));
		$repo = new FormulaRepository();
		$formula = $repo->get($id);

		if (! $formula instanceof FormulaModel) {
			wp_send_json_error(array('message' => __('Formula not found.', 'ratebridge-woo')), 404);
		}

		$used = $repo->count_products_using($id);

		if ($used > 0 && ! $detach) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: product count */
						_n('%d product uses this formula. Confirm detaching them to delete it.', '%d products use this formula. Confirm detaching them to delete it.', $used, 'ratebridge-woo'),
						$used
					),
					'products' => $used,
					'needs_confirm' => true,
				),
				409
			);
		}

		$affected = 0;

		if ($detach) {
			foreach ($repo->product_ids_using($id) as $post_id) {
				delete_post_meta($post_id, ProductData::META_FORMULA_ID);
				delete_post_meta($post_id, ProductData::META_FORMULA_VARS);
				update_post_meta($post_id, ProductData::META_MODE, ProductData::MODE_CURRENCY);
				++$affected;
			}
		}

		if (! $repo->delete($id)) {
			wp_send_json_error(array('message' => __('Could not delete the formula.', 'ratebridge-woo')), 500);
		}

		wp_send_json_success(
			array(
				'message' => $detach && $affected > 0
					? sprintf(
						/* translators: %d: product count */
						__('Formula deleted and %d products switched back to currency mode.', 'ratebridge-woo'),
						$affected
					)
					: __('Formula deleted.', 'ratebridge-woo'),
				'id' => $id,
				'detached' => $affected,
			)
		);
	}

	/**
	 * Duplicate a formula.
	 *
	 * @return void
	 */
	private function duplicate_formula(): void
	{
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$repo = new FormulaRepository();
		$formula = $repo->get($id);

		if (! $formula instanceof FormulaModel) {
			wp_send_json_error(array('message' => __('Formula not found.', 'ratebridge-woo')), 404);
		}

		$new_id = $repo->create(
			array(
				'name' => sprintf(
					/* translators: %s: original formula name */
					__('%s (copy)', 'ratebridge-woo'),
					$formula->name
				),
				'slug' => $formula->slug . '-copy',
				'expression' => $formula->expression,
				'description' => $formula->description,
				'variables' => $formula->variables,
				'rounding_mode' => $formula->rounding_mode,
				'rounding_step' => $formula->rounding_step,
				'is_enabled' => false,
			)
		);

		if ($new_id <= 0) {
			wp_send_json_error(array('message' => __('Could not duplicate the formula.', 'ratebridge-woo')), 500);
		}

		$copy = $repo->get($new_id);

		wp_send_json_success(
			array(
				'message' => __('Formula duplicated (disabled).', 'ratebridge-woo'),
				'formula' => $copy instanceof FormulaModel ? $this->formula_payload($copy) : array(),
			)
		);
	}

	/**
	 * Enable/disable a formula.
	 *
	 * @return void
	 */
	private function toggle_formula(): void
	{
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$enabled = isset($_POST['enabled']) && '1' === sanitize_text_field(wp_unslash((string) $_POST['enabled']));
		$repo = new FormulaRepository();

		if (! $repo->get($id) instanceof FormulaModel) {
			wp_send_json_error(array('message' => __('Formula not found.', 'ratebridge-woo')), 404);
		}

		if (! $repo->update($id, array('is_enabled' => $enabled))) {
			wp_send_json_error(array('message' => __('Could not save the change.', 'ratebridge-woo')), 500);
		}

		wp_send_json_success(
			array(
				'message' => $enabled ? __('Formula enabled.', 'ratebridge-woo') : __('Formula disabled.', 'ratebridge-woo'),
				'id' => $id,
				'is_enabled' => $enabled,
			)
		);
	}

	/**
	 * Create the two example formulas (disabled).
	 *
	 * @return void
	 */
	private function add_formula_samples(): void
	{
		$repo = new FormulaRepository();
		$created = array();

		$samples = array(
			array(
				'name' => __('Gold price (weight × 18K gold)', 'ratebridge-woo'),
				'slug' => 'gold-price',
				'expression' => 'weight * gold18 * (1 + wage / 100) * (1 + profit / 100)',
				'description' => __('Price a piece of gold jewellery from its weight.', 'ratebridge-woo'),
				'variables' => array(
					array('name' => 'weight', 'label' => __('Weight (g)', 'ratebridge-woo'), 'type' => 'product', 'product_field' => 'weight'),
					array('name' => 'gold18', 'label' => __('18K gold (gram)', 'ratebridge-woo'), 'type' => 'currency', 'currency_code' => 'GOLD18'),
					array('name' => 'wage', 'label' => __('Making charge (%)', 'ratebridge-woo'), 'type' => 'custom', 'default' => 15, 'is_money' => false),
					array('name' => 'profit', 'label' => __('Profit (%)', 'ratebridge-woo'), 'type' => 'custom', 'default' => 7, 'is_money' => false),
				),
			),
			array(
				'name' => __('Simple USD price', 'ratebridge-woo'),
				'slug' => 'usd-price',
				'expression' => 'base * usd + extra',
				'description' => __('Dollar price plus a fixed markup.', 'ratebridge-woo'),
				'variables' => array(
					array('name' => 'base', 'label' => __('Base price (USD)', 'ratebridge-woo'), 'type' => 'custom', 'default' => 100, 'is_money' => false),
					array('name' => 'usd', 'label' => __('US dollar', 'ratebridge-woo'), 'type' => 'currency', 'currency_code' => 'USD'),
					array('name' => 'extra', 'label' => __('Extra', 'ratebridge-woo'), 'type' => 'custom', 'default' => 0, 'is_money' => true),
				),
			),
		);

		foreach ($samples as $sample) {
			if ($repo->get_by_slug($sample['slug']) instanceof FormulaModel) {
				continue;
			}

			$sample['is_enabled'] = false;
			$id = $repo->create($sample);

			if ($id > 0) {
				$created[] = $id;
			}
		}

		if (empty($created)) {
			wp_send_json_error(array('message' => __('The sample formulas already exist.', 'ratebridge-woo')), 200);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of created formulas */
					_n('%d sample formula created (disabled).', '%d sample formulas created (disabled).', count($created), 'ratebridge-woo'),
					count($created)
				),
				'created' => $created,
			)
		);
	}

	/**
	 * Persian message for a formula engine error code.
	 *
	 * @param string $code Machine code.
	 * @return string
	 */
	private function formula_message(string $code): string
	{
		$messages = array(
			'empty' => __('The expression is empty.', 'ratebridge-woo'),
			'too_long' => __('The expression is too long (max 2000 characters).', 'ratebridge-woo'),
			'too_many_tokens' => __('The expression is too complex.', 'ratebridge-woo'),
			'too_deep' => __('The expression is nested too deeply.', 'ratebridge-woo'),
			'unexpected_char' => __('The expression contains a character that is not allowed.', 'ratebridge-woo'),
			'unexpected_token' => __('Unexpected syntax. Check the operators and parentheses.', 'ratebridge-woo'),
			'missing_paren' => __('A closing parenthesis is missing.', 'ratebridge-woo'),
			'bad_number' => __('A number is written incorrectly.', 'ratebridge-woo'),
			'number_too_long' => __('A number is too long.', 'ratebridge-woo'),
			'number_out_of_range' => __('A number is out of range.', 'ratebridge-woo'),
			'identifier_too_long' => __('A variable name is too long (max 40 characters).', 'ratebridge-woo'),
			'unknown_function' => __('This function is not supported.', 'ratebridge-woo'),
			'bad_arity' => __('The function is called with the wrong number of arguments.', 'ratebridge-woo'),
			'function_needs_args' => __('A function always needs parentheses, for example min(1, 2).', 'ratebridge-woo'),
			'reserved_name' => __('This name is reserved and cannot be used as a variable.', 'ratebridge-woo'),
			'unknown_variable' => __('The expression uses a variable that is not defined.', 'ratebridge-woo'),
			'division_by_zero' => __('The expression divides by zero.', 'ratebridge-woo'),
			'negative_sqrt' => __('The square root of a negative number is not defined.', 'ratebridge-woo'),
			'not_finite' => __('The result is not a finite number.', 'ratebridge-woo'),
			'invalid_decimals' => __('The number of decimals must be a whole number between -10 and 10.', 'ratebridge-woo'),
			'invalid_range' => __('clamp() needs a minimum that is not greater than the maximum.', 'ratebridge-woo'),
			'chained_comparison' => __('Chained comparisons are not allowed. Write them with “and”, for example (1 < 2) and (2 < 3).', 'ratebridge-woo'),
		);

		return $messages[ $code ] ?? __('The formula is not valid.', 'ratebridge-woo');
	}

	/**
	 * Serialisable formula payload for JS.
	 *
	 * @param FormulaModel $formula Formula.
	 * @return array<string, mixed>
	 */
	private function formula_payload(FormulaModel $formula): array
	{
		return array_merge(
			$formula->to_array(),
			array(
				'products' => (new FormulaRepository())->count_products_using($formula->id),
			)
		);
	}

	/**
	 * Import/Export tab bootstrap data (download URLs, backups, unit).
	 *
	 * @return void
	 */
	private function export_info(): void
	{
		$importer = new Importer();

		wp_send_json_success(
			array(
				'package_url' => AdminController::package_url(),
				'products_url' => AdminController::products_url(),
				'store_unit' => Settings::store_unit(),
				'unit_label' => SettingsPage::unit_label(),
				'sections' => array(
					'settings' => __('Settings', 'ratebridge-woo'),
					'currencies' => __('Currencies', 'ratebridge-woo'),
					'formulas' => __('Formulas', 'ratebridge-woo'),
				),
				'backups' => $importer->backup_summaries(),
			)
		);
	}

	/**
	 * Validate an uploaded JSON package and return the dry-run report.
	 *
	 * @return void
	 */
	private function import_package_dry_run(): void
	{
		$file = $this->read_uploaded_json();

		if (is_wp_error($file)) {
			wp_send_json_error(array('message' => $file->get_error_message()), 400);
		}

		$validated = Package::validate_json((string) $file);

		if (! $validated['ok']) {
			wp_send_json_error(
				array(
					'message' => $this->package_error_message($validated['error']->code),
					'code' => $validated['error']->code,
				),
				400
			);
		}

		$policy = ImportPlan::policy(isset($_POST['policy']) ? sanitize_key(wp_unslash((string) $_POST['policy'])) : ImportPlan::POLICY_SKIP);
		$convert = ! isset($_POST['convert']) || '1' === sanitize_text_field(wp_unslash((string) $_POST['convert']));

		$plan = (new Importer())->analyse($validated['data'], $policy, $convert);

		// The already-validated payload is stored server-side; the browser only
		// gets an opaque token.
		$token = $this->store_import_token(
			array(
				'kind' => 'package',
				'data' => $validated['data'],
				'policy' => $policy,
				'convert' => $convert,
			)
		);

		$counts = array();

		foreach ((array) ($plan['counts'] ?? array()) as $section => $values) {
			$counts[$section] = array(
				'create' => (int) ($values['create'] ?? 0),
				'update' => (int) ($values['update'] ?? 0),
				'skip' => (int) ($values['skip'] ?? 0),
				'invalid' => (int) ($values['invalid'] ?? 0),
			);
		}

		wp_send_json_success(
			array(
				'message' => __('Validation finished. Review the report, then confirm the import.', 'ratebridge-woo'),
				'token' => $token,
				'counts' => $counts,
				'messages' => array_slice($this->normalise_messages((array) ($plan['messages'] ?? array())), 0, 50),
				'sample' => array_slice((array) ($plan['sample'] ?? array()), 0, 5),
				'meta' => (array) ($plan['meta'] ?? array()),
				'unit_label' => SettingsPage::unit_label(),
			)
		);
	}

	/**
	 * Execute a previously validated package import.
	 *
	 * @return void
	 */
	private function import_package_execute(): void
	{
		$payload = $this->consume_import_token(isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '');

		if (null === $payload || 'package' !== ($payload['kind'] ?? '')) {
			wp_send_json_error(array('message' => __('The import session expired. Please upload the file again.', 'ratebridge-woo')), 400);
		}

		// Re-validate from scratch: the stored payload is trusted only after this.
		$validated = Package::validate($payload['data'] ?? array());

		if (! $validated['ok']) {
			wp_send_json_error(array('message' => $this->package_error_message($validated['error']->code)), 400);
		}

		$importer = new Importer();
		$plan = $importer->analyse(
			$validated['data'],
			(string) ($payload['policy'] ?? ImportPlan::POLICY_SKIP),
			(bool) ($payload['convert'] ?? true)
		);

		$result = $importer->execute($plan);

		// Reprice everything the import could have touched.
		$job = new \RateBridge\Pricing\RepriceJob();

		foreach ((array) ($result['currencies'] ?? array()) as $currency_id) {
			$currency = (new CurrencyRepository())->get((int) $currency_id);

			if ($currency instanceof Currency) {
				$job->enqueue($currency->code);
			}
		}

		foreach ((array) ($result['formulas'] ?? array()) as $formula_id) {
			$job->enqueue_formula((int) $formula_id);
		}

		$currencies = new CurrencyRepository();
		$pending = array();

		foreach ((array) ($result['currencies'] ?? array()) as $currency_id) {
			$currency = $currencies->get((int) $currency_id);

			if ($currency instanceof Currency && ! $currency->has_rate()) {
				$pending[] = $currency->code;
			}
		}

		$counts = (array) ($result['counts'] ?? array());

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: created, 2: updated, 3: invalid */
					__('Import finished: %1$d created, %2$d updated, %3$d invalid. Prices are being recalculated in the background.', 'ratebridge-woo'),
					(int) ($counts['create'] ?? 0),
					(int) ($counts['update'] ?? 0),
					(int) ($counts['invalid'] ?? 0)
				),
				'counts' => $counts,
				'messages' => array_slice($this->normalise_messages((array) ($result['messages'] ?? array())), 0, 50),
				'pending_currencies' => $pending,
				'backups' => $importer->backup_summaries(),
			)
		);
	}

	/**
	 * Stored backups.
	 *
	 * @return void
	 */
	private function list_backups(): void
	{
		wp_send_json_success(array('backups' => (new Importer())->backup_summaries(), 'expires_in_minutes' => 60));
	}

	/**
	 * Restore the newest backup (or a given id).
	 *
	 * @return void
	 */
	private function restore_backup(): void
	{
		$id = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';
		$importer = new Importer();
		$result = $importer->restore($id);

		if (null === $result) {
			wp_send_json_error(array('message' => __('The backup could not be restored.', 'ratebridge-woo')), 400);
		}

		$job = new \RateBridge\Pricing\RepriceJob();

		foreach ((array) ($result['currencies'] ?? array()) as $currency_id) {
			$currency = (new CurrencyRepository())->get((int) $currency_id);

			if ($currency instanceof Currency) {
				$job->enqueue($currency->code);
			}
		}

		foreach ((array) ($result['formulas'] ?? array()) as $formula_id) {
			$job->enqueue_formula((int) $formula_id);
		}

		wp_send_json_success(
			array(
				'message' => __('The backup was restored. Prices are being recalculated in the background.', 'ratebridge-woo'),
				'backups' => $importer->backup_summaries(),
			)
		);
	}

	/**
	 * Validate an uploaded products CSV and create a chunked session.
	 *
	 * @return void
	 */
	private function import_products_csv(): void
	{
		if (! current_user_can('edit_products')) {
			wp_send_json_error(array('message' => __('You are not allowed to do this.', 'ratebridge-woo')), 403);
		}

		if (! isset($_FILES['file']) || ! is_array($_FILES['file'])) {
			wp_send_json_error(array('message' => __('Choose a CSV file first.', 'ratebridge-woo')), 400);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$file = $_FILES['file'];
		$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

		if (UPLOAD_ERR_OK !== $error) {
			wp_send_json_error(array('message' => __('The upload failed. Check the file size and try again.', 'ratebridge-woo')), 400);
		}

		if (! is_uploaded_file((string) $file['tmp_name'])) {
			wp_send_json_error(array('message' => __('The upload could not be verified.', 'ratebridge-woo')), 400);
		}

		if ((int) $file['size'] > ProductCsv::MAX_BYTES) {
			wp_send_json_error(array('message' => __('The file is larger than 5 MB.', 'ratebridge-woo')), 400);
		}

		$name = sanitize_file_name((string) ($file['name'] ?? ''));

		if (! str_ends_with(strtolower($name), '.csv')) {
			wp_send_json_error(array('message' => __('Only .csv files are accepted.', 'ratebridge-woo')), 400);
		}

		$unit = isset($_POST['file_unit']) ? Package::unit(sanitize_key(wp_unslash((string) $_POST['file_unit']))) : Settings::store_unit();

		$service = new ProductsCsvService();
		$session = $service->prepare_session((string) $file['tmp_name'], $unit);

		if (null === $session) {
			wp_send_json_error(array('message' => __('The CSV file could not be read. Check the encoding (UTF-8) and the size.', 'ratebridge-woo')), 400);
		}

		if (isset($session['error'])) {
			$message = 'missing_columns' === $session['error']
				? sprintf(
					/* translators: %s: comma separated column list */
					__('Required columns are missing: %s', 'ratebridge-woo'),
					implode(', ', (array) ($session['missing'] ?? array()))
				)
				: __('The CSV needs a “mode” column and an identifier column (“id” or “sku”).', 'ratebridge-woo');

			wp_send_json_error(array('message' => $message, 'code' => $session['error']), 400);
		}

		/*
		 * The parsed rows go to a private JSONL file, never into a transient:
		 * 20,000 rows would bloat wp_options and risk max_allowed_packet. Only
		 * the session metadata stays in the transient.
		 */
		if (! $service->is_tmp_writable()) {
			wp_send_json_error(
				array('message' => __('The upload folder is not writable, so the import cannot start. Check the permissions of wp-content/uploads.', 'ratebridge-woo')),
				500
			);
		}

		$token = ProductsCsvService::new_token();
		$row_file = $service->row_file($token);

		if (null === $row_file) {
			wp_send_json_error(array('message' => __('The import session could not be created.', 'ratebridge-woo')), 500);
		}

		$written = $row_file->write((array) $session['rows']);

		if (! $written['ok']) {
			$row_file->delete();

			wp_send_json_error(
				array('message' => $this->row_file_error_message((string) $written['error'])),
				500
			);
		}

		$total = (int) $written['rows'];
		$first = $row_file->read(0, ProductCsv::IMPORT_CHUNK);

		$meta = array(
			'kind' => 'csv',
			'file' => basename($row_file->path()),
			'map' => $session['map'],
			'file_unit' => $session['file_unit'],
			'delimiter' => $session['delimiter'],
			'total' => $total,
			'offset' => (int) $first['next'],
		);

		$plan = $service->plan_chunk(
			(array) $first['rows'],
			(array) $session['map'],
			(string) $session['file_unit'],
			0
		);

		$this->store_import_token($meta, $token);

		wp_send_json_success(
			array(
				'message' => __('The file was read. Review the result, then start the import.', 'ratebridge-woo'),
				'token' => $token,
				'rows' => $total,
				'chunk' => ProductCsv::IMPORT_CHUNK,
				'delimiter' => $session['delimiter'],
				'file_unit' => $session['file_unit'],
				'store_unit' => Settings::store_unit(),
				'dry_run' => array(
					'valid' => (int) $plan['valid'],
					'invalid' => (int) $plan['invalid'],
					'messages' => array_slice((array) $plan['messages'], 0, 50),
					'sample' => array_slice((array) $plan['sample'], 0, 5),
				),
			)
		);
	}

	/**
	 * Apply one chunk of a validated products CSV session.
	 *
	 * @return void
	 */
	private function import_products_chunk(): void
	{
		if (! current_user_can('edit_products')) {
			wp_send_json_error(array('message' => __('You are not allowed to do this.', 'ratebridge-woo')), 403);
		}

		$payload = $this->consume_import_token(
			isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '',
			false
		);

		if (null === $payload || 'csv' !== ($payload['kind'] ?? '')) {
			wp_send_json_error(array('message' => __('The import session expired. Please upload the file again.', 'ratebridge-woo')), 400);
		}

		$service = new ProductsCsvService();
		$row_file = $service->row_file((string) ($_POST['token'] ?? ''));

		if (null === $row_file) {
			wp_send_json_error(array('message' => __('The import session expired. Please upload the file again.', 'ratebridge-woo')), 400);
		}

		// Byte offset of the next unread row (0 on the first call).
		$offset = isset($_POST['offset']) ? absint($_POST['offset']) : (int) ($payload['offset'] ?? 0);
		$total = (int) ($payload['total'] ?? 0);
		$chunk = $row_file->read($offset, ProductCsv::IMPORT_CHUNK);

		$rows_read_before = max(0, (int) ($payload['rows_done'] ?? 0));
		$plan = $service->plan_chunk((array) $chunk['rows'], (array) ($payload['map'] ?? array()), (string) ($payload['file_unit'] ?? 'toman'), $rows_read_before);
		$touched = $service->apply_chunk((array) $plan['apply']);

		if (! empty($touched)) {
			(new \RateBridge\Pricing\RepriceJob())->enqueue_ids($touched);
		}

		$rows_done = $rows_read_before + (int) $chunk['read'];
		$next = (int) $chunk['next'];
		$done = (bool) $chunk['eof'];

		// Keep the session metadata fresh so the next chunk knows where it is.
		$payload['offset'] = $next;
		$payload['rows_done'] = $rows_done;
		$this->store_import_token($payload, (string) ($_POST['token'] ?? ''), false);

		if ($done || 0 === (int) $chunk['read']) {
			$row_file->delete();
			$this->consume_import_token((string) ($_POST['token'] ?? ''), true);

			\RateBridge\Core\Logger::add(
				array(
					'status' => (int) $plan['invalid'] > 0 ? 'error' : 'success',
					'message' => sprintf(
						/* translators: 1: touched products, 2: invalid rows */
						__('Products CSV import finished: %1$d products updated, %2$d rows skipped in the last chunk.', 'ratebridge-woo'),
						count($touched),
						(int) $plan['invalid']
					),
					'currencies' => array(),
					'trigger' => 'import',
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => $done
					? __('Import finished. Prices are being recalculated in the background.', 'ratebridge-woo')
					: __('Chunk applied.', 'ratebridge-woo'),
				'offset' => $next,
				'total' => $total,
				'percent' => $total > 0 ? (int) min(100, round($rows_done / $total * 100)) : 100,
				'done' => $done,
				'valid' => (int) $plan['valid'],
				'invalid' => (int) $plan['invalid'],
				'messages' => array_slice((array) $plan['messages'], 0, 50),
			)
		);
	}

	/**
	 * Read and validate the uploaded JSON file.
	 *
	 * @return string|\WP_Error Raw JSON text.
	 */
	private function read_uploaded_json(): string|\WP_Error
	{
		if (! isset($_FILES['file']) || ! is_array($_FILES['file'])) {
			return new \WP_Error('no_file', __('Choose a JSON file first.', 'ratebridge-woo'));
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$file = $_FILES['file'];

		if (UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
			return new \WP_Error('upload_failed', __('The upload failed. Check the file size and try again.', 'ratebridge-woo'));
		}

		if (! is_uploaded_file((string) $file['tmp_name'])) {
			return new \WP_Error('bad_upload', __('The upload could not be verified.', 'ratebridge-woo'));
		}

		if ((int) $file['size'] > Package::MAX_BYTES) {
			return new \WP_Error('too_large', __('The file is larger than 2 MB.', 'ratebridge-woo'));
		}

		$name = sanitize_file_name((string) ($file['name'] ?? ''));

		if (! str_ends_with(strtolower($name), '.json')) {
			return new \WP_Error('bad_extension', __('Only .json files are accepted.', 'ratebridge-woo'));
		}

		$raw = @file_get_contents((string) $file['tmp_name']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp upload.

		if (! is_string($raw) || '' === $raw) {
			return new \WP_Error('unreadable', __('The uploaded file could not be read.', 'ratebridge-woo'));
		}

		return $raw;
	}

	/**
	 * Persian message for a package validation code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private function package_error_message(string $code): string
	{
		$map = array(
			'too_large' => __('The package is larger than 2 MB.', 'ratebridge-woo'),
			'not_utf8' => __('The package is not valid UTF-8.', 'ratebridge-woo'),
			'invalid_json' => __('The file is not valid JSON.', 'ratebridge-woo'),
			'too_deep' => __('The JSON is nested too deeply.', 'ratebridge-woo'),
			'not_an_object' => __('The package must be a JSON object.', 'ratebridge-woo'),
			'bad_format' => __('This is not a RateBridge package.', 'ratebridge-woo'),
			'bad_schema' => __('The package schema is not recognised.', 'ratebridge-woo'),
			'unsupported_schema' => __('The package was created by a newer version of the plugin. Update the plugin first.', 'ratebridge-woo'),
			'no_sections' => __('The package contains no settings, currencies or formulas.', 'ratebridge-woo'),
			'bad_section' => __('A package section is malformed.', 'ratebridge-woo'),
		);

		return $map[ $code ] ?? __('The package is not valid.', 'ratebridge-woo');
	}

	/**
	 * Store an import payload for the confirm step (1 hour, bound to the user).
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return string Token.
	 */
	private function store_import_token(array $payload, string $token = ''): string
	{
		if ('' === $token) {
			$token = wp_generate_password(32, false, false);
		}

		set_transient(
			'rbw_import_' . get_current_user_id() . '_' . $token,
			$payload,
			HOUR_IN_SECONDS
		);

		return $token;
	}

	/**
	 * Persian message for a row-file write failure.
	 *
	 * @param string $code RowFile error code.
	 * @return string
	 */
	private function row_file_error_message(string $code): string
	{
		$messages = array(
			'not_writable' => __('The upload folder is not writable, so the import cannot start.', 'ratebridge-woo'),
			'write_failed' => __('The import file could not be written.', 'ratebridge-woo'),
			'too_many_rows' => __('The file has more rows than the importer allows (20,000).', 'ratebridge-woo'),
		);

		return $messages[ $code ] ?? __('The import file could not be prepared.', 'ratebridge-woo');
	}

	/**
	 * Read (and optionally delete) a stored import payload.
	 *
	 * @param string $token  Token.
	 * @param bool   $delete Whether to delete it.
	 * @return array<string, mixed>|null
	 */
	private function consume_import_token(string $token, bool $delete = false): ?array
	{
		if ('' === $token || ! preg_match('/^[A-Za-z0-9]+$/', $token)) {
			return null;
		}

		$key = 'rbw_import_' . get_current_user_id() . '_' . $token;
		$payload = get_transient($key);

		if (! is_array($payload)) {
			return null;
		}

		if ($delete) {
			delete_transient($key);
		}

		return $payload;
	}

	/**
	 * Normalise message rows for JSON (level/section/item/message).
	 *
	 * @param array<int, mixed> $messages Messages.
	 * @return array<int, array<string, string>>
	 */
	private function normalise_messages(array $messages): array
	{
		$out = array();

		foreach ($messages as $message) {
			if (! is_array($message)) {
				continue;
			}

			$out[] = array(
				'level' => (string) ($message['level'] ?? 'info'),
				'section' => (string) ($message['section'] ?? ''),
				'item' => (string) ($message['item'] ?? ''),
				'message' => (string) ($message['message'] ?? ''),
			);
		}

		return $out;
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
			'last_error'              => $currency->error_message(),
			'pending_first_update'    => $currency->is_pending_first_update(),
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
