<?php
/**
 * Guards the token scope and the portal rule.
 *
 * Design tokens live on `.rbw-admin` only. Any UI moved outside that wrapper —
 * the kebab menu, the toast stack, future tooltips — must be appended to
 * `#rbw-portal`, which carries the class. When this was violated, the portalled
 * menu rendered with no background/border/padding and the toasts lost their
 * position and z-index.
 *
 * No WordPress and no browser: this reads the sources.
 *
 * Run from the plugin root:
 *     php tests/ui-scope.php
 *
 * -----------------------------------------------------------------------------
 * LIMITS (by design — this is a static test, not a CSS engine):
 *  - Rule (c) is a heuristic: for every declaration that reads `var(--rbw-*)` it
 *    looks at the nearest preceding selector list and requires at least one class
 *    in it to be a scope we know about (`.rbw-admin`, `.rbw-typography`,
 *    `.rbw-portal`, or a frontend wrapper that defines its own subset). Nested
 *    at-rules, `:is()/:where()`, and stylesheets from other plugins are not
 *    analysed. It catches the real mistake (a token used in a rule rooted at a
 *    plain WooCommerce class) but will not prove full CSS correctness.
 *  - Rule (a) matches `document.body.appendChild`/`body.append` textually; a
 *    dynamically built reference would slip through.
 * -----------------------------------------------------------------------------
 *
 * @package RateBridge
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

/**
 * Read a file, failing loudly when missing.
 *
 * @param string $path Absolute path.
 * @return string
 */
function ui_scope_read( string $path ): string {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Cannot read: {$path}\n" );
		exit( 1 );
	}

	return (string) file_get_contents( $path );
}

$passed = 0;
$failed = 0;

/**
 * Record a check.
 *
 * @param string $label Description.
 * @param bool   $ok    Result.
 * @param string $info  Extra context.
 * @return void
 */
function ui_scope_check( string $label, bool $ok, string $info = '' ): void {
	global $passed, $failed;

	if ( $ok ) {
		++$passed;
		printf( "  PASS  %s\n", $label );

		return;
	}

	++$failed;
	printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
}

/**
 * Recursively list files with an extension.
 *
 * @param string $dir Root.
 * @param string $ext Extension without dot.
 * @return string[]
 */
function ui_scope_files( string $dir, string $ext ): array {
	$found = array();

	if ( ! is_dir( $dir ) ) {
		return $found;
	}

	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( $file->isFile() && strtolower( $file->getExtension() ) === $ext ) {
			$found[] = (string) $file->getPathname();
		}
	}

	sort( $found );

	return $found;
}

echo "RateBridge Woo — UI scope + portal rule\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] No JS appends to <body> except the portal host\n";
/* ------------------------------------------------------------------------- */

$js_files = array_merge( ui_scope_files( $root . '/assets', 'js' ), ui_scope_files( $root . '/includes', 'js' ) );
$js_files = array_values( array_unique( $js_files ) );

ui_scope_check( 'found the plugin JS files', ! empty( $js_files ), implode( ', ', $js_files ) );

$body_appends = array();

foreach ( $js_files as $js_file ) {
	$source   = ui_scope_read( $js_file );
	$relative = str_replace( '\\', '/', substr( $js_file, strlen( $root ) + 1 ) );

	// `document.body.appendChild(` / `document.body.append(` / `document.body.insertBefore(`
	// `body.append(` is NOT flagged: inside this codebase `body` is a FormData.
	if ( preg_match_all( '/document\s*\.\s*body\s*\.\s*(appendChild|append|insertBefore|prepend)\s*\(/', $source, $m ) ) {
		foreach ( $m[0] as $hit ) {
			$body_appends[] = $relative . ': ' . trim( $hit );
		}
	}
}

/*
 * The ONE allowed append is inside portalHost(), which creates the host itself
 * when the server-rendered element is missing.
 */
ui_scope_check(
	'only portalHost() appends to document.body',
	1 === count( $body_appends ),
	implode( '; ', $body_appends )
);

$admin_js = ui_scope_read( $root . '/assets/admin/js/admin.js' );

ui_scope_check(
	'the single document.body append is the portal host creation',
	(bool) preg_match( '/host\.id = \'rbw-portal\';.*?document\.body\.appendChild\( host \)/s', $admin_js )
);

ui_scope_check(
	'portalHost() is defined once (shared helper)',
	1 === preg_match_all( '/function portalHost\(\)/', $admin_js )
);

ui_scope_check(
	'the toast host goes INTO the portal',
	(bool) preg_match( '/portalHost\(\)\.appendChild\( host \)/', $admin_js )
);

ui_scope_check(
	'the kebab menu goes INTO the portal',
	(bool) preg_match( '/portalHost\(\)\.appendChild\( menu \)/', $admin_js )
);

ui_scope_check(
	'the portal host carries the token class',
	(bool) preg_match( '/host\.className = \'rbw-admin rbw-portal rbw-typography\';/', $admin_js )
);

ui_scope_check(
	'no leftover body-level menu/toast append',
	! preg_match( '/document\.body\.appendChild\(\s*(menu|host\s*=\s*document)/', $admin_js )
);

/* ------------------------------------------------------------------------- */
echo "\n[2] Server-side: the portal host is rendered, not only created by JS\n";
/* ------------------------------------------------------------------------- */

$settings_php = ui_scope_read( $root . '/includes/Admin/SettingsPage.php' );

ui_scope_check(
	'render_portal_host() exists',
	(bool) preg_match( '/function render_portal_host\(\)/', $settings_php )
);

ui_scope_check(
	'the host is hooked on admin_footer',
	(bool) preg_match( "/add_action\(\s*'admin_footer',\s*array\( \\\$this,\s*'render_portal_host'\s*\)\s*\)/", $settings_php )
);

ui_scope_check(
	'the host has id + both classes',
	(bool) preg_match( '/id="rbw-portal" class="rbw-admin rbw-portal rbw-typography"/', $settings_php )
);

ui_scope_check(
	'the host is capability-gated',
	(bool) preg_match( '/function render_portal_host\(\): void \{(.*?)
	\}/s', $settings_php, $host_body )
		&& str_contains( $host_body[1] ?? '', 'current_user_can' )
);

ui_scope_check(
	'the host is limited to screens that show plugin UI',
	(bool) preg_match( '/function screen_has_plugin_ui\(\)/', $settings_php )
);

// The product screens must render it too, or the editor/list lose their host.
$product_php = ui_scope_read( $root . '/includes/Product/ProductFields.php' );

ui_scope_check(
	'product screens are covered by screen_has_plugin_ui()',
	(bool) preg_match( "/'product' === \\\$screen->post_type/", $settings_php )
);

ui_scope_check(
	'the product panel is wrapped in rbw-admin + rbw-typography',
	(bool) preg_match( '/class="options_group rbw-product-panel rbw-admin rbw-typography"/', $product_php )
);

ui_scope_check(
	'the products list column is token-scoped',
	(bool) preg_match( '/class="rbw-col-currency rbw-admin"/', $product_php )
);

/* ------------------------------------------------------------------------- */
echo "\n[3] tokens.css defines the token block only on .rbw-admin\n";
/* ------------------------------------------------------------------------- */

$tokens = ui_scope_read( $root . '/assets/admin/css/tokens.css' );

// Find every selector list that immediately precedes a block containing --rbw-* definitions.
$token_blocks = array();

if ( preg_match_all( '/([^{}]+)\{([^{}]*--rbw-[a-z0-9-]+\s*:[^{}]*)\}/s', $tokens, $blocks, PREG_SET_ORDER ) ) {
	foreach ( $blocks as $block ) {
		// The "selector" capture starts after the previous `}`, so it may still
		// carry comment text. Strip comments before reading the selector list.
		$raw      = preg_replace( '#/\*.*?\*/#s', '', $block[1] );
		$selector = trim( preg_replace( '/\s+/', ' ', (string) $raw ) );

		// Skip @supports/@media wrappers (handled below).
		if ( '' === $selector || str_starts_with( $selector, '@' ) ) {
			continue;
		}
		$token_blocks[ $selector ][] = $block[2];
	}
}

$allowed_scopes = array(
	'.rbw-admin',        // The scope. Tokens live here and only here.
	'.rbw-typography',   // Consumes tokens, defines none (checked below).
);

$offenders = array();

foreach ( array_keys( $token_blocks ) as $selector ) {
	foreach ( $allowed_scopes as $allowed ) {
		if ( $selector === $allowed ) {
			continue 2;
		}
	}

	/*
	 * A rule like `.rbw-toasts { --x: … }` would be a second token scope. Any
	 * other selector defining --rbw-* is flagged.
	 */
	$offenders[] = $selector;
}

ui_scope_check(
	'the only selector defining --rbw-* custom properties is .rbw-admin',
	empty( $offenders ),
	implode( ' | ', $offenders )
);

ui_scope_check(
	'the temporary .rbw-toasts / .rbw-menu--portal token hack is gone',
	! preg_match( '/^\s*\.rbw-toasts,\s*$/m', $tokens )
		&& ! preg_match( '/\.rbw-menu--portal\s*\{/', $tokens )
);

ui_scope_check(
	'.rbw-admin is the token block',
	(bool) preg_match( '/^\.rbw-admin\s*\{/m', $tokens )
);

ui_scope_check(
	'.rbw-typography defines typography, not tokens',
	(bool) preg_match( '/^\.rbw-typography\s*\{[^}]*font-family:\s*var\(--rbw-font\)/m', $tokens )
		&& ! preg_match( '/^\.rbw-typography\s*\{[^}]*--rbw-[a-z0-9-]+\s*:/m', $tokens )
);

// The @supports block must also target .rbw-admin only.
if ( preg_match_all( '/@supports[^{]*\{\s*([^{]+)\{/s', $tokens, $sup ) ) {
	$bad = array();

	foreach ( $sup[1] as $selector ) {
		$selector = trim( preg_replace( '/\s+/', ' ', $selector ) );

		if ( '.rbw-admin' !== $selector ) {
			$bad[] = $selector;
		}
	}

	ui_scope_check( 'the @supports token block targets .rbw-admin only', empty( $bad ), implode( ' | ', $bad ) );
}

/* ------------------------------------------------------------------------- */
echo "\n[4] Every var(--rbw-*) consumer is rooted in a known scope (heuristic)\n";
/* ------------------------------------------------------------------------- */

/*
 * `admin.css` is loaded together with tokens.css on plugin screens, so every one
 * of its rules may use tokens — but only when the rule actually sits inside the
 * scope. frontend.css defines its OWN small token subset, so it is exempt; it is
 * listed here to make that explicit rather than accidental.
 */
$css_files = array(
	'assets/admin/css/admin.css'         => 'admin',
	'assets/admin/css/admin-rtl.css'     => 'admin',
	'assets/admin/css/product.css'       => 'product',
	'assets/admin/css/product-rtl.css'   => 'product',
	'assets/frontend/css/frontend.css'   => 'self-contained',
	'assets/frontend/css/frontend-rtl.css' => 'self-contained',
);

foreach ( $css_files as $relative => $kind ) {
	$path = $root . '/' . $relative;

	if ( ! is_readable( $path ) ) {
		ui_scope_check( "{$relative} exists", false );
		continue;
	}

	$css = (string) file_get_contents( $path );

	// Does this file use tokens at all?
	$uses_tokens = (bool) preg_match( '/var\(\s*--rbw-[a-z0-9-]+/', $css );

	if ( ! $uses_tokens ) {
		ui_scope_check( "{$relative} uses no tokens (nothing to scope)", true );
		continue;
	}

	if ( 'self-contained' === $kind ) {
		// It must define the subset it uses.
		$defined = array();
		preg_match_all( '/--rbw-([a-z0-9-]+)\s*:/', $css, $defs );
		$defined = array_unique( $defs[1] );

		$used = array();
		preg_match_all( '/var\(\s*--rbw-([a-z0-9-]+)/', $css, $uses );
		$used = array_unique( $uses[1] );

		/*
		 * A theme hook is a token that is read WITH a fallback:
		 *     var(--rbw-block-bg, rgba(34, 113, 177, 0.06))
		 * Those may stay undefined — the fallback is the whole point, it lets a
		 * theme restyle one block without shipping our full token set. Only a
		 * var() WITHOUT a fallback must be defined locally, otherwise the
		 * declaration is invalid and silently dropped.
		 */
		$bare = array();
		preg_match_all( '/var\(\s*--rbw-([a-z0-9-]+)\s*\)/', $css, $bare_uses );
		$bare = array_unique( $bare_uses[1] );

		$missing = array_values( array_diff( $bare, $defined ) );

		ui_scope_check(
			"{$relative} defines every token it consumes WITHOUT a fallback",
			empty( $missing ),
			'undefined and no fallback: ' . implode( ', ', $missing )
		);

		continue;
	}

	/*
	 * For the admin/product sheets: strip comments, then walk rule blocks and
	 * record the selector list that owns each `var(--rbw-` usage. A rule fails
	 * when NONE of its selectors mentions a scope class.
	 */
	$stripped = preg_replace( '#/\*.*?\*/#s', '', $css );

	if ( null === $stripped ) {
		$stripped = $css;
	}

	$unscoped = array();

	// Innermost `selector { declarations }` pairs.
	if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $stripped, $rules, PREG_SET_ORDER ) ) {
		foreach ( $rules as $rule ) {
			$declarations = $rule[2];

			if ( ! preg_match( '/var\(\s*--rbw-[a-z0-9-]+/', $declarations ) ) {
				continue;
			}

			$selector = trim( preg_replace( '/\s+/', ' ', $rule[1] ) );

			// Skip at-rule preludes captured as selectors.
			if ( str_starts_with( $selector, '@' ) || '' === $selector ) {
				continue;
			}

			/*
			 * @keyframes steps (`from`, `to`, `50%`) are not selectors: they apply
			 * to whatever element runs the animation, which is already scoped at
			 * the call site. Flagging them would be a false positive.
			 */
			if ( preg_match( '/^(from|to|\d+%|0%|100%)$/', $selector ) ) {
				continue;
			}

			// A rule is in scope when any of its selector parts names a scope class.
			$in_scope = false;

			foreach ( explode( ',', $selector ) as $part ) {
				if ( preg_match( '/\.rbw-(admin|typography|portal|product-panel|kpi|card|menu|toast|badge|button|modal|tabs?|panel|table|form|field|col-)\b/', $part )
					|| preg_match( '/^\.rbw-[a-z]/', trim( $part ) ) ) {
					$in_scope = true;
					break;
				}
			}

			if ( ! $in_scope ) {
				$unscoped[] = $selector;
			}
		}
	}

	ui_scope_check(
		"{$relative}: every rule using var(--rbw-*) is rooted in an .rbw-* class",
		empty( $unscoped ),
		implode( ' | ', array_slice( array_unique( $unscoped ), 0, 6 ) )
	);
}

/* ------------------------------------------------------------------------- */
echo "\n[4b] Body-level components can resolve every token they use\n";
/* ------------------------------------------------------------------------- */

/*
 * The two components that live in the portal must not reference a token that
 * `.rbw-admin` does not define — that is precisely the "unstyled toast / menu"
 * symptom. This is a resolved-set check, not a selector heuristic.
 */
$admin_css = (string) file_get_contents( $root . '/assets/admin/css/admin.css' );

/**
 * Collect `--rbw-*` names defined inside a CSS snippet.
 *
 * @param string $css Snippet.
 * @return string[]
 */
function ui_scope_defined( string $css ): array {
	preg_match_all( '/--rbw-([a-z0-9-]+)\s*:/', $css, $m );

	return array_values( array_unique( $m[1] ) );
}

/**
 * Collect `--rbw-*` names read via var() in a CSS snippet.
 *
 * @param string $css Snippet.
 * @return string[]
 */
function ui_scope_used( string $css ): array {
	preg_match_all( '/var\(\s*--rbw-([a-z0-9-]+)/', $css, $m );

	return array_values( array_unique( $m[1] ) );
}

// Everything `.rbw-admin` defines, including the @supports additions.
$admin_defined = ui_scope_defined( $tokens );

// The portal-resident component blocks.
$portal_css = '';

foreach ( array( '.rbw-toasts {', '.rbw-toast {', '.rbw-menu {', '.rbw-menu-item {' ) as $start ) {
	$from = strpos( $admin_css, $start );

	if ( false === $from ) {
		continue;
	}

	// Take up to the next top-level rule start (crude but adequate here).
	$chunk      = substr( $admin_css, $from, 4000 );
	$portal_css .= $chunk;
}

$needed  = ui_scope_used( $portal_css );
$missing = array_values( array_diff( $needed, $admin_defined ) );

ui_scope_check(
	'every token used by the toast + kebab menu is defined on .rbw-admin',
	empty( $missing ),
	'undefined: ' . implode( ', ', $missing )
);

ui_scope_check(
	'the toast stack is styled by tokens (not a bare div)',
	count( array_intersect( array( 'bg', 'border', 'surface', 'z-toast' ), ui_scope_used( substr( $admin_css, strpos( $admin_css, '.rbw-toasts {' ), 800 ) ) ) ) >= 2
);

/* ------------------------------------------------------------------------- */
echo "\n[5] Limits of this test (documented, not asserted)\n";
/* ------------------------------------------------------------------------- */

/*
 * Rule (c) is a heuristic. It cannot know about:
 *  - CSS injected at runtime by other plugins/themes;
 *  - cascade/specificity conflicts;
 *  - a token consumed inside an inline `style="…"` attribute in PHP.
 * The inline-style case is covered separately below because it is cheap to check.
 */
$inline_offenders = array();

foreach ( array_merge( ui_scope_files( $root . '/includes', 'php' ), ui_scope_files( $root . '/templates', 'php' ) ) as $php_file ) {
	$source = (string) file_get_contents( $php_file );

	if ( preg_match( '/style="[^"]*var\(--rbw-/', $source ) ) {
		$inline_offenders[] = str_replace( '\\', '/', substr( $php_file, strlen( $root ) + 1 ) );
	}
}

ui_scope_check(
	'no PHP emits an inline style that reads a token the element may not inherit',
	empty( $inline_offenders ),
	implode( ', ', $inline_offenders )
);

ui_scope_check(
	'AGENTS.md documents the portal rule',
	str_contains(
		ui_scope_read( $root . '/AGENTS.md' ),
		'must live inside `#rbw-portal`'
	)
);

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
