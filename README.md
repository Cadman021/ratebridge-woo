# RateBridge Woo / نرخ ساز

Define product prices in a foreign currency or commodity (USD, EUR, gold, coins, …) and let
the plugin recalculate the store-currency price (Iranian Toman or Rial) from live rates.

- **Product name (user-facing):** نرخ ساز
- **Slug:** `ratebridge-woo`
- **Text domain:** `ratebridge-woo` (English source strings, Persian `fa_IR` translation shipped)
- **Namespace / prefix:** `RateBridge\` / `rbw_`
- **Requirements:** PHP 8.1+, WordPress 6.4+, WooCommerce 8+ (HPOS compatible)

> **Status – Phase 3.** Currency management, real price sources (Navasan + AlanChand),
> per-provider settings, request-quota protection, **product pricing by currency**
> (simple + variable products, classic editor), **automatic repricing when a rate
> changes**, store-unit migration and full Persian UI.
>
> Still out of scope: order/cart meta and showing the rate on orders, Elementor widgets,
> shortcodes, the formula builder, bulk/quick edit integration, import/export,
> new providers, Persian-digit price display and the block-editor product form.

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
│   ├── parse-fixtures.php      CLI parser test against docs/api-samples/ (no WordPress)
│   ├── price-calculator.php    CLI test for the calculation engine (no WordPress)
│   ├── ajax-actions.php        JS request() slugs vs registered wp_ajax hooks
│   └── scrub-secrets.php       Credentials never leak into messages
├── docs/
│   └── api-samples/            Real API response fixtures (committed, secret-free)
│       ├── navasan-latest.json
│       ├── alanchand-currency.json
│       └── alanchand-gold.json
├── includes/
│   ├── Core/
│   │   ├── Plugin.php          Singleton bootstrap; wires cron, AJAX, admin page, pricing
│   │   ├── Installer.php       dbDelta table creation, default options, seeding, upgrades
│   │   ├── Settings.php        Typed getters/setters over the `rbw_settings` option
│   │   ├── UnitMigration.php   Toman <-> Rial conversion of every stored amount
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
│   │   ├── RateUpdater.php     Chooses due currencies, batches per provider, stores rates
│   │   ├── PriceCalculator.php Pure calculation engine (no WordPress; CLI-testable)
│   │   ├── Repricer.php        Computes + saves product/variation prices via CRUD
│   │   └── RepriceJob.php      Batched async repricing (Action Scheduler + cron fallback)
│   ├── Product/
│   │   ├── ProductData.php     `_rbw_*` meta keys, sanitisation, rule resolution
│   │   └── ProductFields.php   Classic editor fields (simple + variations), column, AJAX
│   ├── Cron/
│   │   └── Scheduler.php       `rbw_every_five_minutes` schedule + `rbw_update_rates` event
│   └── Admin/
│       ├── SettingsPage.php      WooCommerce → نرخ ساز (3 tabs, assets, RTL)
│       ├── AjaxController.php    admin-ajax endpoints (nonce + manage_woocommerce)
│       ├── ProviderSettings.php  Provider schema -> UI model, saving, masking, test fetch
│       └── AdminNotices.php      "WooCommerce is required" + stale-rate notice
├── templates/
│   ├── layout.php              Page shell with the tab navigation
│   ├── tab-currencies.php      Currency table, add/edit dialog, presets, update log
│   ├── tab-sources.php         Provider cards: settings, quota display, test connection
│   └── tab-general.php         Store unit, rounding, default interval, max rate age, uninstall
├── assets/admin/
│   ├── css/admin.css           Minimal admin styles (logical properties, no build step)
│   ├── css/admin-rtl.css       RTL overrides, auto-loaded by WP on RTL locales
│   ├── css/product.css         Product editor fields (product panel, preview, variations)
│   ├── css/product-rtl.css     RTL overrides for the product editor
│   ├── js/admin.js             Vanilla JS: tabs, dialog, symbol picker, presets, AJAX
│   └── js/product.js           Vanilla JS: panel toggle, read-only prices, live preview
└── languages/
    ├── ratebridge-woo-fa_IR.po Complete Persian translation (330 strings)
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
- JS strings are passed through `wp_localize_script()` (`rbwAdmin` for the plugin page,
  `rbwProduct` for the product editor), so they are translated server-side by the same domain.
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

Phase 3 terminology: قیمت پایه (base price), قیمت ویژه (sale price), سود (profit), کارمزد
(commission), مقدار اضافه (extra amount), حداقل قیمت (minimum price), حاشیه نوسان (fluctuation
margin), قیمت نهایی (final price), پیش‌نمایش قیمت (price preview), غیرفعال / بدون ارز
(disabled / no currency), قیمت‌گذاری با نرخ ارز (pricing by currency), ارز والد (parent
currency), آخرین محاسبه (last calculation), تنظیمات قیمت‌گذاری (pricing settings), حداکثر سن نرخ
(maximum rate age), درصد (percent), مبلغ ثابت (fixed amount).

RTL layout: `assets/admin/css/admin.css` uses logical properties
(`margin-block`, `border-inline-start`) and is **always** loaded, with `admin-rtl.css` added on
top when `is_rtl()` (using `'rtl' => 'replace'` dropped the base rules, see the Phase-2 fix).
The product editor follows the same pattern with `product.css` + `product-rtl.css`. The tab row
is a flex row (no floats), the info line drops to its own line, and Latin/numeric fragments
(`dir="ltr"`) are wrapped in `unicode-bidi: isolate` so trailing punctuation cannot jump sides.

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
| `profit_type` | varchar(16) | `percent` or `fixed` (default `percent`) |
| `profit_value` | decimal(30,10) | profit amount: a percentage (0–1000) or a store-unit amount |
| `commission_type` | varchar(16) | `percent` or `fixed` (default `percent`) |
| `commission_value` | decimal(30,10) | commission amount: a percentage (0–1000) or a store-unit amount |
| `extra_amount` | decimal(30,10) | fixed store-unit amount added to every price |
| `is_enabled` | tinyint(1) | only enabled currencies are refreshed |
| `update_interval_minutes` | int | clamped to the 5-minute minimum |
| `last_updated` | datetime (UTC) | last **successful** fetch |
| `last_error` | text | last provider error, cleared on success |
| `sort_order` | int | admin ordering |
| `created_at` / `updated_at` | datetime (UTC) | |

Schema version `1.1.0` (Phase 3) adds the five pricing columns. `Installer::maybe_upgrade()`
runs `dbDelta()` on load when `rbw_db_version` is older, then back-fills the new columns so
rows created by 1.0.0 behave exactly like freshly seeded ones (idempotent `UPDATE`, safe to
run repeatedly).

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
    'max_rate_age_hours'       => 48,         // 0 = stale-rate protection disabled
)
```

`Settings::store_decimals()` returns `0` for Toman/Rial (filterable with
`rbw_store_decimals`), and `rounding_mode()`/`rounding_step()` feed the calculator.

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
php tests/price-calculator.php  # calculation engine, no WordPress (63 assertions)
php tests/ajax-actions.php      # JS request() slugs vs registered wp_ajax hooks (87)
php tests/scrub-secrets.php     # credentials never leak into messages (11)
```

Each prints `PASS`/`FAIL` per assertion and exits non-zero on failure.

`tests/ajax-actions.php` guards the Phase-2 regression where `admin.js` sent `action=rbw_save`
while the server only hooked `wp_ajax_rbw_save_currency`, so admin-ajax.php answered a bare `0`.
`AjaxController::actions()` is now the single source of truth for the dispatcher slugs. Phase 3
extends it to the product editor: `ProductFields::PREVIEW_ACTION` and every literal
`request()` in `product.js` must resolve to a registered `wp_ajax_rbw_*` hook.

`tests/price-calculator.php` runs `PriceCalculator` from the CLI (it never loads WordPress) and
pins the documented numbers: plain conversion, percent/fixed profit, percent commission on
`value + profit`, extra amount, the combined chain, the minimum price, every rounding mode with
step 1000 (including the exact half), the fluctuation margin, the `add`/`replace` modes, every
rejected input (zero, negative, `NaN`, `INF`, >1000%) and the large-value precision cases.

---

## Product pricing (Phase 3)

A product is **priced in a currency or commodity** instead of a fixed amount: the admin stores
a base price in the foreign unit (`100` USD, `4.75` grams of 18K gold …) and the plugin turns it
into the store price from the stored rate. Classic product editor only – there is no
block-editor integration.

### Formula (fixed order of operations)

`PriceCalculator::calculate()` runs these steps in this exact order. Everything except the final
rounding happens with the store precision **plus** 4 guard digits.

| # | Step | Detail |
| --- | --- | --- |
| 1 | `value = base_price × rate` | `rate` is store units per **1** foreign unit |
| 2 | `profit` | `percent` ⇒ `value × profit / 100`; `fixed` ⇒ the amount itself |
| 3 | `commission` | `percent` ⇒ `(value + profit) × commission / 100`; `fixed` ⇒ the amount itself |
| 4 | `extra` | fixed store-unit amount (a percent form is applied to `value + profit`) |
| 5 | `total = value + profit + commission + extra` | |
| 6 | minimum price | `if total < min_price` ⇒ `total = min_price` |
| 7 | rounding | `none` / `nearest` (half-up) / `ceil` / `floor`, with the **step** from Settings |
| 8 | fluctuation margin | if a previous price exists and `abs(new − previous) < margin` ⇒ keep `previous` |

Rules:

- All inputs are validated. `rate ≤ 0`, `base_price ≤ 0`, `NaN`, `INF`, a percentage above
  `1000` or a negative amount produce an **error result with a reason code** instead of a price:
  `invalid_rate`, `invalid_base_price`, `invalid_setting`, `non_finite`, `non_positive_result`.
  `PriceCalculator` returns `ok => false`, `price => null` and the `breakdown` of what was
  computed so far – it never throws and never returns `0`/negative.
- **Rounding happens only at the end** (step 7), on the store decimals (`0` for Toman/Rial).
- Rounding is applied **after** the minimum price, so the store never shows less than the
  minimum.
- **Float precision:** `bcmath` is used when the extension is loaded (all money steps at the
  store scale + 4 digits). Without it every step is snapped with `round()` at the same scale, so
  the accumulated error stays far below one unit. Large values are tested: gold coin
  `267,000,000` Toman and bitcoin `2 × 10^10` Toman keep full precision
  (`tests/price-calculator.php`, section 13). `float64` is exact up to `2^53 ≈ 9 × 10^15`.

### Product-level overrides (`mode`)

Each product carries its own profit/commission/extra values and a **mode**:

| Mode | Behaviour |
| --- | --- |
| `none` | use the currency values |
| `add` | currency value **+** product value. Same type ⇒ the amounts add up. **Different types** ⇒ both sides are first converted to a fixed store amount (`percent × value`) and then added; `profit_converted` / `commission_converted` in the breakdown says so |
| `replace` | use the product value only |

`PriceCalculator::merge_rules()` implements this and is shared by the live preview and the
repricer (`ProductData::resolve_rules()`), so the preview cannot drift from the stored price.

### Meta keys

| Meta key | Holds |
| --- | --- |
| `_rbw_enabled` | `yes` when the product is managed by a currency |
| `_rbw_currency` | the currency **CODE** (`USD`), never the numeric id, so a re-created row still works |
| `_rbw_base_price` | base price in the foreign unit |
| `_rbw_sale_base_price` | optional sale base price in the foreign unit |
| `_rbw_min_price` | optional minimum final price (store unit) |
| `_rbw_fluctuation` | optional fluctuation margin (store unit) |
| `_rbw_profit_mode` | `none` / `add` / `replace` |
| `_rbw_profit_type`, `_rbw_profit_value` | product profit override |
| `_rbw_commission_type`, `_rbw_commission_value` | product commission override |
| `_rbw_extra_amount` | product extra amount (always a fixed store amount) |
| `_rbw_last_price` | last calculated price written by the plugin |
| `_rbw_last_calc` | timestamp of the last calculation (shown in the products column) |
| `_rbw_last_rate` | rate used for the last calculation |

Variable products keep the currency, mode, overrides, minimum and fluctuation on the **parent**.
Each variation has `_rbw_base_price` / `_rbw_sale_base_price` in the parent currency and an
optional `_rbw_currency` override; a variation without a base price falls back to the parent's,
and one without its own overrides uses the parent's.

### Repricing flow

`Repricer::reprice_product( WC_Product $product, ?Currency $currency = null ): array` computes
and saves the regular/sale price through CRUD (`set_regular_price`, `set_sale_price`,
`set_price`, `save()`), then walks the variations, calls `wc_variable_product_sync_data()` and
`wc_delete_product_transients()` so the parent price range and the caches are refreshed. Raw SQL
on post meta is never used for prices.

It **skips with a reason (not an error)** when: the product is not managed (`not_managed`), the
currency is missing/disabled (`currency_missing`, `currency_disabled`), the rate is `0`
(`no_rate`), the rate is stale (`stale_rate`) or the base price is empty (`empty_base_price`).
A fluctuation-only change reports `unchanged` / `fluctuation`.

It runs:

1. **After a product save** – `woocommerce_update_product` (priority 20, after WooCommerce stored
   our meta) and `woocommerce_update_product_variation`. A re-entrancy counter
   (`Repricer::is_saving()`) stops our own `save()` from re-triggering the hook.
2. **After a rate changes** – `RateUpdater` fires `rbw_rate_updated` **only when the stored rate
   really changed** (difference > `1e-7`). The listener enqueues **one** async job per currency
   with Action Scheduler (`as_enqueue_async_action`, group `ratebridge-woo`), de-duplicated with
   `as_has_scheduled_action()`. When Action Scheduler is unavailable the job falls back to a
   single cron event plus a queue in the `rbw_reprice_queue` option.
3. **The job** (`RepriceJob`) processes `wc_get_products()` in batches of **50**, paginated by
   ID, filtered on `_rbw_enabled = yes` and `_rbw_currency = CODE`, including variable products
   whose variations override the currency. It reschedules itself until a batch comes back short,
   then logs one summary line with `Logger`:
   `USD: repriced 12, skipped 3, unchanged-by-fluctuation 4, failed 0.`

Products that are not managed are never touched, and a price is never written below the
minimum price.

### Stale-rate rule

General settings → **«حداکثر سن نرخ (ساعت)»** (default **48**, `0` = disabled; stored as
`max_rate_age_hours`). If a currency's `last_updated` is older than the limit:

- products of that currency are **not** repriced and keep their prices (`skipped` /
  `stale_rate`);
- a dismissible warning lists the affected currencies on the plugin page **and** on the
  Products screen. The dismissal is per user (`usermeta rbw_dismiss_stale_notice`, tied to the
  current limit so changing the limit shows the notice again).

Manual-provider currencies with no `last_updated` are never considered stale, because the admin
typed the rate by hand.

### Store-unit change (Toman ⇄ Rial)

Rates and every fixed amount are stored in the store unit, so switching between Toman and Rial
would silently corrupt them by ×10 / ÷10. The General settings tab shows an inline warning and
asks for confirmation (the AJAX payload then carries `confirm_unit_change=1`); the server refuses
an unconfirmed unit change. Once confirmed, `Core\UnitMigration::migrate( $old, $new )`:

1. multiplies the `rate` and the **fixed** profit/commission/extra of **every** currency row
   (percent values are unit-less and stay untouched);
2. converts the rounding step;
3. converts product-level store amounts in batches of 100 (`_rbw_min_price`, `_rbw_fluctuation`,
   `_rbw_last_price`, plus `_rbw_profit_value` / `_rbw_commission_value` when their type is
   `fixed`, and `_rbw_extra_amount`), then clears the fluctuation baseline;
4. logs the action with `Logger` (trigger `unit`) and queues one repricing job per currency.

Nothing happens when the unit did not really change.

### Hooks and filters

| Hook | Type | Purpose |
| --- | --- | --- |
| `rbw_rate_updated` | action | `( string $code, float $old_rate, float $new_rate, int $currency_id )` – fires only on a real change |
| `rbw_calculated_price` | filter | `( float $price, float $base_price, Currency $currency, int $post_id, array $settings )` – applied in the preview **and** before storing, so the visible number equals the stored number |
| `rbw_store_decimals` | filter | `( int $decimals, string $store_unit )` – decimal places of calculated prices |

### Product editor behaviour

- **Simple product** – General tab, section «قیمت‌گذاری با نرخ ارز»: checkbox, currency select
  (**all** currencies with code, name and current rate; disabled ones are appended with
  «غیرفعال» / «بدون نرخ» so an existing product never loses its selection), base price, sale
  base price, minimum price, fluctuation margin, profit/commission/extra with the mode select,
  and the «پیش‌نمایش قیمت» button.
- **Variable product** – the parent holds the currency, mode, overrides, minimum and
  fluctuation; each variation has a base price, an optional sale base price and an optional
  currency override («ارز والد»).
- While a product/variation is managed, the **native regular/sale price inputs are read-only**
  and a note explains that the currency controls them. Quick edit and bulk edit are not
  integrated; they can still write a price, but the next recalculation corrects it.
- **Live preview** – `wp_ajax_rbw_preview_price` (`action=rbw_preview_price`, nonce
  `rbw_admin` + the `edit_products` capability) recalculates from the **unsaved** form values
  through the same `PriceCalculator` and returns the final price plus a Persian breakdown
  (base value, profit, commission, extra, before/after minimum, rounded, final) and the flags
  that fired (minimum applied, rounded, fluctuation kept, percentage converted).
- The products list gets an «ارز» column: currency code, «آخرین محاسبه» timestamp, and a red
  reason when the product still has no calculated price («ارز غیرفعال است»، «ارز نرخ ندارد»،
  «قیمت پایه ندارد»، «ارزی انتخاب نشده است»).
  The column needs `product.css`, which is therefore also enqueued on `edit.php`
  (`ProductFields::enqueue_styles()`), not only in the editor. Without it the narrow cell had no
  wrapping rules and browsers broke the code one character per line (`U` / `S` / `D`). The code
  is `white-space: nowrap` + `unicode-bidi: isolate`, the timestamp is a `<time dir="ltr">`
  element that also stays on one line.
- A **bulk action «محاسبه دوباره قیمت‌های ارزی»** recalculates the selected products immediately
  and reports repriced / skipped / failed counts.

#### Why a product can show as «ناموجود»

WooCommerce prints *"این محصول در انبار موجود نیست"* whenever `WC_Product::is_purchasable()` is
false — and that happens when the price is **empty**, regardless of stock status. A managed
variation gets its price only when the currency is usable, so a **disabled currency (or one
without a rate)** leaves a newly created variation priceless and WooCommerce renders it as out of
stock. This is now surfaced in three places: the panel note under the currency select, the
products-list column, and an admin notice queued after saving a product whose price could not be
calculated. Fix = enable the currency (and give it a rate), then run the bulk recalculation.

Related invariants the repricer enforces on variable products:

- the **parent keeps no price of its own** (a leftover `_regular_price`/`_sale_price` from when
  the product was simple is cleared), so the range and the "from" label come from the variations;
- a variation's **sale base price is never inherited from the parent** (only its own value
  counts), which previously wrote a sale price orders of magnitude below the regular price;
- the parent base price *is* inherited by a variation that has none, because a variable product
  stores its template base price on the parent.

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
حذف تمام داده‌ها هنگام حذف افزونه** is enabled. When enabled it:

- drops the currencies table;
- deletes `rbw_settings`, `rbw_db_version`, `rbw_update_log`, `rbw_provider_settings`,
  `rbw_request_budget`, `rbw_reprice_queue`, `rbw_unit_migration` and all `_transient_rbw_*` rows;
- deletes **every `_rbw_*` post meta** row with one prepared `DELETE` (the 15 keys listed in
  `ProductData::all_meta_keys()`);
- deletes the per-user `rbw_dismiss_stale_notice` meta.

The final WooCommerce prices (`_regular_price`, `_sale_price`, `_price`) are **left as they
are**, so the shop keeps selling at the last calculated price instead of showing empty prices.
