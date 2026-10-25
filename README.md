# RateBridge Woo / نرخ ساز

Define product prices in a foreign currency or commodity (USD, EUR, gold, coins, …) and let
the plugin recalculate the store-currency price (Iranian Toman or Rial) from live rates.

- **Product name (user-facing):** نرخ ساز
- **Slug:** `ratebridge-woo`
- **Text domain:** `ratebridge-woo` (English source strings, Persian `fa_IR` translation shipped)
- **Namespace / prefix:** `RateBridge\` / `rbw_`
- **Requirements:** PHP 8.1+, WordPress 6.4+, WooCommerce 8+ (HPOS compatible)

> **Status – Phase 2.** Currency management, real price sources (Navasan + AlanChand),
> per-provider settings, request-quota protection and full Persian UI.
> Product price fields, price calculation, rounding applied to products, order meta,
> shortcodes and the formula builder are intentionally out of scope for now.

---

## Folder structure

```
ratebridge-woo/
├── ratebridge-woo.php          Main plugin file: header, constants, autoloader, WC guard, hooks
├── uninstall.php               Optional data removal (only when the setting is enabled)
├── README.md
├── .gitignore                  Excludes .env, *.key, *secret*, vendor, build artefacts
├── bin/
│   ├── build-mo.php            Dependency-free .po -> .mo compiler
│   └── i18n-strings.php        Extracts translatable strings (tokenizer based) / --pot
├── tests/
│   └── parse-fixtures.php      CLI parser test against docs/api-samples/ (no WordPress)
├── docs/
│   └── api-samples/            Real API response fixtures (committed, secret-free)
│       ├── navasan-latest.json
│       ├── alanchand-currency.json
│       └── alanchand-gold.json
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
│   │   ├── AbstractProvider.php    HTTP helper: timeouts, cache, quota gate, secret scrubber
│   │   ├── ProviderRegistry.php    `rbw_register_providers` filter, lookup by key
│   │   ├── RequestBudget.php       Per-provider quota guard (monthly + interval + backoff)
│   │   ├── ManualProvider.php      Rates typed by the administrator (never auto-updated)
│   │   ├── MockProvider.php        Deterministic fake rates for development/testing
│   │   ├── NavasanProvider.php     Real: api.navasan.tech
│   │   ├── AlanChandProvider.php   Real: api.alanchand.com
│   │   └── Parsing/
│   │       ├── NumberParser.php    Messy strings -> positive finite floats (WP-free)
│   │       ├── NavasanParser.php   Pure parser for the Navasan payload (WP-free)
│   │       └── AlanChandParser.php Pure parser for the AlanChand payloads (WP-free)
│   ├── Pricing/
│   │   └── RateUpdater.php     Chooses due currencies, batches per provider, stores rates
│   ├── Cron/
│   │   └── Scheduler.php       `rbw_every_five_minutes` schedule + `rbw_update_rates` event
│   └── Admin/
│       ├── SettingsPage.php      WooCommerce → نرخ ساز (3 tabs, assets, RTL)
│       ├── AjaxController.php    admin-ajax endpoints (nonce + manage_woocommerce)
│       ├── ProviderSettings.php  Provider schema -> UI model, saving, masking, test fetch
│       └── AdminNotices.php      "WooCommerce is required" notice
├── templates/
│   ├── layout.php              Page shell with the tab navigation
│   ├── tab-currencies.php      Currency table, add/edit dialog, presets, update log
│   ├── tab-sources.php         Provider cards: settings, quota display, test connection
│   └── tab-general.php         Store unit, rounding, default interval, uninstall
├── assets/admin/
│   ├── css/admin.css           Minimal admin styles (logical properties, no build step)
│   ├── css/admin-rtl.css       RTL overrides, auto-loaded by WP on RTL locales
│   └── js/admin.js             Vanilla JS: tabs, dialog, symbol picker, presets, AJAX
└── languages/
    ├── ratebridge-woo-fa_IR.po Complete Persian translation (222 strings)
    ├── ratebridge-woo-fa_IR.mo Compiled translation
    └── README.md               How to regenerate translations
```

There is **no Composer and no JS build step**. A small `spl_autoload_register()` maps
`RateBridge\Foo\Bar` → `includes/Foo/Bar.php`.

---

## Persian localization

- Plugin header declares `Text Domain: ratebridge-woo` and `Domain Path: /languages`.
  `load_plugin_textdomain()` runs on `init` (see `Core\Plugin::load_textdomain()`).
- English strings stay in the source; `languages/ratebridge-woo-fa_IR.po` holds the Persian
  translation and `ratebridge-woo-fa_IR.mo` is the compiled catalogue.
- JS strings are passed through `wp_localize_script( 'rbw-admin', 'rbwAdmin', ... )`, so they
  are translated server-side by the same domain.
- **Rebuilding the `.mo`** after editing the `.po`:

```bash
php bin/build-mo.php                        # compiles every languages/*.po
php bin/build-mo.php languages/foo.po       # or a single file
```

`bin/build-mo.php` is dependency-free (writes the GNU MO little-endian layout directly) and
warns about untranslated entries. If `msgfmt` is available you can also use:

```bash
msgfmt languages/ratebridge-woo-fa_IR.po -o languages/ratebridge-woo-fa_IR.mo
```

To list all translatable strings (or produce a `.pot` skeleton):

```bash
php bin/i18n-strings.php
php bin/i18n-strings.php --pot > languages/ratebridge-woo.pot
```

Terminology used consistently: ارز (currency), نرخ (rate), منبع قیمت (provider), فعال (enabled),
به‌روزرسانی همین حالا (update now), به‌روزرسانی همه (update all), افزودن ارز (add currency),
آخرین به‌روزرسانی (last update), بازه به‌روزرسانی (interval), گزارش (log), تنظیمات (settings),
کلید API (API key), تست اتصال (test connection), گرد کردن (rounding), تومان/ریال, طلا (gold), سکه (coin).

RTL layout: `assets/admin/css/admin.css` uses logical properties
(`margin-block`, `border-inline-start`), and `admin-rtl.css` is registered with
`wp_style_add_data( 'rbw-admin', 'rtl', 'replace' )` so WordPress loads it automatically on
`fa_IR`. The tab row is a flex row (no floats), the info line drops to its own line, and
Latin/numeric fragments (`dir="ltr"`) are wrapped in `unicode-bidi: isolate` so trailing
punctuation cannot jump sides.

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
- **Batching:** `RateUpdater` groups all due currencies by provider key and calls
  `fetch_rates()` **once per provider per cycle**. Navasan returns the whole catalogue in one
  request; AlanChand makes at most one request per catalogue type (currency/gold/crypto).
- **Interval floor:** if a row's `update_interval_minutes` is shorter than the provider's
  effective minimum, `RateUpdater::is_due_for_provider()` skips it until the provider's own
  minimum has elapsed, so the cron tick never hammers the API.
- On any failure the **previous rate is kept**, `last_error` is stored, and a rate of
  `0`, `null`, `NAN` or a negative number is never written (`CurrencyRepository::update_rate()`
  rejects them).
- Every attempt is appended to `rbw_update_log` (max 20 entries, newest first) and shown in
  the "Last 20 update attempts" table.
- Deactivation clears the event; `uninstall.php` deletes data only when the setting is on.

---

## Request budget (quota protection)

Free tiers are small — Navasan ≈120 requests/month, AlanChand test plan ≈1 request/hour — so
`Providers/RequestBudget.php` guards every real HTTP request. State lives in the
non-autoloaded option `rbw_request_budget`:

```php
array(
    'navasan' => array(
        'last_request' => 1792945862, // unix ts of the last real request
        'last_status'  => 200,        // last HTTP status (0 = transport error)
        'count'        => 42,         // requests in this period
        'period'       => '2026-10',  // month key the count belongs to
        'last_failure' => 0,          // unix ts of the last failure
        'last_error'   => '',         // scrubbed message
    ),
)
```

Rules:

1. **Before** any `wp_remote_get()`, `AbstractProvider::request()` calls
   `RequestBudget::authorize()`. If the monthly limit is exhausted or the minimum interval has
   not elapsed, the request is **not sent** and a Persian `WP_Error` is returned; `RateUpdater`
   keeps the previous rate and records `last_error`.
2. **After** the request returns (success *or* HTTP error), `RequestBudget::record()` increments
   the counter. **Cache hits never consume quota** — the transient is checked first.
3. The monthly counter **resets automatically** when the calendar month changes
   (`current_period()` is compared on every read); no cron job is needed.
4. **Failure back-off:** after a failed request the provider is not contacted again for
   `RequestBudget::FAILURE_BACKOFF_MINUTES` (15) minutes, so a wrong key cannot burn the quota
   on every 5-minute tick.
5. **A manual "update now"/"test connection" is also budgeted.** The cache is only bypassed
   when the budget still allows a real call.
6. **Effective minimum interval** = `max(configured interval, ceil(minutes_in_month / monthly_limit))`.
   For Navasan's 120/month that is ≈366 minutes; for AlanChand's 60-minute setting it is 60.
   The Price sources tab shows both numbers, and the currency dialog warns when a row asks for
   a shorter interval than the source permits.

Secrets never appear in user-facing text: `AbstractProvider::scrub_secrets()` replaces every
`password`-type setting value (raw, urlencoded and rawurlencoded) with `***` and rewrites
`api_key=`/`token=`/`authorization=` query parameters before any message is stored or shown.

---

## Price sources (providers)

| Key | Label | Endpoint | Auth | Units |
| --- | --- | --- | --- | --- |
| `manual` | Manual (typed by admin) | – | – | entered by admin |
| `mock` | Mock / test data | – | – | deterministic fake |
| `navasan` | Navasan | `GET https://api.navasan.tech/latest/?api_key=KEY` | query `api_key` | Toman |
| `alanchand` | AlanChand | `GET https://api.alanchand.com?type={currency\|gold\|crypto}&symbols=a,b` | `Authorization: Bearer TOKEN` | Toman |

**No scraping is performed** — only the official JSON APIs.

### What the real samples showed

`docs/api-samples/navasan-latest.json` — a **flat map keyed by item name** (302 keys), not a list:

```json
"usd":  { "value": "270000", "change": 1800, "timestamp": 1792945862, "date": "1405-08-03 20:01:02" }
```

- `value` is a **string** for every numeric item; prices are in **Toman**.
- Variants are separate keys (`usd_buy`, `usd_sell`), so a plain `usd` plus variants coexist.
- `bub_abshodeh` has **no `value` key at all** (only `change_val`/`change_pct`) — must be skipped.
- `usd_xau` / `xag`-style keys are quoted in **USD per ounce**, while bare `xau` / `xag` are Toman.
- `bub_*` (bubble metrics), `mob_*` (stale) and `mex_*` (Mexico) are excluded.

`docs/api-samples/alanchand-currency.json` — a **flat map keyed by slug** (44 items):

```json
"usd": { "slug": "usd", "name": "دلار آمریکا", "sell": 270900, "buy": 268200, "dolar_rate": 1, "open": 268900, "high": 272200, "low": 268200, "dayChange": 0.74, "updated_at": "2026-10-25 20:20:06" }
```

`docs/api-samples/alanchand-gold.json` — 12 items; 7 are Toman-priced with a **`price`** field
(`abshodeh`, `18ayar`, `sekkeh`, `bahar`, `nim`, `rob`, `sek`) and 5 are **USD per ounce**
(`usd_xau`, `xag`, `xpt`, `xpd`, `xrh`) which are deliberately excluded.

AlanChand prices are **numbers** (not strings) and in **Toman**. Currency items expose
`sell`/`buy`; gold items expose `price`.

### Unit conversion

Both real sources quote **Toman**. `AbstractProvider::from_toman()` converts into the store's
configured unit:

| Store unit | Result |
| --- | --- |
| Toman | value as is |
| Rial | `value * 10` (1 Toman = 10 Rial) |

`rial_factor()` is the inverse (store amount → Rial): `1.0` for a Rial store, `10.0` for a Toman
store. Use `from_toman()` for provider output; it never returns `0` for invalid input.

### Symbol overrides

An AlanChand currency row's `provider_symbol` may be written as:

- `usd` — bare slug, resolved through the built-in slug → type map
- `gold:sekkeh` — explicit catalogue type
- `currency:usd`, `crypto:btc` — also accepted

Unknown bare slugs fall back to `currency`.

AlanChand symbols are stored **typed** (`currency:usd`, `gold:sekkeh`, `gold:sek`) because `sek`
exists in both catalogues (Swedish krona vs gram coin).

### Unit multipliers (Navasan)

Navasan quotes **coins and melted gold in thousand Toman**, everything else in real Toman.
`NavasanParser::UNIT_MULTIPLIERS` applies `x1000` to `sekkeh`, `bahar`, `nim`, `rob`, `gerami`
and `abshodeh` (including their `_buy` / `_sell` variants). Evidence from the fixtures:
`sekkeh` is `267000` on Navasan vs `267,000,000` on AlanChand, and the same x1000 holds for the
other five. Currencies, `18ayar`, `xau`/`xag` and crypto pairs are unchanged.

### Request format (AlanChand)

The real, verified endpoints are:

```
https://api.alanchand.com/?type=currencies&token=TOKEN
https://api.alanchand.com/?type=golds&token=TOKEN
https://api.alanchand.com/?type=crypto&token=TOKEN
```

The token travels in the **query string**, `type` is **plural**, and there is **no `symbols`
parameter** — the whole catalogue is fetched once per type and cached. Because the token is in
the URL, `scrub_secrets()` must strip it from every message (covered by
`tests/scrub-secrets.php`). The query args can be replaced with the
`rbw_alanchand_request_args` filter.

### Tests

Pure parsers live in `includes/Providers/Parsing/` with **no WordPress dependency**, so they can
be tested from the CLI against the real fixtures:

```bash
php tests/parse-fixtures.php    # parsing + cross-source consistency (79 assertions)
php tests/ajax-actions.php      # JS request() slugs vs registered wp_ajax hooks (67)
php tests/scrub-secrets.php     # credentials never leak into messages (11)
```

Each prints `PASS`/`FAIL` per assertion and exits non-zero on failure.

`tests/ajax-actions.php` guards the Phase-2 regression where `admin.js` sent `action=rbw_save`
while the server only hooked `wp_ajax_rbw_save_currency`, so admin-ajax.php answered a bare `0`.
`AjaxController::actions()` is now the single source of truth for the dispatcher slugs.

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
                'type'        => 'password',   // text|password|number|checkbox|select
                'required'    => true,
                'description' => __( 'Issued by the provider panel.', 'ratebridge-woo' ),
                'hint'        => __( 'Copy it from your account panel.', 'ratebridge-woo' ),
            ),
            array(
                'key'         => 'min_interval',
                'label'       => __( 'Minimum minutes between requests', 'ratebridge-woo' ),
                'type'        => 'number',
                'required'    => false,
                'default'     => 60,
                'min'         => 5,
            ),
        );
    }

    // Required: the preset catalogue shown in the admin UI (symbol => Persian label).
    // Return an empty array to force the free-text symbol field.
    public function get_known_symbols(): array {
        return array(
            'usd' => __( 'US Dollar', 'ratebridge-woo' ),
            'eur' => __( 'Euro', 'ratebridge-woo' ),
        );
    }

    // Optional: tiny safe set used by the "Test connection" button.
    public function get_test_symbols(): array {
        return array( 'usd' );
    }

    // Optional: required for the quota guard to protect your plan.
    public function get_min_interval_minutes(): int {
        return max( 5, (int) $this->get_setting( 'min_interval', 60 ) );
    }

    public function get_monthly_limit(): int {
        return 0;  // 0 = unlimited
    }

    // Required by AbstractProvider: turn the raw body into symbol => rate.
    protected function parse_response( string $body, array $args = array() ): array|WP_Error {
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || empty( $data['rates'] ) ) {
            return new WP_Error( 'rbw_bad_response', __( 'Unexpected response.', 'ratebridge-woo' ) );
        }

        $rates = array();

        foreach ( (array) ( $args['symbols'] ?? array() ) as $symbol ) {
            $value = \RateBridge\Providers\Parsing\NumberParser::to_positive_float( $data['rates'][ $symbol ] ?? null );

            if ( null !== $value ) {
                $rates[ $symbol ] = $this->from_toman( $value );  // if the API quotes Toman
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
  Use `from_toman()` if the source quotes Toman; `rial_factor()` converts store → Rial.
- `fetch_rates()` must return `array( 'USD' => 600000.0 )` or a `WP_Error`.
  Never throw, never return `0`/negative/non-finite values — `sanitize_rates()` drops them.
- **Never** build user-facing error text from a raw URL or a secret. Route it through
  `scrub_secrets()`; `request()` already does this for HTTP errors and parser errors.
- Use `AbstractProvider::request()` — it handles timeouts (15 s), HTTP status codes,
  `WP_Error` conversion, transient caching **and the request-quota gate**.
- Declare `get_min_interval_minutes()` and `get_monthly_limit()` so `RequestBudget` can protect
  your plan; otherwise the guard has nothing to enforce.
- Provider settings are saved in the non-autoloaded `rbw_provider_settings` option, keyed by
  provider key (`get_setting()` / `save_settings()`). `save_settings()` also flushes the cache.
- Never scrape HTML pages; use documented JSON APIs.
- If you want CLI tests, put your parsing logic in a WordPress-free class under
  `includes/Providers/Parsing/` and add assertions to `tests/parse-fixtures.php`.

---

## Security

- Every AJAX call checks `check_ajax_referer( 'rbw_admin', 'nonce' )` **and**
  `current_user_can( 'manage_woocommerce' )` centrally in `AjaxController::handle()`.
- All DB access goes through `$wpdb->prepare()` / `$wpdb->insert()` / `$wpdb->update()` with
  explicit formats; identifiers are only ever built from `$wpdb->prefix`.
- Input is sanitised (`sanitize_key`, `sanitize_text_field`, `absint`, per-field-type whitelists),
  output is escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode`).
- **Secrets:**
  - `password`-type settings are never echoed back into HTML — only `••••••••` + last 4 chars.
  - Submitting an empty password keeps the stored value.
  - Provider settings and the request budget live in **non-autoloaded** options.
  - `AbstractProvider::scrub_secrets()` strips every secret (raw/urlencoded) and rewrites
    `api_key=` / `token=` / `authorization=` query parameters to `***` before any message is
    logged, returned to JS, or rendered.
  - Transient cache keys are hashes, so keys never appear in `wp_options` names.
- All strings use the `ratebridge-woo` text domain.
- `.gitignore` excludes `.env`, `*.key`, `*.pem`, `*secret*`, `*credentials*` and `/keys/`.
  The committed `docs/api-samples/*.json` fixtures are secret-free.

---

## Uninstall

`uninstall.php` returns immediately unless **WooCommerce → نرخ ساز → تنظیمات عمومی →
حذف تمام داده‌ها هنگام حذف افزونه** is enabled. When enabled it drops the currencies table and
 deletes `rbw_settings`, `rbw_db_version`, `rbw_update_log`, `rbw_provider_settings`,
`rbw_request_budget` and all `_transient_rbw_*` rows.
