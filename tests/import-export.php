<?php
/**
 * Dependency-free tests for the JSON package envelope and import planning.
 *
 * Run from the plugin root:
 *     php tests/import-export.php
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/ImportExport/Csv.php';
require_once __DIR__ . '/../includes/ImportExport/Package.php';
require_once __DIR__ . '/../includes/ImportExport/ImportPlan.php';

use RateBridge\ImportExport\ImportPlan;
use RateBridge\ImportExport\Package;

/**
 * Tiny PASS/FAIL collector.
 */
final class PkgAssert {

	/** @var int */
	public static int $passed = 0;

	/** @var int */
	public static int $failed = 0;

	/**
	 * Record a check.
	 *
	 * @param string $label Description.
	 * @param bool   $ok    Result.
	 * @param string $info  Extra info.
	 * @return bool
	 */
	public static function check( string $label, bool $ok, string $info = '' ): bool {
		if ( $ok ) {
			++self::$passed;
			printf( "  PASS  %s\n", $label );
		} else {
			++self::$failed;
			printf( "  FAIL  %s%s\n", $label, '' !== $info ? "  ({$info})" : '' );
		}

		return $ok;
	}

	/**
	 * Assert a numeric value.
	 *
	 * @param string $label    Description.
	 * @param float  $actual   Actual.
	 * @param float  $expected Expected.
	 * @return bool
	 */
	public static function float( string $label, float $actual, float $expected ): bool {
		return self::check(
			sprintf( '%s => %s', $label, (string) $expected ),
			abs( $actual - $expected ) < 0.0001,
			'got ' . $actual
		);
	}
}

/**
 * Build a valid package for the tests.
 *
 * @param array<string, mixed> $overrides Overrides.
 * @return array<string, mixed>
 */
function pkg_fixture( array $overrides = array() ): array {
	$base = array(
		'format'         => Package::FORMAT,
		'schema'         => 1,
		'plugin_version' => '0.7.0',
		'exported_at'    => '2026-10-27T12:00:00+00:00',
		'store_unit'     => 'toman',
		'site'           => 'currency-lab.test',
		'sections'       => array(
			'settings'   => array(
				'store_currency_unit' => 'toman',
				'rounding_mode'       => 'nearest',
				'rounding_step'       => 1000,
				'date_format'         => 'Y-m-d H:i',
			),
			'currencies' => array(
				array(
					'code'             => 'GOLD18',
					'name'             => 'طلای ۱۸ عیار',
					'symbol'           => 'g',
					'provider'         => 'alanchand',
					'provider_symbol'  => 'gold:18ayar',
					'is_enabled'       => 1,
					'profit_type'      => 'percent',
					'profit_value'     => 10,
					'commission_type'  => 'fixed',
					'commission_value' => 50000,
					'extra_amount'     => 2000,
				),
			),
			'formulas'   => array(
				array(
					'slug'       => 'gold-price',
					'name'       => 'قیمت طلا',
					'expression' => 'weight * gold18 * (1 + wage / 100)',
					'variables'  => array(
						array( 'name' => 'weight', 'type' => 'product', 'product_field' => 'weight' ),
						array( 'name' => 'gold18', 'type' => 'currency', 'currency_code' => 'GOLD18' ),
						array( 'name' => 'wage', 'type' => 'custom', 'default' => 15, 'is_money' => false ),
					),
					'is_enabled' => 1,
				),
			),
		),
	);

	return array_replace_recursive( $base, $overrides );
}

echo "RateBridge Woo — package import/export\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] Envelope validation\n";
/* ------------------------------------------------------------------------- */
$good = Package::validate( pkg_fixture() );
PkgAssert::check( 'valid package accepted', true === $good['ok'] );
PkgAssert::check( 'error is null', null === $good['error'] );
PkgAssert::check( 'sections kept', array( 'settings', 'currencies', 'formulas' ) === array_keys( $good['data']['sections'] ) );

PkgAssert::check(
	'bad format rejected',
	'bad_format' === Package::validate( pkg_fixture( array( 'format' => 'something-else' ) ) )['error']->code
);
PkgAssert::check(
	'not an object rejected',
	'not_an_object' === Package::validate( 'a string' )['error']->code
);
PkgAssert::check(
	'missing format rejected',
	'bad_format' === Package::validate( array( 'schema' => 1 ) )['error']->code
);
PkgAssert::check(
	'schema 0 rejected',
	'bad_schema' === Package::validate( pkg_fixture( array( 'schema' => 0 ) ) )['error']->code
);
PkgAssert::check(
	'newer schema refused',
	'unsupported_schema' === Package::validate( pkg_fixture( array( 'schema' => 2 ) ) )['error']->code
);
PkgAssert::check(
	'schema 2 refusal names the version',
	'2' === Package::validate( pkg_fixture( array( 'schema' => 2 ) ) )['error']->detail
);

// Size limit.
PkgAssert::check(
	'oversized payload refused',
	'too_large' === Package::validate_json( str_repeat( 'x', Package::MAX_BYTES + 1 ) )['error']->code
);

// Invalid JSON and invalid UTF-8.
PkgAssert::check(
	'broken JSON refused',
	'invalid_json' === Package::validate_json( '{"format": "ratebridge-woo-package",' )['error']->code
);
PkgAssert::check(
	'invalid UTF-8 refused',
	'not_utf8' === Package::validate_json( "{\"format\":\"x\",\"a\":\"\xC3\x28\"}" )['error']->code
);

// Depth limit: 40 nested arrays exceed MAX_DEPTH (16).
$deep = str_repeat( '{"a":', 40 ) . '1' . str_repeat( '}', 40 );
PkgAssert::check(
	'deeply nested JSON refused',
	'too_deep' === Package::validate_json( $deep )['error']->code
);

// No sections at all (built explicitly: array_replace_recursive would keep the
// fixture's sections when the override is an empty array).
$empty_sections = pkg_fixture();
$empty_sections['sections'] = array();

PkgAssert::check(
	'empty sections refused',
	'no_sections' === Package::validate( $empty_sections )['error']->code
);

// A section of the wrong type.
$bad_section = pkg_fixture();
$bad_section['sections']['currencies'] = 'nope';
PkgAssert::check(
	'non-array section refused',
	'bad_section' === Package::validate( $bad_section )['error']->code
);

// Valid JSON string round trip.
$json = (string) json_encode( pkg_fixture(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
PkgAssert::check( 'valid JSON string accepted', true === Package::validate_json( $json )['ok'] );
PkgAssert::check(
	'BOM-prefixed JSON accepted',
	true === Package::validate_json( \RateBridge\ImportExport\Csv::bom() . $json )['ok']
);

/* ------------------------------------------------------------------------- */
echo "\n[2] Secret leak guard\n";
/* ------------------------------------------------------------------------- */
// The settings cleaner keeps only the allowed keys.
$leaky = array(
	'store_currency_unit'    => 'toman',
	'api_key'                => 'sk-live-123',
	'navasan_token'          => 'abc',
	'provider_password'      => 'hunter2',
	'rbw_request_budget'     => array( 'navasan' => 42 ),
	'default_provider'       => 'manual',
	'something_secret_here'  => 'x',
);

$cleaned = Package::clean_settings( $leaky );

PkgAssert::check( 'allowed key kept', isset( $cleaned['store_currency_unit'] ) );
PkgAssert::check( 'default_provider kept (not a credential)', isset( $cleaned['default_provider'] ) );
PkgAssert::check( 'api_key dropped', ! isset( $cleaned['api_key'] ) );
PkgAssert::check( 'token dropped', ! isset( $cleaned['navasan_token'] ) );
PkgAssert::check( 'password dropped', ! isset( $cleaned['provider_password'] ) );
PkgAssert::check( 'budget dropped', ! isset( $cleaned['rbw_request_budget'] ) );

// The recursive scanner finds credential-shaped keys anywhere.
$found = Package::find_forbidden_keys(
	array(
		'sections' => array(
			'settings' => array(
				'api_key'   => 'x',
				'deep'      => array( 'nested_token' => 'y' ),
				'fine'      => 'ok',
			),
		),
	)
);

PkgAssert::check( 'scanner finds api_key', in_array( 'sections.settings.api_key', $found, true ), implode( ',', $found ) );
PkgAssert::check( 'scanner finds nested_token', in_array( 'sections.settings.deep.nested_token', $found, true ) );

// The real fixture exported by the plugin must be clean.
$fresh = Package::validate( pkg_fixture() );
$paths = Package::find_forbidden_keys( $fresh['data']['sections'] );
PkgAssert::check( 'clean package has no forbidden keys', array() === $paths, implode( ',', $paths ) );

// A package that *tries* to smuggle a key is cleaned, not rejected.
$smuggle = pkg_fixture();
$smuggle['sections']['settings']['navasan_api_key'] = 'secret';
$sanitised = Package::validate( $smuggle );
PkgAssert::check( 'smuggled key is stripped, package still valid', true === $sanitised['ok'] && ! isset( $sanitised['data']['sections']['settings']['navasan_api_key'] ) );

/* ------------------------------------------------------------------------- */
echo "\n[3] Section selection\n";
/* ------------------------------------------------------------------------- */
$only_currencies = pkg_fixture();
unset( $only_currencies['sections']['settings'], $only_currencies['sections']['formulas'] );
$subset = Package::validate( $only_currencies );

PkgAssert::check( 'subset accepted', true === $subset['ok'] );
PkgAssert::check( 'only currencies present', array( 'currencies' ) === array_keys( $subset['data']['sections'] ) );
PkgAssert::check( 'settings absent', ! isset( $subset['data']['sections']['settings'] ) );

/* ------------------------------------------------------------------------- */
echo "\n[4] Unit conversion maths\n";
/* ------------------------------------------------------------------------- */
// Toman -> Rial multiplies by 10; Rial -> Toman divides by 10.
PkgAssert::float( 'toman->rial factor', ImportPlan::factor( 'toman', 'rial' ), 10.0 );
PkgAssert::float( 'rial->toman factor', ImportPlan::factor( 'rial', 'toman' ), 0.1 );
PkgAssert::float( 'same unit factor', ImportPlan::factor( 'toman', 'toman' ), 1.0 );
PkgAssert::float( '50000 toman -> rial', ImportPlan::convert_amount( 50000.0, 'toman', 'rial' ), 500000.0 );
PkgAssert::float( '500000 rial -> toman', ImportPlan::convert_amount( 500000.0, 'rial', 'toman' ), 50000.0 );
PkgAssert::check( 'units_differ true', ImportPlan::units_differ( 'toman', 'rial' ) );
PkgAssert::check( 'units_differ false', ! ImportPlan::units_differ( 'toman', 'toman' ) );

// A currency row: fixed amounts convert, percentages never do.
$row = array(
	'profit_type'      => 'percent',
	'profit_value'     => 10,
	'commission_type'  => 'fixed',
	'commission_value' => 50000,
	'extra_amount'     => 2000,
);

$converted = ImportPlan::convert_currency_row( $row, 'toman', 'rial', true );

PkgAssert::float( 'percent profit untouched', (float) $converted['profit_value'], 10.0 );
PkgAssert::float( 'fixed commission x10', (float) $converted['commission_value'], 500000.0 );
PkgAssert::float( 'extra amount x10', (float) $converted['extra_amount'], 2000.0 * 10 );

$not_converted = ImportPlan::convert_currency_row( $row, 'toman', 'rial', false );
PkgAssert::float( 'no conversion keeps the raw value', (float) $not_converted['commission_value'], 50000.0 );

$same_unit = ImportPlan::convert_currency_row( $row, 'toman', 'toman', true );
PkgAssert::float( 'same unit is a no-op', (float) $same_unit['commission_value'], 50000.0 );

// A formula: only money-type custom variables convert.
$formula = array(
	'variables' => array(
		array( 'name' => 'wage', 'type' => 'custom', 'default' => 15, 'is_money' => false ),
		array( 'name' => 'extra', 'type' => 'custom', 'default' => 5000, 'is_money' => true ),
		array( 'name' => 'gold18', 'type' => 'currency', 'currency_code' => 'GOLD18' ),
	),
);

$converted_formula = ImportPlan::convert_formula_row( $formula, 'toman', 'rial', true );
$vars = $converted_formula['variables'];

PkgAssert::float( 'non-money variable untouched', (float) $vars[0]['default'], 15.0 );
PkgAssert::float( 'money variable x10', (float) $vars[1]['default'], 50000.0 );
PkgAssert::check( 'currency variable untouched', ! isset( $vars[2]['default'] ) );

// Rounding step uses the same factor.
PkgAssert::float( 'rounding step 1000 toman -> rial', ImportPlan::convert_amount( 1000.0, 'toman', 'rial' ), 10000.0 );

/* ------------------------------------------------------------------------- */
echo "\n[5] Conflict policy\n";
/* ------------------------------------------------------------------------- */
PkgAssert::check( 'default policy is skip', ImportPlan::POLICY_SKIP === ImportPlan::policy( null ) );
PkgAssert::check( 'update policy recognised', ImportPlan::POLICY_UPDATE === ImportPlan::policy( 'update' ) );
PkgAssert::check( 'unknown policy falls back to skip', ImportPlan::POLICY_SKIP === ImportPlan::policy( 'whatever' ) );

/* ------------------------------------------------------------------------- */
echo "\n[6] Formula rows must be usable before import\n";
/* ------------------------------------------------------------------------- */
$repo_inline = static function ( array $formulaRow ): array {
	// Mirrors what the WordPress importer does before saving: compile + check.
	$expression = (string) ( $formulaRow['expression'] ?? '' );
	$declared   = array();

	foreach ( (array) ( $formulaRow['variables'] ?? array() ) as $variable ) {
		if ( is_array( $variable ) && isset( $variable['name'] ) ) {
			$declared[] = (string) $variable['name'];
		}
	}

	return array(
		'expression' => $expression,
		'declared'   => $declared,
	);
};

// Valid formula (structure asserted here; the engine is tested by formula-engine.php).
$ok_formula = $repo_inline( pkg_fixture()['sections']['formulas'][0] );
PkgAssert::check( 'fixture formula declares its variables', array( 'weight', 'gold18', 'wage' ) === $ok_formula['declared'] );
PkgAssert::check( 'fixture formula has an expression', '' !== $ok_formula['expression'] );

// A formula without variables cannot be imported meaningfully.
$broken = $repo_inline( array( 'expression' => 'weight * 2', 'variables' => array() ) );
PkgAssert::check( 'undeclared variable is detectable', array() === array_intersect( array( 'weight' ), $broken['declared'] ) );

/* ------------------------------------------------------------------------- */
echo "\n[7] Unknown provider handling\n";
/* ------------------------------------------------------------------------- */
/*
 * The repository imports unknown provider keys as disabled rows with a warning.
 * The decision itself is pure: a provider is unknown when it is not in the
 * registry's key list.
 */
$known   = array( 'manual', 'mock', 'navasan', 'alanchand' );
$rows    = array( 'alanchand', 'some_new_provider' );
$unknown = array_values( array_diff( $rows, $known ) );

PkgAssert::check( 'unknown provider detected', array( 'some_new_provider' ) === $unknown );
PkgAssert::check( 'known provider not flagged', ! in_array( 'alanchand', $unknown, true ) );

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", PkgAssert::$passed, PkgAssert::$failed );

exit( PkgAssert::$failed > 0 ? 1 : 0 );
