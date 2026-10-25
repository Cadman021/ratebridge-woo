<?php
/**
 * Guards against the Phase-2 regression where admin.js sent `action=rbw_save`
 * but the server only hooked `wp_ajax_rbw_save_currency`, so admin-ajax.php
 * answered a bare "0" and no handler ever ran.
 *
 * No WordPress needed: it reads admin.js and AjaxController.php as text.
 *
 * Run from the plugin root:
 *     php tests/ajax-actions.php
 *
 * Exit code 0 = every JS action has a registered hook, 1 = mismatch found.
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
function rbw_test_read( string $path ): string {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Cannot read: {$path}\n" );
		exit( 1 );
	}

	return (string) file_get_contents( $path );
}

/**
 * Extract every `request( '<slug>'` call from admin.js.
 *
 * @param string $js admin.js contents.
 * @return string[] Unique slugs.
 */
function rbw_js_actions( string $js ): array {
	$found = array();

	// Matches request( 'save', ... ) and request( "save", ... ).
	if ( preg_match_all( '/\brequest\(\s*[\'"]([a-z0-9_]+)[\'"]/', $js, $matches ) ) {
		$found = $matches[1];
	}

	$found = array_values( array_unique( $found ) );
	sort( $found );

	return $found;
}

/**
 * Extract the dispatcher slug list from AjaxController::actions().
 *
 * @param string $php AjaxController.php contents.
 * @return string[]
 */
function rbw_controller_actions( string $php ): array {
	$start = strpos( $php, 'public static function actions(): array' );

	if ( false === $start ) {
		fwrite( STDERR, "Could not find AjaxController::actions()\n" );
		exit( 1 );
	}

	$end   = strpos( $php, ');', $start );
	$chunk = substr( $php, $start, (int) $end - $start );

	$found = array();

	if ( preg_match_all( '/^\s*\'([a-z0-9_]+)\',/m', $chunk, $matches ) ) {
		$found = $matches[1];
	}

	$found = array_values( array_unique( $found ) );
	sort( $found );

	return $found;
}

/**
 * Extract the `case '...':` labels from the dispatcher switch.
 *
 * @param string $php AjaxController.php contents.
 * @return string[]
 */
function rbw_switch_cases( string $php ): array {
	$found = array();

	if ( preg_match_all( '/\bcase\s+\'([a-z0-9_]+)\':/', $php, $matches ) ) {
		$found = $matches[1];
	}

	$found = array_values( array_unique( $found ) );
	sort( $found );

	return $found;
}

$js   = rbw_test_read( $root . '/assets/admin/js/admin.js' );
$php  = rbw_test_read( $root . '/includes/Admin/AjaxController.php' );

$js_actions    = rbw_js_actions( $js );
$registered    = rbw_controller_actions( $php );
$switch_cases  = rbw_switch_cases( $php );

$passed = 0;
$failed = 0;

/**
 * Record a check.
 *
 * @param string $label Description.
 * @param bool   $ok    Result.
 * @param string $info  Extra info on failure.
 * @return void
 */
function check( string $label, bool $ok, string $info = '' ): void {
	global $passed, $failed;

	if ( $ok ) {
		++$passed;
		printf( "  PASS  %s\n", $label );
		return;
	}

	++$failed;
	printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
}

echo "RateBridge Woo — AJAX action wiring\n";
echo str_repeat( '=', 60 ), "\n\n";

printf( "JS request() slugs      : %s\n", implode( ', ', $js_actions ) );
printf( "Registered wp_ajax hooks: %s\n", implode( ', ', $registered ) );
printf( "Dispatcher switch cases : %s\n\n", implode( ', ', $switch_cases ) );

echo "[1] Every JS action has a registered hook (`wp_ajax_rbw_{slug}`)\n";

foreach ( $js_actions as $slug ) {
	check(
		sprintf( 'request( \'%s\' ) -> wp_ajax_rbw_%s', $slug, $slug ),
		in_array( $slug, $registered, true ),
		'not in AjaxController::actions()'
	);
}

echo "\n[2] Every registered hook has a dispatcher case\n";

foreach ( $registered as $slug ) {
	check(
		sprintf( 'actions() \'%s\' handled by switch', $slug ),
		in_array( $slug, $switch_cases, true ),
		'no case in handle()'
	);
}

echo "\n[3] No orphan dispatcher cases\n";

foreach ( $switch_cases as $slug ) {
	check(
		sprintf( 'case \'%s\' is declared in actions()', $slug ),
		in_array( $slug, $registered, true ),
		'unreachable (would be rejected by the whitelist)'
	);
}

echo "\n[4] No orphan JS actions\n";

foreach ( $js_actions as $slug ) {
	check(
		sprintf( 'JS \'%s\' is used by the controller', $slug ),
		in_array( $slug, $registered, true ),
		'JS calls an action the server does not know'
	);
}

echo "\n[5] Legacy aliases are still registered\n";

if ( preg_match( '/function legacy_aliases\(\): array\s*\{(.*?)
	\}/s', $php, $m ) ) {
	$aliases = array();
	if ( preg_match_all( '/\'(rbw_[a-z0-9_]+)\'\s*=>/', $m[1], $am ) ) {
		$aliases = $am[1];
	}

	check( 'legacy_aliases() is non-empty', ! empty( $aliases ), 'no aliases found' );

	foreach ( $aliases as $alias ) {
		// Mirror AjaxController::resolve_action(): strip `rbw_` and the legacy
		// `_currency` suffix before checking the slug.
		$slug = substr( $alias, 4 );

		// Only strip the legacy suffix when the result is a real action
		// (`get_currency` must stay intact).
		if ( str_ends_with( $slug, '_currency' ) ) {
			$stripped = substr( $slug, 0, -9 );

			if ( in_array( $stripped, $registered, true ) ) {
				$slug = $stripped;
			}
		}

		check( "alias {$alias} resolves to '{$slug}'", in_array( $slug, $registered, true ) );
	}
} else {
	check( 'legacy_aliases() present', false, 'method not found' );
}

echo "\n[6] admin.js sends the rbw_ prefix exactly once\n";

check(
	"request() builds 'rbw_' + action",
	(bool) preg_match( '/body\.append\(\s*[\'"]action[\'"]\s*,\s*[\'"]rbw_[\'"]\s*\+\s*action\s*\)/', $js )
);

check(
	"request() also sends rbw_action",
	(bool) preg_match( '/body\.append\(\s*[\'"]rbw_action[\'"]\s*,\s*action\s*\)/', $js )
);

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
