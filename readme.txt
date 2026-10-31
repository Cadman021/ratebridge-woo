=== RateBridge Woo / نرخ ساز ===
Contributors: ratebridge
Tags: currency, exchange rate, woocommerce, pricing, gold price, persian
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Price WooCommerce products in a foreign currency or commodity (USD, gold, coins…) and keep the
store price in Toman/Rial updated automatically from live rates.

== Description ==

نرخ ساز (RateBridge Woo) lets you define a product price in a **foreign currency or commodity**
instead of a fixed amount:

* `100` USD, `4.75` grams of 18K gold, one gold coin…
* the plugin turns it into the store price (Toman or Rial) from the stored rate,
* and recalculates it automatically whenever a rate changes.

**Features**

* **Currencies** with real price sources (Navasan, AlanChand, NetArz, Nerkhsanj), per-source
  settings and a request-quota guard (monthly **and** daily) so a free plan is never burned.
* **Currency-level pricing rules**: profit (percent/fixed), commission, extra amount.
* **Product pricing** (simple + variable products, classic editor): base price, sale base price,
  minimum price, fluctuation margin, per-product profit overrides, live price preview.
* **Formula builder**: write `weight * gold18 * (1 + wage / 100) * (1 + profit / 100)` with typed
  variables (currency rates, product weight/dimensions, custom values). A safe interpreter — no
  `eval`, no code execution.
* **Automatic repricing** in the background (Action Scheduler, batched) whenever a rate or a
  formula changes, with stale-rate protection.
* **Order records**: every order line stores the currency, rate and base price it was priced with.
* **Frontend output**: shortcodes for a single rate, a currencies table, a scrolling ticker and
  product info; an optional product-page currency block; 8 Elementor widgets.
* **Import / Export**: a JSON package (settings, currencies, formulas) with dry run, backups and
  undo; a products CSV with chunked import and a progress bar.
* **Bulk edit** of pricing settings from the products list.
* Full **Persian (fa_IR)** interface, RTL-correct.

**Requirements:** WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+. Elementor 3.5+ is optional (widgets).

**Fonts and licenses:** the admin UI uses the **Vazirmatn** typeface by Saber Rastikerdar
(https://github.com/rastikerdar/vazirmatn), bundled unmodified as `.woff2` files in
`assets/fonts/` and loaded from `assets/admin/css/tokens.css`. Vazirmatn is licensed under the
**SIL Open Font License 1.1**; the full license text ships in `assets/fonts/OFL.txt`.
Frontend output inherits the theme font and loads no webfont of its own.

== Installation ==

1. Upload the `ratebridge-woo` folder to `/wp-content/plugins/` (or install the ZIP from
   *Plugins → Add New → Upload Plugin*).
2. Activate **نرخ ساز** through the *Plugins* screen.
3. Go to **WooCommerce → نرخ ساز** and:
   * open **Currencies**, enable the currencies you need and pick a price source,
   * set the profit/commission/extra rules per currency,
   * check **General settings** for the store unit (Toman/Rial) and rounding.
4. Open a product, enable **«قیمت‌گذاری با نرخ ارز»** and enter its base price in that currency.

== Frequently Asked Questions ==

= Does it call the provider APIs on every page view? =

No. Rates are fetched by WP-Cron (minimum 5 minutes) and cached. The frontend only reads the stored
rates.

= What happens if a rate is stale? =

General settings has **«حداکثر سن نرخ»** (default 48 hours, `0` disables it). When a rate is older,
products keep their price, a dismissible notice lists the affected currencies, and nothing is
repriced until a fresh rate arrives.

= Can I use it without WooCommerce? =

No. The plugin requires WooCommerce and disables itself with a notice when WooCommerce is missing.

= Are my API keys exported? =

Never. The JSON package contains settings, currencies and formulas only; keys, tokens, request
counters, logs and caches are excluded, and a unit test fails if such a key ever appears.

= Does a formula run PHP? =

No. Formulas go through a small interpreter (normalizer → lexer → parser → AST → evaluator) with a
whitelist of functions. There is no `eval()`, no file include and no dynamic call.

= Which numbers can a formula use? =

Persian and Arabic digits, `٫` as the decimal separator, `×`, `÷`, `−`, and the functions `min`,
`max`, `round`, `ceil`, `floor`, `abs`, `pow`, `sqrt`, `clamp`, `if`, `percent`. Chained comparisons
(`1 < 2 < 3`) are rejected; write `(1 < 2) and (2 < 3)`.

= Does it work with an external object cache (Redis/Memcached)? =

Yes. Cache invalidation uses generation counters, so flushing works even when transients are not
rows in `wp_options`.

= Will my prices survive deactivating the plugin? =

Yes. Deactivation never deletes data; the calculated WooCommerce prices stay as they are. Data is
only removed on uninstall when **«حذف تمام داده‌ها هنگام حذف افزونه»** is enabled.

== Screenshots ==

1. Currencies tab with live rates and per-currency pricing rules.
2. Product editor with pricing-by-currency fields and the live preview.
3. Formula builder with variables, validation and a test panel.
4. Elementor widgets in the «نرخ ساز» category.

== Changelog ==

= 1.0.0 =
* Fix: the provider label «نوسان» was rendered as «نواسان» because the compiled
  `.mo` was stale; the Persian catalogue is now rebuilt and guarded by a test.
* Fix: the «قیمت با نرخ ارز / فرمول‌بندی» control in the product editor escaped
  the meta box and overlapped the sidebar — WooCommerce floats panel labels with
  a -150px margin, which is now cancelled (RTL and LTR).
* Fix: every plugin stylesheet/script is versioned from the file mtime in debug
  mode, so a CSS fix is never masked by a cached copy.
* Fix: body-level UI (kebab menus, toasts) lives in `#rbw-portal`, so the design
  tokens apply to it.

= 0.9.0 =
* New source **NetArz** (`netarz`, نت‌ارز): Bearer-key FX API, Toman quotes, free (15-minute
  delay) and pro plans with their own daily caps, `unit`-aware parsing for IQD/AMD/PKR.
* New source **Nerkhsanj** (`nerkhsanj`, نرخ‌سنج): free open feed, no key, 28 Toman rates
  including gold and coins, Jalali/Tehran timestamps with a 3-day staleness warning.
* **Sticky `auth_failed` state**: an HTTP 401/403 stops all retries until the key changes or a
  manual test succeeds, and the root cause (e.g. "the server IP is not allow-listed") is shown
  on the Sources card, the currency row and the Overview instead of a generic quota message.
* **Daily request limit** for providers that need one, checked before the monthly limit and
  reset on the site-timezone date.
* AlanChand wording corrected: the token travels in the **query string** (`token=…`), the free
  token is valid for 3 days and can be issued once.
* `fx-ntz-…` keys (and generic `Bearer` tokens) are now scrubbed from every message and log
  entry — this also fixes a pattern that previously matched nothing.

= 0.8.1 =
* UI redesign: design tokens, Vazirmatn, inline icon sprite, a new Overview tab (KPIs, provider
  health, attention list, activity timeline), restyled currencies list with filters and a kebab
  menu, toast notifications and an accessible confirm dialog, and a `style` attribute for
  `[rbw_rates]` (`minimal` / `striped` / `cards`).
* No functional change: every AJAX action, payload and capability is unchanged.

= 0.8.0 =
* Review fixes: the CSV import keeps rows in a private JSONL file instead of a transient, cache
  invalidation now works with Redis/Memcached (generation counters), a per-action capability map,
  the products CSV export now includes variations of managed parents, and formula edge cases
  (`round` with negative decimals, `clamp` range errors, chained comparisons) are handled.
* Added the GPL-2.0 text (`LICENSE`), this `readme.txt` and a generated `languages/ratebridge-woo.pot`.

= 0.7.1 =
* Fix: the products-list «ارز» column rendered inside the Price cell (double hook registration and a
  per-post static cache).

= 0.7.0 =
* New: Import / Export tab — JSON package (settings, currencies, formulas) with dry run, backups and
  restore, plus a products CSV with chunked import and progress.
* New: bulk edit of pricing settings from the products list, with async repricing.
* New: `[rbw_formula_var]` shortcode and the «متغیر فرمول محصول» Elementor widget.

= 0.6.0 =
* New: safe formula engine (normalizer, lexer, parser, evaluator) with typed variables.
* New: «فرمول‌ها» tab with a builder, live validation and a test panel.
* New: formula pricing mode for products and variations, with its own failure handling.
* New: formula variables in order records (`_rbw_formula_name`, `_rbw_formula_vars`).

= 0.5.0 =
* New: 7 Elementor widgets in a dedicated «نرخ ساز» category.
* Fix: a currency loses its rate (pending state) when its provider or symbol changes.
* Fix: an empty currency symbol falls back to its code.

= 0.4.0 =
* New: order line rate records (`_rbw_currency`, `_rbw_rate`, `_rbw_base_price`, …).
* New: shortcodes `[rbw_rate]`, `[rbw_rates]`, `[rbw_ticker]`, `[rbw_product_rate]`,
  `[rbw_product_updated]`.
* New: optional product-page currency block; Persian digits option.

= 0.3.0 =
* New: product pricing by currency (simple + variable), automatic repricing on rate change,
  store-unit migration, live price preview.

= 0.2.0 =
* New: real price sources (Navasan, AlanChand) with per-source settings and a request-quota guard.
* New: provider presets, symbol picker, update log.

= 0.1.0 =
* First release: currency table, manual and mock providers, settings page, Persian UI.

== Upgrade Notice ==

= 0.8.1 =
New admin UI and a `style` attribute for the currencies table; behaviour unchanged.

= 0.8.0 =
Review fixes: CSV import memory, cache invalidation with Redis/Memcached, capabilities, export
variations and formula edge cases.

= 0.7.1 =
Fixes the «ارز» column overlapping the Price column in the products list.

= 0.7.0 =
Adds import/export (JSON package + products CSV) and bulk edit of pricing settings.

= 0.6.0 =
Adds the formula builder and formula pricing.

= 0.5.0 =
Adds Elementor widgets and fixes the pending state after a provider change.
