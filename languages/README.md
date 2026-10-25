# Translations

All user-facing strings use the `ratebridge-woo` text domain. The plugin ships a complete
Persian (`fa_IR`) translation.

## Files

| File | Purpose |
| --- | --- |
| `ratebridge-woo-fa_IR.po` | Persian translation source (222 strings) |
| `ratebridge-woo-fa_IR.mo` | Compiled binary catalogue loaded by WordPress |

`load_plugin_textdomain( 'ratebridge-woo', false, dirname( RBW_PLUGIN_BASENAME ) . '/languages' )`
runs on `init`, so on a `fa_IR` site the translation loads automatically.

## Rebuilding the `.mo`

The plugin ships a dependency-free compiler (no gettext/msgfmt needed):

```bash
php bin/build-mo.php                    # compiles every languages/*.po
php bin/build-mo.php languages/x.po     # or a single file
```

It prints the entry count, warns about untranslated strings, and writes the GNU MO little-endian
format directly.

If you have `msgfmt` available, this is equivalent:

```bash
msgfmt languages/ratebridge-woo-fa_IR.po -o languages/ratebridge-woo-fa_IR.mo
msgfmt --check --statistics languages/ratebridge-woo-fa_IR.po   # validate only
```

## Finding untranslated strings

```bash
php bin/i18n-strings.php                          # list all English source strings
php bin/i18n-strings.php --pot > languages/ratebridge-woo.pot   # POT skeleton
```

The extractor uses PHP's tokenizer, so it only reports literal strings passed to
`__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`, `_x()`, `_n()`
and `_nx()` with the `ratebridge-woo` domain.

## Adding another language

```bash
cp languages/ratebridge-woo-fa_IR.po languages/ratebridge-woo-de_DE.po
# edit the msgstr lines, then:
php bin/build-mo.php languages/ratebridge-woo-de_DE.po
```

WordPress also loads `ratebridge-woo-{locale}.l10n.php` if present (PHP-array format) — useful
for very large catalogues, but optional.

## JS strings

Admin JavaScript receives its strings via `wp_localize_script()` from
`SettingsPage::enqueue_assets()` (the `rbwAdmin.strings` object), so they are translated
server-side by the same text domain. No separate JS catalogue is needed.

## Terminology

Keep these consistent with the existing `.po`:

| English | Persian |
| --- | --- |
| Currency | ارز |
| Rate | نرخ |
| Provider / Source | منبع قیمت |
| Enabled | فعال |
| Update now | به‌روزرسانی همین حالا |
| Update all | به‌روزرسانی همه |
| Add currency | افزودن ارز |
| Last update | آخرین به‌روزرسانی |
| Interval | بازه به‌روزرسانی |
| Log | گزارش |
| Settings | تنظیمات |
| API key | کلید API |
| Test connection | تست اتصال |
| Rounding | گرد کردن |
| Toman / Rial | تومان / ریال |
| Gold | طلا |
| Coin | سکه |

Numbers are **not** converted to Persian digits; only wording and units are localized.
