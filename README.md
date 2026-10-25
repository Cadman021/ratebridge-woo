# RateBridge Woo

Define product prices in a foreign currency or commodity (USD, EUR, gold, coins, …) and let
the plugin recalculate the store-currency price (Iranian Toman or Rial) from live rates.

- **Slug:** `ratebridge-woo`
- **Text domain:** `ratebridge-woo` (English source strings, Persian translations added later)
- **Namespace / prefix:** `RateBridge\` / `rbw_`
- **Requirements:** PHP 8.1+, WordPress 6.4+, WooCommerce 8+ (HPOS compatible)

> **Status – Phase 1.** Foundation and currency management only.
> Product price fields, price calculation, rounding applied to products, order meta, shortcodes
> and the formula builder are intentionally out of scope for this version.

---

## Folder structure

```
ratebridge-woo/
├── ratebridge-woo.php          Main plugin file: header, constants, autoloader, WC guard, hooks
├── uninstall.php               Optional data removal (only when the setting is enabled)
├── README.md
├── includes/
│   ├── Core/
│   │   ├── Plugin.php          Singleton bootstrap; wires cron, AJAX and admin page
│   │   ├── Installer.php       dbDelta table creation, default options, seeding, upgrades
│   │   ├── Settings.php        Typed getters/setters over the `rbw_settings` option
│   │   └── Logger.php          Last 20 update attempts stored in `rbw_update_log`
│   ├── Currency/
│   │   ├── Currency.php        Immutable value object + `from_row()` + `is_due()`
│   │   └── CurrencyRepository.php  All DB access (CRUD, get_enabled, update_rate, set_error)
│   ├── Providers/
│   │   ├── ProviderInterface.php   The contract every rate source must implement
│   │   ├── AbstractProvider.php    HTTP helper with timeouts, WP_Error handling, transient cache
│   │   ├── ProviderRegistry.php    `rbw_register_providers` filter, lookup by key
│   │   ├── ManualProvider.php      Rates typed by the administrator (never auto-updated)
│   │   └── MockProvider.php        Deterministic fake rates for development/testing
│   ├── Pricing/
│   │   └── RateUpdater.php     Chooses due currencies, calls providers, stores rates safely
│   ├── Cron/
│   │   └── Scheduler.php       `rbw_every_five_minutes` schedule + `rbw_update_rates` event
│   └── Admin/
│       ├── SettingsPage.php    WooCommerce → Currency Prices (tabs, assets, RTL)
│       ├── AjaxController.php  admin-ajax endpoints (nonce + manage_woocommerce)
│       └── AdminNotices.php    "WooCommerce is required" notice
├── templates/
│   ├── layout.php              Page shell with the tab navigation
│   ├── tab-currencies.php      Currency table, add/edit dialog, update log
│   └── tab-general.php         Store unit, rounding, default interval, uninstall
├── assets/admin/
│   ├── css/admin.css           Minimal admin styles (no build step)
│   ├── css/admin-rtl.css       RTL overrides, auto-loaded by WP on RTL locales
│   └── js/admin.js             Vanilla JS: toggles, dialog, AJAX, log refresh
└── languages/                  .pot/.po/.mo files (Persian translation comes later)
```

There is **no Composer and no JS build step**. A small `spl_autoload_register()` maps
`RateBridge\Foo\Bar` → `includes/Foo/Bar.php`.

---

## Data model

Table `{prefix}rbw_currencies`:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `code` | varchar(32), UNIQUE | e.g. `USD`, `GOLD18` |
| `name` | varchar(190) | display name (translatable at seed time) |
| `symbol` | varchar(32) | `$`, `€`, `g` … |
| `provider` | varchar(64) | provider key (`manual`, `mock`, …) |
| `provider_symbol` | varchar(64) | identifier the provider understands (falls back to `code`) |
| `rate` | decimal(30,10) | store-currency units per **1** unit; always > 0 or 0 = "no rate yet" |
| `is_enabled` | tinyint(1) | only enabled currencies are refreshed |
| `update_interval_minutes` | int | clamped to the 5-minute minimum |
| `last_updated` | datetime (UTC) | last **successful** fetch |
| `last_error` | text | last provider error, cleared on success |
| `sort_order` | int | admin ordering |
| `created_at` / `updated_at` | datetime (UTC) | |

On activation four currencies are seeded **disabled**: `USD`, `EUR`, `AED`, `GOLD18`
(18K gold gram) using the `mock` provider, so you can enable and test them immediately.

Settings are stored in a single option, `rbw_settings`:

```php
array(
    'store_currency_unit'      => 'toman',    // rial | toman
    'rounding_mode'            => 'nearest',  // none | nearest | ceil | floor
    'rounding_step'            => 1000,       // N
    'default_update_interval'  => 60,         // minutes
    'delete_data_on_uninstall' => false,
    'default_provider'         => 'manual',
)
```

---

## Scheduling

- Custom schedule `rbw_every_five_minutes` (300 s). WordPress cron cannot run more often,
  so **5 minutes is the hard minimum** and every interval is clamped to it.
- Main event `rbw_update_rates` → `RateUpdater::update_due()`.
- A currency is due when `now - last_updated >= update_interval_minutes`, or when it has
  never been updated.
- On any failure the **previous rate is kept**, `last_error` is stored, and a rate of
  `0`, `null`, `NAN` or a negative number is never written (`CurrencyRepository::update_rate()`
  rejects them).
- Every attempt is appended to `rbw_update_log` (max 20 entries, newest first) and shown in
  the "Last 20 update attempts" table.
- Deactivation clears the event; `uninstall.php` deletes data only when the setting is on.

---

## Adding a new provider

1. Create `includes/Providers/MyApiProvider.php`:

```php
<?php
namespace RateBridge\Providers;

use WP_Error;

final class MyApiProvider extends AbstractProvider {

    public function get_key(): string {
        return 'my_api';                       // sanitize_key()-safe, unique
    }

    public function get_label(): string {
        return __( 'My API', 'ratebridge-woo' );
    }

    // Optional: which settings the admin must fill in.
    public function get_settings_fields(): array {
        return array(
            array(
                'key'         => 'api_key',
                'label'       => __( 'API key', 'ratebridge-woo' ),
                'type'        => 'password',
                'required'    => true,
                'description' => __( 'Issued by the provider panel.', 'ratebridge-woo' ),
            ),
        );
    }

    // Required by AbstractProvider: turn the raw body into symbol => rate.
    protected function parse_response( string $body, array $args = array() ): array|WP_Error {
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || empty( $data['rates'] ) ) {
            return new WP_Error( 'rbw_bad_response', __( 'Unexpected response.', 'ratebridge-woo' ) );
        }

        $rates = array();

        foreach ( (array) $args['symbols'] as $symbol ) {
            if ( isset( $data['rates'][ $symbol ] ) ) {
                $rates[ $symbol ] = (float) $data['rates'][ $symbol ];
            }
        }

        return $rates;
    }

    // Optional: customise the request (endpoint, query, headers, caching).
    public function fetch_rates( array $symbols ): array|WP_Error {
        return $this->request(
            'https://api.example.com/v1/rates',
            array(
                'query'     => array(
                    'key'    => (string) $this->get_setting( 'api_key' ),
                    'symbols' => implode( ',', $symbols ),
                ),
                'cache_key' => 'rbw_my_api_' . md5( implode( ',', $symbols ) ),
                'cache_ttl' => 5 * MINUTE_IN_SECONDS,
                'parse_args' => array( 'symbols' => $symbols ),
            )
        );
    }
}
```

2. Register it with the filter (from your theme or another plugin):

```php
add_filter( 'rbw_register_providers', function ( array $providers ) {
    $providers[] = new \RateBridge\Providers\MyApiProvider();
    return $providers;
} );
```

Rules for provider implementations:

- Return rates **in the store unit** (Rial or Toman, see `Settings::store_unit()`).
  `AbstractProvider::rial_factor()` helps convert from Rial.
- `fetch_rates()` must return `array( 'USD' => 600000.0 )` or a `WP_Error`.
  Never throw, never return `0`/negative/non-finite values — they are dropped anyway.
- Use `AbstractProvider::request()`; it already handles timeouts (15 s), HTTP status codes,
  `WP_Error` conversion and transient caching.
- Provider settings are saved in the `rbw_provider_settings` option, keyed by provider key
  (`get_setting()` / `save_settings()`).
- Never scrape HTML pages; use documented JSON APIs.

---

## Security

- Every AJAX call checks `check_ajax_referer( 'rbw_admin', 'nonce' )` **and**
  `current_user_can( 'manage_woocommerce' )`.
- All DB access goes through `$wpdb->prepare()` / `$wpdb->insert()` / `$wpdb->update()` with
  explicit formats; identifiers are only ever built from `$wpdb->prefix`.
- Input is sanitised (`sanitize_key`, `sanitize_text_field`, `absint`, whitelists), output is
  escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode`).
- All strings use the `ratebridge-woo` text domain.

---

## Uninstall

`uninstall.php` returns immediately unless **WooCommerce → Currency Prices → General
settings → "Delete all plugin data on uninstall"** is enabled. When enabled it drops the
currencies table and deletes `rbw_settings`, `rbw_db_version`, `rbw_update_log` and all
`_transient_rbw_*` rows.
