# Translations

All user-facing strings use the `ratebridge-woo` text domain.

To create/update the template and the Persian translation you can use WP-CLI:

```bash
wp i18n make-pot . languages/ratebridge-woo.pot --domain=ratebridge-woo
wp i18n make-pot . languages/ratebridge-woo-fa_IR.po --domain=ratebridge-woo
wp i18n make-mo languages/ratebridge-woo-fa_IR.po
```

Place `ratebridge-woo-fa_IR.mo` (and optionally `.l10n.php`) in this folder; the plugin loads
it automatically via `load_plugin_textdomain()`.

The admin CSS ships an RTL stylesheet (`assets/admin/css/admin-rtl.css`) that WordPress loads
automatically on RTL locales such as `fa_IR`.
