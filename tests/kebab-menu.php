<?php
/**
 * Static contract test for the portalled kebab menu.
 *
 * The kebab menus cannot live inside `.rbw-table` (which needs `overflow: hidden`
 * for rounded corners and `overflow-x: auto` on mobile), so they are moved to
 * `<body>` while open and positioned with `position: fixed`. That behaviour is
 * easy to break by accident — a forgotten `restoreMenu()`, a per-tab copy, or a
 * z-index below the WP admin bar — so it is pinned here as text assertions
 * against admin.js, admin.css and tokens.css.
 *
 * No WordPress and no browser: this reads the sources.
 *
 * Run from the plugin root:
 *     php tests/kebab-menu.php
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
function kebab_read( string $path ): string {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Cannot read: {$path}\n" );
		exit( 1 );
	}

	return (string) file_get_contents( $path );
}

$js   = kebab_read( $root . '/assets/admin/js/admin.js' );
$css  = kebab_read( $root . '/assets/admin/css/admin.css' );
$toks = kebab_read( $root . '/assets/admin/css/tokens.css' );

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
function kebab_check( string $label, bool $ok, string $info = '' ): void {
	global $passed, $failed;

	if ( $ok ) {
		++$passed;
		printf( "  PASS  %s\n", $label );

		return;
	}

	++$failed;
	printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
}

echo "RateBridge Woo — kebab menu portal\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] The portal moves the menu out of the clipping ancestor\n";
/* ------------------------------------------------------------------------- */

kebab_check(
	'a portalled class is added on open',
	(bool) preg_match( "/classList\.add\(\s*'rbw-menu--portal'\s*\)/", $js )
);

kebab_check(
	// Phase 9d: the menu goes into the portal host (which carries the tokens),
	// never straight into <body>. Appending to <body> left every
	// `var(--rbw-*)` undefined, so the menu rendered with no background/border.
	'the menu is portalled into #rbw-portal (not <body>)',
	(bool) preg_match( '/portalHost\(\)\.appendChild\(\s*menu\s*\)/', $js )
		&& ! preg_match( '/document\.body\.appendChild\(\s*menu\s*\)/', $js )
);

kebab_check(
	'the original parent is remembered',
	(bool) preg_match( '/parent:\s*menu\.parentNode/', $js )
);

kebab_check(
	'the original next sibling is remembered (order is preserved)',
	(bool) preg_match( '/next:\s*menu\.nextSibling/', $js )
);

kebab_check(
	'restore inserts before the remembered sibling',
	(bool) preg_match( '/state\.parent\.insertBefore\(\s*menu,\s*state\.next\s*\)/', $js )
);

kebab_check(
	'restore appends when the sibling is gone',
	(bool) preg_match( '/state\.parent\.appendChild\(\s*menu\s*\)/', $js )
);

kebab_check(
	'a menu whose row was re-rendered is removed, not orphaned',
	(bool) preg_match( '/menu\.parentNode\.removeChild\(\s*menu\s*\)/', $js )
);

/* ------------------------------------------------------------------------- */
echo "\n[2] Positioning: fixed, inline-end aligned, clamped, flippable\n";
/* ------------------------------------------------------------------------- */

kebab_check(
	'.rbw-menu--portal is position:fixed',
	(bool) preg_match( '/\.rbw-menu\.rbw-menu--portal\s*\{[^}]*position:\s*fixed/s', $css )
);

kebab_check(
	'the in-flow anchoring is reset in portal mode',
	(bool) preg_match( '/\.rbw-menu\.rbw-menu--portal\s*\{[^}]*inset-block-start:\s*auto/s', $css )
		&& (bool) preg_match( '/\.rbw-menu\.rbw-menu--portal\s*\{[^}]*inset-inline-end:\s*auto/s', $css )
);

kebab_check(
	'position is computed from getBoundingClientRect()',
	(bool) preg_match( '/toggle\.getBoundingClientRect\(\)/', $js )
);

kebab_check(
	'8px viewport margin',
	(bool) preg_match( '/var margin = 8;/', $js )
);

kebab_check(
	'inline-end alignment is RTL/LTR aware',
	(bool) preg_match( '/rtl\s*\?\s*rect\.right - size\.width\s*:\s*rect\.left/', $js )
);

kebab_check(
	'it flips above when there is no room below',
	(bool) preg_match( '/var flip = spaceBelow < size\.height && spaceAbove > spaceBelow/', $js )
		&& (bool) preg_match( '/top = rect\.top - size\.height - gap/', $js )
);

kebab_check(
	'left is clamped inside the viewport',
	(bool) preg_match( '/if \(\s*left \+ size\.width > viewportW - margin\s*\)/', $js )
);

kebab_check(
	'top is clamped inside the viewport',
	(bool) preg_match( '/if \(\s*top \+ size\.height > viewportH - margin\s*\)/', $js )
);

/* ------------------------------------------------------------------------- */
echo "\n[3] Close triggers\n";
/* ------------------------------------------------------------------------- */

kebab_check(
	'outside click closes',
	(bool) preg_match( "/document\.addEventListener\(\s*'click'/", $js )
);

kebab_check(
	'a click on a menu item is NOT treated as an outside click',
	(bool) preg_match( "/closest\(\s*'\.rbw-menu'\s*\)\s*\)\s*\{\s*\n\s*return;/", $js )
);

kebab_check(
	'ESC closes and returns focus to the toggle',
	(bool) preg_match( '/closeMenus\(\s*\{\s*focusToggle:\s*true\s*\}\s*\)/', $js )
);

kebab_check(
	'window resize repositions (and therefore cannot leave it stranded)',
	(bool) preg_match( "/window\.addEventListener\(\s*'resize',\s*state\.onResize\s*\)/", $js )
);

kebab_check(
	'any ancestor scroll is caught (capture phase)',
	(bool) preg_match( "/window\.addEventListener\(\s*'scroll',\s*state\.onScroll,\s*true\s*\)/", $js )
);

kebab_check(
	'listeners are detached on close',
	(bool) preg_match( "/window\.removeEventListener\(\s*'scroll',\s*state\.onScroll,\s*true\s*\)/", $js )
		&& (bool) preg_match( "/window\.removeEventListener\(\s*'resize',\s*state\.onResize\s*\)/", $js )
);

kebab_check(
	'Tab leaving the menu closes it',
	(bool) preg_match( "/'Tab' === event\.key/", $js )
);

kebab_check(
	'opening another menu closes the previous one',
	(bool) preg_match( '/Single-open policy:[^\n]*\n\s*closeMenus\(\);/', $js )
);

kebab_check(
	'a detached toggle drops the portal (AJAX re-render safety)',
	(bool) preg_match( '/new MutationObserver/', $js )
		&& (bool) preg_match( '/!\s*OPEN_MENU\.toggle\.isConnected/', $js )
);

/* ------------------------------------------------------------------------- */
echo "\n[4] Keyboard behaviour is unchanged\n";
/* ------------------------------------------------------------------------- */

foreach ( array( 'ArrowDown', 'ArrowUp', 'Home', 'End', 'Escape', 'Tab' ) as $key ) {
	kebab_check( "handles {$key}", str_contains( $js, "'{$key}' === event.key" ) || str_contains( $js, "'{$key}'" ) );
}

kebab_check(
	'ArrowDown from the toggle opens and focuses the first item',
	(bool) preg_match( '/function menuItems\(\s*menu\s*\)/', $js )
		&& (bool) preg_match( '/var first = menuItems\(\s*menu\s*\)\[\s*0\s*\]/', $js )
);

kebab_check(
	'arrow movement wraps around the item list',
	(bool) preg_match( '/\(\s*index \+ 1\s*\)\s*%\s*list\.length/', $js )
);

/* ------------------------------------------------------------------------- */
echo "\n[5] Single shared helper (no per-tab copies)\n";
/* ------------------------------------------------------------------------- */

kebab_check(
	'exactly one bindMenus() definition',
	1 === preg_match_all( '/function bindMenus\(\s*\)/', $js )
);

kebab_check(
	'exactly one openMenu() definition',
	1 === preg_match_all( '/function openMenu\(/', $js )
);

kebab_check(
	'exactly one closeMenus() definition',
	1 === preg_match_all( '/function closeMenus\(/', $js )
);

kebab_check(
	'both lists (currencies + formulas) share the same markup class',
	2 === substr_count(
		kebab_read( $root . '/templates/tab-currencies.php' ) . kebab_read( $root . '/templates/tab-formulas.php' ),
		'class="rbw-kebab"'
	)
);

kebab_check(
	'no per-tab menu positioning code exists',
	! preg_match( '/rbwCurrencyKebab|rbwFormulaKebab|positionCurrencyMenu|positionFormulaMenu/', $js )
);

/* ------------------------------------------------------------------------- */
echo "\n[5b] ARIA wiring on both lists\n";
/* ------------------------------------------------------------------------- */

$markup = kebab_read( $root . '/templates/tab-currencies.php' ) . kebab_read( $root . '/templates/tab-formulas.php' );

kebab_check(
	'every toggle declares aria-haspopup="menu" (not the vague "true")',
	2 === substr_count( $markup, 'aria-haspopup="menu"' ) && ! str_contains( $markup, 'aria-haspopup="true"' )
);

kebab_check(
	'every toggle starts expanded=false',
	2 === substr_count( $markup, 'aria-expanded="false"' )
);

kebab_check(
	'every toggle points at its menu with aria-controls',
	2 === substr_count( $markup, 'aria-controls="rbw-' )
);

$currency_markup = kebab_read( $root . '/templates/tab-currencies.php' );
$formula_markup  = kebab_read( $root . '/templates/tab-formulas.php' );

kebab_check(
	'each list has exactly one role="menu" with an id, hidden by default',
	1 === substr_count( $currency_markup, 'class="rbw-menu" role="menu" id="rbw-currency-menu-' )
		&& 1 === substr_count( $formula_markup, 'class="rbw-menu" role="menu" id="rbw-formula-menu-' )
);

kebab_check(
	'currencies menu has edit + delete as real menuitem buttons',
	2 === substr_count( $currency_markup, '<button type="button" role="menuitem" class="rbw-menu-item' )
);

kebab_check(
	'formulas menu has edit + duplicate + delete as real menuitem buttons',
	3 === substr_count( $formula_markup, '<button type="button" role="menuitem" class="rbw-menu-item' )
);

/* ------------------------------------------------------------------------- */
echo "\n[6] Stacking order\n";
/* ------------------------------------------------------------------------- */

preg_match( '/--rbw-z-menu:\s*(\d+)/', $toks, $menu );
preg_match( '/--rbw-z-modal:\s*(\d+)/', $toks, $modal );
preg_match( '/--rbw-z-toast:\s*(\d+)/', $toks, $toast );

$z_menu  = (int) ( $menu[1] ?? 0 );
$z_modal = (int) ( $modal[1] ?? 0 );
$z_toast = (int) ( $toast[1] ?? 0 );

kebab_check( 'tokens define all three layers', $z_menu > 0 && $z_modal > 0 && $z_toast > 0 );
kebab_check( 'menu is above the WP admin bar (99999)', $z_menu > 99999, 'menu=' . $z_menu );
kebab_check( 'menu is below the modal', $z_menu < $z_modal, $z_menu . ' vs ' . $z_modal );
kebab_check( 'menu is below the toasts', $z_menu < $z_toast, $z_menu . ' vs ' . $z_toast );

kebab_check(
	'the portal uses the token, not a magic number',
	(bool) preg_match( '/\.rbw-menu\.rbw-menu--portal\s*\{[^}]*z-index:\s*var\(--rbw-z-menu\)/s', $css )
);

/* ------------------------------------------------------------------------- */
echo "\n[7] The table keeps its clipping (the portal is why that is safe)\n";
/* ------------------------------------------------------------------------- */

kebab_check(
	'.rbw-table still has overflow:hidden for rounded corners',
	(bool) preg_match( '/\.rbw-table\s*\{[^}]*overflow:\s*hidden/s', $css )
);

kebab_check(
	'mobile scroll wrapper still scrolls horizontally',
	(bool) preg_match( '/\.rbw-table-scroll\s*\{[^}]*overflow-x:\s*auto/s', $css )
);

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
