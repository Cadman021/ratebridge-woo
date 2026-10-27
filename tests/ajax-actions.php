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

$js         = rbw_test_read( $root . '/assets/admin/js/admin.js' );
$product_js = rbw_test_read( $root . '/assets/admin/js/product.js' );
$php        = rbw_test_read( $root . '/includes/Admin/AjaxController.php' );

/*
 * Phase 3: the product editor builds its request body by hand
 * (`body.append( 'action', 'rbw_' + config.action )`), where config.action is
 * the value localised by ProductFields::PREVIEW_ACTION. Pick it up as well so a
 * renamed preview action fails this test instead of returning a bare "0".
 */
$preview_action = '';

$preview_pattern = '/PREVIEW_ACTION\s*=\s*["\x27]([a-z0-9_]+)["\x27]/';

if ( preg_match( $preview_pattern, $php, $preview_match ) ) {
	$preview_action = $preview_match[1];
}

$product_preview_action = '';

$fields_php = rbw_test_read( $root . '/includes/Product/ProductFields.php' );

if ( str_contains( $fields_php, "=> AjaxController::PREVIEW_ACTION" ) ) {
	$product_preview_action = $preview_action;
}

$js_actions = rbw_js_actions( $js );

// Every literal `request( 'x' )` in product.js counts too.
foreach ( rbw_js_actions( $product_js ) as $extra ) {
	if ( ! in_array( $extra, $js_actions, true ) ) {
		$js_actions[] = $extra;
	}
}

if ( '' !== $product_preview_action ) {
	$js_actions[] = $product_preview_action;
}

$js_actions = array_values( array_unique( $js_actions ) );
sort( $js_actions );
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

echo "\n[7] Product editor preview endpoint (Phase 3)\n";

check(
	'ProductFields::PREVIEW_ACTION is readable',
	'' !== $preview_action,
	'constant not found in AjaxController.php'
);

check(
	"'{$preview_action}' is registered as wp_ajax_rbw_{$preview_action}",
	'' !== $preview_action && in_array( $preview_action, $registered, true ),
	'not in AjaxController::actions()'
);

check(
	"'{$preview_action}' has a dispatcher case",
	'' !== $preview_action && in_array( $preview_action, $switch_cases, true ),
	'no case in handle()'
);

check(
	'product.js posts the preview with the rbw_ prefix',
	(bool) preg_match( '/body\.append\(\s*[\'"]action[\'"]\s*,\s*[\'"]rbw_[\'"]\s*\+\s*config\.action\s*\)/', $product_js )
);

check(
	'ProductFields localises the same action',
	'' !== $product_preview_action,
	"'action' => AjaxController::PREVIEW_ACTION missing in ProductFields.php"
);

check(
	'the unit-migration action is registered',
	in_array( 'migrate_unit', $registered, true )
);

check(
	'the stale-notice dismissal action is registered',
	in_array( 'dismiss_stale_notice', $registered, true )
);

echo "
[8] Phase 4 settings travel with save_settings
";

// The General tab's Phase-4 fields must be part of the settings payload, both in
// the JS and in the controller, otherwise they are silently dropped on save.
$admin_js = rbw_test_read( $root . '/assets/admin/js/admin.js' );
$ajax_php = rbw_test_read( $root . '/includes/Admin/AjaxController.php' );

foreach (
	array(
		'show_rate_in_orders'    => 'the customer order notice toggle',
		'product_block_enabled'  => 'the product page block toggle',
		'product_block_position' => 'the product page block position',
		'product_block_template' => 'the product page block template',
		'date_format'            => 'the date format',
		'use_persian_digits'     => 'the Persian digits toggle',
	) as $field => $description
) {
	check(
		"admin.js sends {$field} ({$description})",
		str_contains( $admin_js, $field ),
		'not found in assets/admin/js/admin.js'
	);

	check(
		"save_settings reads {$field}",
		str_contains( $ajax_php, "'{$field}'" ),
		'not handled in AjaxController::save_settings()'
	);
}

$settings_php = rbw_test_read( $root . '/includes/Core/Settings.php' );

foreach ( array( 'show_rate_in_orders', 'product_block_enabled', 'product_block_position', 'product_block_template', 'date_format', 'use_persian_digits' ) as $field ) {
	check(
		"Settings::defaults() declares {$field}",
		(bool) preg_match( "/'" . preg_quote( $field, '/' ) . "'\s*=>/", $settings_php ),
		'missing from Settings::defaults()'
	);
}

echo "\n[9] Products list column: no double render, no bleed into price\n";

$fields_php = rbw_test_read( $root . '/includes/Product/ProductFields.php' );

/*
 * Phase 7.1 regression: the «ارز» column was rendered through TWO actions
 * (`manage_product_posts_custom_column` + `manage_posts_custom_column`) with a
 * per-post "already rendered" static. Depending on hook order the cell appeared
 * inside the Price column and the currency column stayed empty, shifting both.
 */
check(
	'column adds itself on manage_product_posts_columns',
	(bool) preg_match( "/add_filter\(\\s*'manage_product_posts_columns'/s", $fields_php )
);

check(
	'cell renders through manage_product_posts_custom_column once',
	1 === preg_match_all( "/add_action\(\\s*'manage_product_posts_custom_column'/s", $fields_php ),
	'multiple registrations duplicate the cell'
);

check(
	'classic hook uses a separate guarded callback',
	(bool) preg_match( "/add_action\(\\s*'manage_posts_custom_column'\\s*,\\s*array\\(\\s*\\\$this,\\s*'render_column_classic'/s", $fields_php )
);

check(
	'no per-post static "already rendered" cache',
	! str_contains( $fields_php, 'static $rendered' ),
	'a static cache swallows the cell on the second pass'
);

$classic_start = strpos( $fields_php, 'function render_column_classic' );
$classic_body  = false === $classic_start ? '' : substr( $fields_php, $classic_start, 600 );

check(
	'classic callback skips products',
	str_contains( $classic_body, "'product' === " . chr( 36 ) . 'post_type' ),
	'products are rendered by the WooCommerce list table only'
);

check(
	'cell printer is private and stateless',
	(bool) preg_match( '/private function print_column\\( int \\$post_id \\): void/', $fields_php )
);

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
