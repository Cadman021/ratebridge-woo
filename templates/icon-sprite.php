<?php
/**
 * Inline SVG icon sprite.
 *
 * Printed once per plugin page (see SettingsPage::render_page()) and reused with
 * `<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-refresh"></use></svg>`.
 *
 * Rules:
 *  - 24×24 viewBox, no hardcoded colours (fill="currentColor" in tokens.css).
 *  - Purely decorative icons carry aria-hidden; text always accompanies them.
 *  - No icon font, no external file, no CDN.
 *
 * @package RateBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Icon paths: name => inner SVG markup.
 *
 * @return array<string, string>
 */
$rbw_icons = array(
	'refresh'   => '<path d="M12 5V2L7 6l5 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z"/>',
	'plus'      => '<path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/>',
	'edit'      => '<path d="M4 17.2 15.5 5.7a2 2 0 0 1 2.8 0l.9.9a2 2 0 0 1 0 2.8L7.7 21H4v-3.8zM15 8.4l2.6 2.6 1-1-2.6-2.6-1 1z"/>',
	'trash'     => '<path d="M9 3h6l1 2h4v2H4V5h4l1-2zm-3 6h12l-1 12H7L6 9zm3 2v8h2v-8H9zm4 0v8h2v-8h-2z"/>',
	'more'      => '<circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/>',
	'check'     => '<path d="M9.5 17.6 4 12.1l1.4-1.4 4.1 4.1L18.6 5.6 20 7z"/>',
	'warning'   => '<path d="M12 3 22 20H2L12 3zm0 4.6L5.4 18h13.2L12 7.6zM11 11h2v4h-2v-4zm0 5h2v2h-2v-2z"/>',
	'error'     => '<path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-1 5v6h2V7h-2zm0 8v2h2v-2h-2z"/>',
	'clock'     => '<path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm-1 3h2v6l4 2-1 1.7-5-2.7V7z"/>',
	'key'       => '<path d="M14 2a8 8 0 0 0-7.6 10.5L2 17v5h5v-2h2v-2h2l1.5-1.5A8 8 0 1 0 14 2zm2 3a2 2 0 1 1 0 4 2 2 0 0 1 0-4z"/>',
	'link'      => '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1 1.4 1.4L13 7a3 3 0 0 1 4 4l-2 2a3 3 0 0 1-4 0L10 13zm4-2a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1-1.4-1.4L11 17a3 3 0 0 1-4-4l2-2a3 3 0 0 1 4 0L11 11z"/>',
	'download'  => '<path d="M11 3h2v9l3.5-3.5 1.4 1.4L12 16 6.1 9.9l1.4-1.4L11 12V3zM4 18h16v3H4z"/>',
	'upload'    => '<path d="M11 16h2V7l3.5 3.5 1.4-1.4L12 4 6.1 8.9l1.4 1.4L11 7v9zM4 18h16v3H4z"/>',
	'formula'   => '<path d="M6 3h12v2h-7l3 5-3 5h7v2H6l4-7-4-7z"/>',
	'coin'      => '<path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm-1 3h2v3.2c1.5.3 2.5 1.2 2.5 2.6h-2c0-.5-.7-.9-1.5-.9s-1.5.4-1.5.9.5.8 1.9 1.1c1.7.4 3 1.1 3 2.8 0 1.4-1 2.3-2.4 2.6V21h-2v-3.2C9.4 17.5 8.4 16.6 8.4 15h2c0 .6.7 1 1.6 1s1.6-.4 1.6-1-.5-.8-2-1.2c-1.6-.4-2.9-1.1-2.9-2.7 0-1.4 1-2.3 2.3-2.6V7z"/>',
	'chart'     => '<path d="M4 20V10h4v10H4zm6 0V4h4v16h-4zm6 0v-7h4v7h-4z"/>',
	'info'      => '<path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-1 5v2h2V7h-2zm0 4v6h2v-6h-2z"/>',
	'search'    => '<path d="M10 3a7 7 0 1 1 0 14 7 7 0 0 1 0-14zm0 2a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.3 10.3 4.4 4.4-1.4 1.4-4.4-4.4 1.4-1.4z"/>',
	'close'     => '<path d="m6.4 5 5.6 5.6L17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4z"/>',
	'arrow'     => '<path d="M13.3 5.3 12 3.9 4.9 11l7.1 7.1 1.3-1.4L8.7 12z"/>',
	'external'  => '<path d="M14 3h7v7h-2V6.4l-7.3 7.3-1.4-1.4L17.6 5H14V3zM5 5h6v2H7v10h10v-4h2v6H5V5z"/>',
	'eye'       => '<path d="M12 5c5 0 9 4.5 9 7s-4 7-9 7-9-4.5-9-7 4-7 9-7zm0 2C8.6 7 5.7 9.7 5 12c.7 2.3 3.6 5 7 5s6.3-2.7 7-5c-.7-2.3-3.6-5-7-5zm0 1.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7zm0 2a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/>',
	'eye-off'   => '<path d="m3.3 2 18.7 18.7-1.4 1.4-3.2-3.2A10.5 10.5 0 0 1 12 19c-5 0-9-4.5-9-7 0-1.2 1-2.9 2.7-4.3L2 3.4 3.3 2zm4 6.5C6.4 9.4 5 11.2 5 12c.7 2.3 3.6 5 7 5 1.6 0 3.1-.6 4.3-1.5l-1.6-1.6A3.5 3.5 0 0 1 9.8 8.5L7.3 8.5zM12 5c5 0 9 4.5 9 7 0 1-.7 2.3-1.9 3.5l-1.4-1.4C18.6 13.2 19 12.4 19 12c-.7-2.3-3.6-5-7-5-.7 0-1.4.1-2 .3L8.4 5.7A10 10 0 0 1 12 5z"/>',
	'save'      => '<path d="M4 4h12l4 4v12H4V4zm2 2v12h12V9.2L16.8 7H6zm2 1h7v4H8V7zm1 7h6v4H9v-4z"/>',
	'copy'      => '<path d="M9 3h9a2 2 0 0 1 2 2v9h-2V5H9V3zM4 8h10v13H4V8zm2 2v9h6v-9H6z"/>',
	'filter'    => '<path d="M3 5h18l-7 8v6l-4-2v-4L3 5z"/>',
	// A real gear, so «تنظیمات عمومی» no longer looks like a dropdown caret.
	'gear'      => '<path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zm0 2a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zM10.6 2h2.8l.4 2.4c.4.1.8.3 1.2.5l2-1.4 2 2-1.4 2c.2.4.4.8.5 1.2l2.4.4v2.8l-2.4.4c-.1.4-.3.8-.5 1.2l1.4 2-2 2-2-1.4c-.4.2-.8.4-1.2.5L13.4 22h-2.8l-.4-2.4c-.4-.1-.8-.3-1.2-.5l-2 1.4-2-2 1.4-2c-.2-.4-.4-.8-.5-1.2L3.5 14.9v-2.8l2.4-.4c.1-.4.3-.8.5-1.2l-1.4-2 2-2 2 1.4c.4-.2.8-.4 1.2-.5L10.6 2z"/>',
	// Tabs get their own distinct, meaningful icons (no two tabs share one).
	'layers'    => '<path d="M12 2 2 7l10 5 10-5-10-5zm0 2.3L18.6 7 12 9.8 5.4 7 12 4.3zM2 12l10 5 10-5-1.8-.9L12 15.1 3.8 11.1 2 12zm0 5 10 5 10-5-1.8-.9L12 20.1l-8.2-4-1.8.9z"/>',
	'swap'      => '<path d="M7 4l4 4H8v8H6V8H2l5-4zm10 16-4-4h3V8h2v8h4l-5 4z"/>',
);

?>
<svg class="rbw-sprite" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
	<?php foreach ( $rbw_icons as $rbw_name => $rbw_path ) : ?>
		<symbol id="rbw-i-<?php echo esc_attr( $rbw_name ); ?>" viewBox="0 0 24 24"><?php echo $rbw_path; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, developer-authored paths. ?></symbol>
	<?php endforeach; ?>
</svg>
