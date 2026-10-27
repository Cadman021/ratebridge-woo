<?php
/**
 * Small WordPress-free string helpers shared by the ImportExport classes.
 *
 * The plugin's pure-PHP classes must run without WordPress (the CLI tests do),
 * but they still need `sanitize_key()`-style normalisation. Keeping the
 * replacements here — in one file — avoids duplicate function declarations.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal `sanitize_key()` replacement: lowercase, `[a-z0-9_-]` only.
 *
 * @param string $value Raw key.
 * @return string
 */
function sanitize_key_compat( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' );
}
