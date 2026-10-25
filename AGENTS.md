# Project rules
- WordPress plugin, PHP 8.1+, WordPress 6.4+, WooCommerce 8+ (HPOS compatible).
- Follow WordPress Coding Standards; prefix everything with `rbw_` / namespace `RateBridge`.
- Always sanitize input, escape output, use nonces and capability checks.
- All user-facing strings use text domain `ratebridge-woo`; RTL/Persian first.
- No copying of third-party code or assets. No external libraries without asking.
- After each task: list changed files, how to test manually, and run `php -l` on edited files.