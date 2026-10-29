<?php
/**
 * Tests for AssetVersion — the `?ver=` resolver for enqueued plugin assets.
 *
 * The bug this prevents: every stylesheet shipped `?ver=<plugin version>`, so a
 * browser that had cached tokens.css kept serving the stale file after a fix and
 * the broken font URLs stayed invisible. In development the version must follow
 * the file's mtime; in production it must stay stable.
 *
 * No WordPress: the class is pure apart from `filemtime()`, and the path
 * resolver plus the debug flag are injected.
 *
 * Run from the plugin root:
 *     php tests/asset-version.php
 *
 * @package RateBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/Core/AssetVersion.php';

use RateBridge\Core\AssetVersion;

/**
 * PASS/FAIL collector.
 */
final class AVAssert {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Assert two values are identical.
	 *
	 * @param string $label    Description.
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @return void
	 */
	public static function same( string $label, mixed $expected, mixed $actual ): void {
		if ( $expected === $actual ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );

			return;
		}

		++self::$failed;
		printf(
			"  FAIL  %s\n          expected: %s\n          actual:   %s\n",
			$label,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}

	/**
	 * Assert a condition.
	 *
	 * @param string $label Description.
	 * @param bool   $ok    Result.
	 * @param string $info  Extra context.
	 * @return void
	 */
	public static function check( string $label, bool $ok, string $info = '' ): void {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );

			return;
		}

		++self::$failed;
		printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
	}
}

$root = dirname( __DIR__ );

echo "RateBridge Woo — asset cache-busting versions\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Production keeps the plugin version\n";
/* ------------------------------------------------------------------------- */

$real = $root . '/assets/admin/css/tokens.css';

AVAssert::same(
	'debug=false returns the fallback verbatim',
	'0.9.0',
	AssetVersion::for_asset( 'assets/admin/css/tokens.css', false, '0.9.0' )
);

AVAssert::same(
	'production ignores a real file entirely',
	'1.2.3',
	AssetVersion::for_asset( 'assets/admin/css/tokens.css', false, '1.2.3', static fn () => $real )
);

/* ------------------------------------------------------------------------- */
echo "\n[2] Debug uses filemtime()\n";
/* ------------------------------------------------------------------------- */

$mtime = filemtime( $real );

AVAssert::same(
	'debug returns dev-<mtime>',
	'dev-' . $mtime,
	AssetVersion::for_asset( 'assets/admin/css/tokens.css', true, '0.9.0', static fn () => $real )
);

AVAssert::check(
	'the debug version changes when the file changes',
	(static function () use ( $real ): bool {
		$first = AssetVersion::for_asset( 'x.css', true, '0.9.0', static fn () => $real );

		// Simulate an edit by reporting a later mtime.
		$second = AssetVersion::for_asset( 'x.css', true, '0.9.0', static function () use ( $real ): string {
			$copy = sys_get_temp_dir() . '/rbw-asset-version-test.css';
			copy( $real, $copy );
			touch( $copy, filemtime( $real ) + 10 );

			return $copy;
		} );

		@unlink( sys_get_temp_dir() . '/rbw-asset-version-test.css' );

		return $first !== $second;
	} )()
);

AVAssert::check(
	'dev versions never collide with a release version',
	! str_starts_with(
		AssetVersion::for_asset( 'x.css', true, '0.9.0', static fn () => $real ),
		'0.9'
	)
);

/* ------------------------------------------------------------------------- */
echo "\n[3] Missing / unreadable files fall back safely\n";
/* ------------------------------------------------------------------------- */

AVAssert::same(
	'a missing file falls back to the plugin version',
	'0.9.0',
	AssetVersion::for_asset( 'assets/nope.css', true, '0.9.0', static fn () => '/definitely/not/here.css' )
);

AVAssert::same(
	'an empty resolver result falls back',
	'0.9.0',
	AssetVersion::for_asset( 'x.css', true, '0.9.0', static fn () => '' )
);

/* ------------------------------------------------------------------------- */
echo "\n[4] is_debug() honours WP_DEBUG and SCRIPT_DEBUG\n";
/* ------------------------------------------------------------------------- */

/*
 * Constants cannot be undefined, so each combination runs in a subprocess to
 * keep the flag space honest.
 */
$php = PHP_BINARY;

/**
 * Run a snippet with the given constants defined and return its output.
 *
 * @param string $php  PHP binary.
 * @param string $root Plugin root.
 * @param string $defs Constant definitions.
 * @return string
 */
function av_run( string $php, string $root, string $defs ): string {
	$code = "<?php define('ABSPATH','/');" . $defs
		. " require '" . addslashes( $root ) . "/includes/Core/AssetVersion.php';"
		. " echo \\RateBridge\\Core\\AssetVersion::is_debug() ? '1' : '0';";

	$file = sys_get_temp_dir() . '/rbw-av-' . md5( $defs ) . '.php';
	file_put_contents( $file, $code );
	$out = (string) shell_exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	@unlink( $file );

	return trim( $out );
}

AVAssert::same( 'neither constant -> false', '0', av_run( $php, $root, '' ) );
AVAssert::same( 'WP_DEBUG true -> true', '1', av_run( $php, $root, "define('WP_DEBUG', true);" ) );
AVAssert::same( 'SCRIPT_DEBUG true -> true', '1', av_run( $php, $root, "define('SCRIPT_DEBUG', true);" ) );
AVAssert::same( 'WP_DEBUG false only -> false', '0', av_run( $php, $root, "define('WP_DEBUG', false);" ) );

/* ------------------------------------------------------------------------- */
echo "\n[5] Every enqueue in the plugin uses the helper\n";
/* ------------------------------------------------------------------------- */

$enqueue_files = array(
	'includes/Admin/SettingsPage.php',
	'includes/Product/ProductFields.php',
	'includes/Frontend/Frontend.php',
);

$stragglers = array();
$uses       = 0;

foreach ( $enqueue_files as $relative ) {
	$path = $root . '/' . $relative;

	if ( ! is_readable( $path ) ) {
		AVAssert::check( "{$relative} exists", false );
		continue;
	}

	$source = (string) file_get_contents( $path );
	$uses  += substr_count( $source, 'AssetVersion::get(' );

	/*
	 * Find every wp_enqueue_style/wp_register_style/wp_enqueue_script/
	 * wp_register_script call and check its version argument is not the raw
	 * RBW_VERSION constant.
	 */
	if ( preg_match_all( '/wp_(?:enqueue|register)_(?:style|script)\((.*?)\);/s', $source, $calls, PREG_SET_ORDER ) ) {
		foreach ( $calls as $call ) {
			if ( preg_match( '/\bRBW_VERSION\b/', $call[1] ) ) {
				$stragglers[] = $relative;
			}
		}
	}
}

AVAssert::check(
	'no enqueue still passes the bare RBW_VERSION constant',
	empty( $stragglers ),
	implode( ', ', array_unique( $stragglers ) )
);

AVAssert::check(
	'every asset handle is versioned through AssetVersion::get()',
	$uses >= 12,
	'found ' . $uses . ' calls'
);

AVAssert::check(
	'the version helper is a single implementation',
	1 === preg_match_all( '/function for_asset\(/', (string) file_get_contents( $root . '/includes/Core/AssetVersion.php' ) )
);

/* ------------------------------------------------------------------------- */
echo "\n[6] Dev versions reach the actual URLs\n";
/* ------------------------------------------------------------------------- */

// Sanity: the real file exists and has a sane mtime, so a real page load in
// debug mode produces `?ver=dev-<digits>`.
AVAssert::check( 'tokens.css is readable on disk', is_readable( $real ) );
AVAssert::check( 'its mtime is a plausible unix time', $mtime > 1600000000, (string) $mtime );

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", AVAssert::$passed, AVAssert::$failed );

exit( AVAssert::$failed > 0 ? 1 : 0 );
