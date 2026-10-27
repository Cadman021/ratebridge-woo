<?php
/**
 * Static contract test for the Elementor widgets.
 *
 * Run from the plugin root:
 *     php tests/elementor-contracts.php
 *
 * No WordPress and no Elementor are loaded: this asserts the *structure* the
 * Elementor 3.5+ API requires, plus the rules the plugin code follows. It is
 * deliberately a source-level check (tokenizer/regex, no bootstrapping) so it
 * runs anywhere and catches the mistakes that would otherwise only show up as a
 * fatal inside the Elementor editor.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
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
function el_test_read( string $path ): string {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Cannot read: {$path}\n" );
		exit( 1 );
	}

	return (string) file_get_contents( $path );
}

/**
 * Record a check.
 *
 * @param string $label Description.
 * @param bool   $ok    Result.
 * @param string $info  Extra info on failure.
 * @return void
 */
function el_check( string $label, bool $ok, string $info = '' ): void {
	global $el_passed, $el_failed;

	if ( $ok ) {
		++$el_passed;
		printf( "  PASS  %s\n", $label );
		return;
	}

	++$el_failed;
	printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
}

$el_passed = 0;
$el_failed = 0;

echo "RateBridge Woo — Elementor widget contracts\n";
echo str_repeat( '=', 60 ), "\n\n";

$widget_dir = $root . '/includes/Elementor/Widgets';
$files      = glob( $widget_dir . '/*.php' );

if ( ! is_array( $files ) ) {
	$files = array();
}

sort( $files );

// The abstract base is not a widget; every other file must define one.
$base_file  = $widget_dir . '/AbstractWidget.php';
$widget_files = array_values( array_filter( $files, static fn ( string $f ): bool => realpath( $f ) !== realpath( $base_file ) ) );

printf( "Widget files: %d (+1 abstract base)\n\n", count( $widget_files ) );

/* ------------------------------------------------------------------------- */
echo "[1] Each widget file defines a complete Elementor widget\n";
/* ------------------------------------------------------------------------- */
/*
 * `get_categories()` is inherited from AbstractWidget (one category for the whole
 * plugin), so it is checked once on the base instead of per widget.
 */
$required_methods = array( 'get_name', 'get_title', 'register_controls', 'render' );
$names            = array();
$classes          = array();

foreach ( $widget_files as $file ) {
	$source   = el_test_read( $file );
	$basename = basename( $file, '.php' );

	el_check(
		sprintf( '%s extends AbstractWidget', $basename ),
		(bool) preg_match( '/class\s+' . preg_quote( $basename, '/' ) . '\s+extends\s+AbstractWidget/', $source ),
		'class declaration not found'
	);

	foreach ( $required_methods as $method ) {
		el_check(
			sprintf( '%s defines %s()', $basename, $method ),
			(bool) preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\(/', $source ),
			'missing method'
		);
	}

	// get_name() must return a literal string.
	if ( preg_match( '/function\s+get_name\s*\(\s*\)\s*:\s*string\s*\{\s*return\s+\'([a-z0-9_]+)\'/', $source, $m ) ) {
		$name = $m[1];

		el_check(
			sprintf( '%s widget name "%s" is unique', $basename, $name ),
			! in_array( $name, $names, true ),
			'duplicate of another widget'
		);

		el_check(
			sprintf( '%s widget name is prefixed with rbw_', $basename ),
			str_starts_with( $name, 'rbw_' ),
			$name
		);

		$names[] = $name;
	} else {
		el_check( sprintf( '%s get_name() returns a literal string', $basename ), false, 'not parseable' );
	}

	// get_title() must be translated.
	el_check(
		sprintf( '%s get_title() is translatable', $basename ),
		(bool) preg_match( '/function\s+get_title\s*\(\s*\)\s*:\s*string\s*\{[^}]*__\(\s*\'[^\']+\',\s*\'ratebridge-woo\'/', $source ),
		'get_title() must return __( … ) with the plugin domain'
	);

	// Every user-facing label must use the plugin text domain.
	$bare_labels = preg_match_all( '/\'label\'\s*=>\s*\'([^\']+)\'/', $source );

	if ( $bare_labels > 0 ) {
		el_check(
			sprintf( '%s has no untranslated \'label\' literals', $basename ),
			false,
			$bare_labels . ' bare label(s) found'
		);
	} else {
		el_check( sprintf( '%s has no untranslated \'label\' literals', $basename ), true );
	}

	$classes[] = $basename;
}

/* ------------------------------------------------------------------------- */
echo "\n[2] Category + shared base\n";
/* ------------------------------------------------------------------------- */
$integration = el_test_read( $root . '/includes/Elementor/ElementorIntegration.php' );

el_check(
	'category slug is ratebridge-woo',
	str_contains( $integration, "const CATEGORY = 'ratebridge-woo'" )
);
el_check(
	'minimum version is declared as a constant',
	(bool) preg_match( '/const MIN_VERSION\s*=\s*\'([0-9.]+)\'/', $integration )
);
el_check(
	'widgets registered through elementor/widgets/register',
	str_contains( $integration, "'elementor/widgets/register'" )
);
el_check(
	'category registered through elementor/elements/categories_registered',
	str_contains( $integration, "'elementor/elements/categories_registered'" )
);
el_check(
	'registration is version-guarded (is_supported)',
	str_contains( $integration, 'is_supported' )
);
el_check(
	'every widget class is listed for registration',
	count( $widget_files ) === count( array_filter(
		array( 'RatesTable', 'Ticker', 'CurrencyRate', 'ProductCurrency', 'ProductPrice', 'ProductUpdated', 'SiteUpdated' ),
		static fn ( string $c ): bool => str_contains( $integration, "'{$c}'" )
	) ),
	'not every widget appears in widget_classes()'
);

$base = el_test_read( $base_file );

el_check( 'base returns the plugin category', str_contains( $base, 'ElementorIntegration::CATEGORY' ) );
el_check( 'base defines get_categories()', (bool) preg_match( '/public function get_categories\(\s*\)\s*:\s*array/', $base ) );
el_check( 'base declares the style dependency', str_contains( $base, 'get_style_depends' ) );
el_check( 'base has the editor-only placeholder helper', str_contains( $base, 'function placeholder(' ) );
el_check( 'base reads settings without Elementor sanitisers', str_contains( $base, 'function raw_settings(' ) );
el_check( 'base never calls the sanitising accessor unconditionally', ! str_contains( $base, '$this->get_settings_for_display();' ) || str_contains( $base, 'if ( $this->has_live_document() )' ) );

/* ------------------------------------------------------------------------- */
echo "\n[3] No duplicated rendering logic (widgets delegate)\n";
/* ------------------------------------------------------------------------- */
foreach ( $widget_files as $file ) {
	$source   = el_test_read( $file );
	$basename = basename( $file, '.php' );

	// A widget must not format numbers/rates itself.
	$formats = preg_match_all( '/number_format\s*\(/', $source );

	el_check(
		sprintf( '%s does not format numbers itself', $basename ),
		0 === $formats,
		$formats > 0 ? 'use Frontend::format_rate()/RateFormatter' : ''
	);

	// ... and must reach the shared layer.
	$delegates = str_contains( $source, '$this->shortcodes()' )
		|| str_contains( $source, 'Frontend::' )
		|| str_contains( $source, 'ProductNotice' );

	el_check( sprintf( '%s renders through the shared Frontend layer', $basename ), $delegates );
}

/* ------------------------------------------------------------------------- */
echo "\n[4] Output safety\n";
/* ------------------------------------------------------------------------- */
foreach ( $widget_files as $file ) {
	$source   = el_test_read( $file );
	$basename = basename( $file, '.php' );

	/*
	 * Every echo must be one of the vetted sinks: HTML a helper already built
	 * ($html from Frontend/Shortcodes/ProductNotice, which apply wp_kses/esc_html)
	 * or the editor-only placeholder helper.
	 */
	$echoes = array();

	if ( preg_match_all( '/echo\s+([^;]+);/', $source, $m ) ) {
		$echoes = $m[1];
	}

	$unsafe = 0;

	foreach ( $echoes as $expression ) {
		$expression = trim( (string) $expression );

		$is_allowed = str_starts_with( $expression, '$html' )
			|| str_starts_with( $expression, '$this->placeholder(' )
			|| str_starts_with( $expression, '$this->apply_labels(' )
			|| str_starts_with( $expression, 'Frontend::wrap(' );

		// A justified inline echo (esc_html/wp_kses in the expression) also passes.
		if ( ! $is_allowed && ! preg_match( '/esc_html|wp_kses|esc_attr/', $expression ) ) {
			++$unsafe;
		}
	}

	el_check(
		sprintf( '%s only echoes vetted HTML sinks', $basename ),
		0 === $unsafe,
		$unsafe > 0 ? 'raw echo found in ' . $unsafe . ' place(s)' : ''
	);
}

/* ------------------------------------------------------------------------- */
echo "\n[5] Product widgets resolve the product safely\n";
/* ------------------------------------------------------------------------- */
foreach ( array( 'ProductPrice.php', 'ProductUpdated.php', 'ProductCurrency.php' ) as $file ) {
	$source = el_test_read( $widget_dir . '/' . $file );

	el_check(
		sprintf( '%s resolves the product through resolve_product()', basename( $file, '.php' ) ),
		str_contains( $source, 'resolve_product(' ) || str_contains( $source, 'this->product_currency(' )
	);
}

/* ------------------------------------------------------------------------- */
echo "\n[6] Shortcodes keep working alongside the widgets\n";
/* ------------------------------------------------------------------------- */
$shortcodes = el_test_read( $root . '/includes/Frontend/Shortcodes.php' );

foreach ( array( 'rbw_rate', 'rbw_rates', 'rbw_ticker', 'rbw_product_rate', 'rbw_product_updated' ) as $tag ) {
	el_check(
		sprintf( "shortcode [%s] is still registered", $tag ),
		(bool) preg_match( '/add_shortcode\(\s*\'' . preg_quote( $tag, '/' ) . '\'/', $shortcodes )
	);
}

el_check(
	'render_ticker() is public (shared with the Ticker widget)',
	(bool) preg_match( '/public function render_ticker\(/', $shortcodes )
);
el_check(
	'render_table() is public (shared with the RatesTable widget)',
	(bool) preg_match( '/public function render_table\(/', $shortcodes )
);
el_check(
	'render_rate_item() is public (shared with the CurrencyRate widget)',
	(bool) preg_match( '/public function render_rate_item\(/', $shortcodes )
);

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", $el_passed, $el_failed );

exit( $el_failed > 0 ? 1 : 0 );
