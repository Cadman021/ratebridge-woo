<?php
/**
 * Verifies that every `url(...)` reference in the plugin's stylesheets points at
 * a file that actually exists.
 *
 * Why: `assets/admin/css/tokens.css` shipped `url("../../../fonts/...")`, which
 * resolves one directory ABOVE the plugin root, so all three Vazirmatn faces
 * returned 404 on every admin page. A broken font is silent — the browser just
 * falls back — so it needs a test rather than a visual check.
 *
 * No WordPress: this reads the filesystem only.
 *
 * Run from the plugin root:
 *     php tests/css-assets.php
 *
 * Exit code 0 = every reference resolves, 1 = at least one is broken.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

/**
 * PASS/FAIL collector.
 */
final class CssAssetAssert {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Record a check.
	 *
	 * @param string $label Description.
	 * @param bool   $ok    Result.
	 * @param string $info  Extra context on failure.
	 * @return void
	 */
	public static function check( string $label, bool $ok, string $info = '' ): void {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );

			return;
		}

		++self::$failed;
		printf( "  FAIL  %s%s\n", $label, '' !== $info ? "\n          {$info}" : '' );
	}
}

/**
 * Recursively list files with a given extension.
 *
 * @param string $dir Root directory.
 * @param string $ext Extension without the dot.
 * @return string[] Absolute paths, sorted.
 */
function css_assets_list( string $dir, string $ext ): array {
	$found = array();

	if ( ! is_dir( $dir ) ) {
		return $found;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && strtolower( $file->getExtension() ) === $ext ) {
			$found[] = $file->getPathname();
		}
	}

	sort( $found );

	return $found;
}

/**
 * Extract every `url(...)` occurrence from a stylesheet with its line number.
 *
 * Handles single quotes, double quotes and bare values, plus whitespace inside
 * the parentheses. Comments are stripped first so a documented example cannot
 * produce a false positive.
 *
 * @param string $css Stylesheet contents.
 * @return array<int, array{value:string,line:int}>
 */
function css_assets_urls( string $css ): array {
	// Strip /* … */ comments but preserve line count so reported lines stay true.
	$stripped = preg_replace_callback(
		'#/\*.*?\*/#s',
		static function ( array $m ): string {
			return str_repeat( "\n", substr_count( $m[0], "\n" ) );
		},
		$css
	);

	if ( null === $stripped ) {
		$stripped = $css;
	}

	$matches = array();

	if ( ! preg_match_all( '/url\(\s*(["\']?)([^"\')]*)\1\s*\)/i', $stripped, $all, PREG_OFFSET_CAPTURE ) ) {
		return $matches;
	}

	foreach ( $all[2] as $index => $pair ) {
		$value  = trim( $pair[0] );
		$offset = $pair[1];

		$matches[] = array(
			'value' => $value,
			'line'  => substr_count( substr( $stripped, 0, $offset ), "\n" ) + 1,
		);
	}

	return $matches;
}

/**
 * Resolve a CSS url() the way a browser does, relative to the stylesheet.
 *
 * @param string $css_dir Directory holding the stylesheet.
 * @param string $value   Raw url() value.
 * @return string|null Absolute normalised path, or null when not a local ref.
 */
function css_assets_resolve( string $css_dir, string $value ): ?string {
	if ( '' === $value ) {
		return null;
	}

	// Not our business: data URIs, absolute URLs, scheme-relative, fragments.
	if ( preg_match( '#^(data:|https?:|//|\#|mailto:)#i', $value ) ) {
		return null;
	}

	// Drop any query string / fragment (cache busters).
	$path = preg_split( '/[?#]/', $value )[0];

	if ( '' === $path ) {
		return null;
	}

	// Absolute filesystem path (rare, but resolvable).
	if ( '/' === $path[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) ) {
		$candidate = $path;
	} else {
		$candidate = $css_dir . '/' . $path;
	}

	$real = realpath( $candidate );

	if ( false !== $real ) {
		return $real;
	}

	// realpath() fails on a missing file; return the intended absolute path so the
	// failure message is useful.
	$parts = array();

	foreach ( explode( '/', str_replace( '\\', '/', $candidate ) ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}

		if ( '..' === $segment ) {
			array_pop( $parts );
			continue;
		}

		$parts[] = $segment;
	}

	$prefix = preg_match( '#^[A-Za-z]:#', $candidate ) ? '' : '/';

	return $prefix . implode( '/', $parts );
}

$root       = dirname( __DIR__ );
$assets_dir = $root . '/assets';

echo "RateBridge Woo — stylesheet asset references\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Every url() in assets/**/*.css resolves\n";
/* ------------------------------------------------------------------------- */

$sheets    = css_assets_list( $assets_dir, 'css' );
$total     = 0;
$local     = 0;
$skipped   = 0;
$fragments = array();

CssAssetAssert::check( 'found at least one stylesheet', ! empty( $sheets ), 'none under ' . $assets_dir );

foreach ( $sheets as $sheet ) {
	$relative = str_replace( '\\', '/', substr( $sheet, strlen( $root ) + 1 ) );
	$css      = (string) file_get_contents( $sheet );
	$css_dir  = dirname( $sheet );

	foreach ( css_assets_urls( $css ) as $url ) {
		++$total;
		$value = $url['value'];
		$label = sprintf( '%s:%d  url("%s")', $relative, $url['line'], $value );

		if ( preg_match( '#^(data:|https?:|//|\#|mailto:)#i', $value ) ) {
			++$skipped;

			// Not required to exist locally, but record it so the count is honest.
			$fragments[] = $label . '  (external)';
			continue;
		}

		++$local;

		$resolved = css_assets_resolve( $css_dir, $value );

		if ( null === $resolved ) {
			continue;
		}

		$exists = is_file( $resolved );
		$short  = str_replace( '\\', '/', substr( $resolved, strlen( $root ) + 1 ) );

		CssAssetAssert::check(
			$label,
			$exists,
			$exists ? '' : sprintf( 'resolved to "%s" which does not exist under the plugin', $short )
		);
	}
}

/* ------------------------------------------------------------------------- */
echo "\n[2] The Vazirmatn faces are reachable from tokens.css\n";
/* ------------------------------------------------------------------------- */

/*
 * Regression guard for the Phase 9c bug: `assets/admin/css/tokens.css` must use
 * `../../fonts/`, because `../../../fonts/` escapes the plugin directory. This is
 * asserted explicitly so a future path edit fails loudly with a clear reason
 * instead of only "file does not exist".
 */
$tokens = $assets_dir . '/admin/css/tokens.css';

CssAssetAssert::check( 'tokens.css exists', is_file( $tokens ) );

if ( is_file( $tokens ) ) {
	$tokens_css = (string) file_get_contents( $tokens );

	foreach ( array( 'Regular', 'Medium', 'Bold' ) as $weight ) {
		$expect = '../../fonts/Vazirmatn-' . $weight . '.woff2';
		$target = $assets_dir . '/fonts/Vazirmatn-' . $weight . '.woff2';

		CssAssetAssert::check(
			sprintf( 'tokens.css references %s with the correct depth', $weight ),
			str_contains( $tokens_css, 'url("' . $expect . '")' ),
			'expected url("' . $expect . '")'
		);

		CssAssetAssert::check( sprintf( '%s.woff2 is on disk', $weight ), is_file( $target ) );

		CssAssetAssert::check(
			sprintf( 'tokens.css does not escape the plugin directory for %s', $weight ),
			! str_contains( $tokens_css, '../../../fonts/' ),
			'found ../../../fonts/ which resolves above the plugin root'
		);
	}
}

/* ------------------------------------------------------------------------- */
echo "\n[3] The font license ships with the font\n";
/* ------------------------------------------------------------------------- */

$ofl = $assets_dir . '/fonts/OFL.txt';

CssAssetAssert::check( 'assets/fonts/OFL.txt exists', is_file( $ofl ) );

if ( is_file( $ofl ) ) {
	$license = (string) file_get_contents( $ofl );

	CssAssetAssert::check(
		'it is the SIL Open Font License 1.1',
		str_contains( $license, 'SIL OPEN FONT LICENSE Version 1.1' )
	);
	CssAssetAssert::check( 'it names Vazirmatn', str_contains( $license, 'Vazirmatn' ) );
	CssAssetAssert::check(
		'it carries the upstream copyright line',
		(bool) preg_match( '/Copyright.*Rastikerdar/i', $license )
	);
}

/* ------------------------------------------------------------------------- */
echo "\n[4] No PHP-emitted @font-face with a relative url()\n";
/* ------------------------------------------------------------------------- */

/*
 * The plugin enqueues its stylesheets through wp_enqueue_style(), so a relative
 * url() inside a .css file resolves against that file. A relative url() emitted
 * from PHP would resolve against the PAGE url instead and break — so PHP must not
 * declare @font-face at all. Guard that.
 */
$php_files = css_assets_list( $root . '/includes', 'php' );
$php_files = array_merge( $php_files, css_assets_list( $root . '/templates', 'php' ) );
$offenders = array();

foreach ( $php_files as $php_file ) {
	$source = (string) file_get_contents( $php_file );

	if ( preg_match( '/@font-face/i', $source ) ) {
		$offenders[] = str_replace( '\\', '/', substr( $php_file, strlen( $root ) + 1 ) );
	}
}

CssAssetAssert::check(
	'no PHP file declares @font-face (fonts stay in tokens.css)',
	empty( $offenders ),
	implode( ', ', $offenders )
);

/* ------------------------------------------------------------------------- */
echo "\n[5] Summary\n";
/* ------------------------------------------------------------------------- */

printf( "  %d stylesheet(s), %d url() reference(s): %d local, %d external.\n", count( $sheets ), $total, $local, $skipped );

foreach ( $fragments as $fragment ) {
	printf( "        %s\n", $fragment );
}

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", CssAssetAssert::$passed, CssAssetAssert::$failed );

exit( CssAssetAssert::$failed > 0 ? 1 : 0 );
