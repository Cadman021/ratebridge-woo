<?php
/**
 * Dependency-free tests for CSV parsing/serialising and bulk-edit arithmetic.
 *
 * Run from the plugin root:
 *     php tests/csv.php
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/ImportExport/Compat.php';
require_once __DIR__ . '/../includes/ImportExport/Csv.php';
require_once __DIR__ . '/../includes/ImportExport/BulkChange.php';

use RateBridge\ImportExport\BulkChange;
use RateBridge\ImportExport\Csv;

/**
 * Tiny PASS/FAIL collector.
 */
final class CsvAssert {

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
	 * Assert two strings are identical.
	 *
	 * @param string $label    Description.
	 * @param string $actual   Actual.
	 * @param string $expected Expected.
	 * @return bool
	 */
	public static function same( string $label, string $actual, string $expected ): bool {
		return self::check( $label, $actual === $expected, 'got "' . $actual . '"' );
	}

	/**
	 * Assert two arrays are identical.
	 *
	 * @param string $label    Description.
	 * @param array  $actual   Actual.
	 * @param array  $expected Expected.
	 * @return bool
	 */
	public static function same_array( string $label, array $actual, array $expected ): bool {
		return self::check(
			$label,
			$actual === $expected,
			'got ' . wp_json_encode_compat( $actual )
		);
	}

	/**
	 * Assert a float with tolerance.
	 *
	 * @param string     $label    Description.
	 * @param float|null $actual   Actual.
	 * @param float|null $expected Expected.
	 * @return bool
	 */
	public static function float( string $label, ?float $actual, ?float $expected ): bool {
		if ( null === $expected ) {
			return self::check( $label, null === $actual, 'got ' . var_export( $actual, true ) );
		}

		return self::check(
			sprintf( '%s => %s', $label, (string) $expected ),
			null !== $actual && abs( $actual - $expected ) < 0.0001,
			'got ' . var_export( $actual, true )
		);
	}
}

/**
 * Minimal JSON encoder for failure messages.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_compat( mixed $value ): string {
	return (string) json_encode( $value, JSON_UNESCAPED_UNICODE );
}

echo "RateBridge Woo — CSV + bulk edit\n";
echo str_repeat( '=', 60 ), "\n\n";

/* ------------------------------------------------------------------------- */
echo "[1] CSV round trips\n";
/* ------------------------------------------------------------------------- */
$rows = array(
	array( 'id', 'name', 'base_price' ),
	array( '12', 'Gold ring', '8' ),
	array( '13', 'Ring, with comma', '4.75' ),
);

$serialised = '';

foreach ( $rows as $row ) {
	$serialised .= Csv::serialize_row( $row );
}

$parsed = Csv::parse( $serialised );

CsvAssert::check( 'parse succeeds', '' === $parsed['error'], $parsed['error'] );
CsvAssert::same_array( 'round trip keeps every row', $parsed['rows'], $rows );

// Quotes inside a cell.
$quoted = Csv::serialize_row( array( 'a"b', 'c' ) );
CsvAssert::same( 'quote is doubled', $quoted, "\"a\"\"b\",c\r\n" );
CsvAssert::same_array( 'quoted round trip', Csv::parse( $quoted )['rows'], array( array( 'a"b', 'c' ) ) );

// Newlines inside a cell must survive.
$multiline = Csv::serialize_row( array( "line1\nline2", 'x' ) );
$parsed_multi = Csv::parse( $multiline );
CsvAssert::same_array(
	'newline inside a cell survives',
	$parsed_multi['rows'],
	array( array( "line1\nline2", 'x' ) )
);

// Line endings: LF and CRLF give the same rows.
$lf = "a,b\n1,2\n";
$crlf = "a,b\r\n1,2\r\n";
CsvAssert::same_array( 'LF parses', Csv::parse( $lf )['rows'], array( array( 'a', 'b' ), array( '1', '2' ) ) );
CsvAssert::same_array( 'CRLF parses', Csv::parse( $crlf )['rows'], array( array( 'a', 'b' ), array( '1', '2' ) ) );

// BOM is stripped.
CsvAssert::same_array(
	'BOM stripped',
	Csv::parse( Csv::bom() . "id,mode\r\n5,currency\r\n" )['rows'],
	array( array( 'id', 'mode' ), array( '5', 'currency' ) )
);

/* ------------------------------------------------------------------------- */
echo "\n[2] Delimiters\n";
/* ------------------------------------------------------------------------- */
CsvAssert::same_array(
	'semicolon',
	Csv::parse( "a;b\n1;2\n", ';' )['rows'],
	array( array( 'a', 'b' ), array( '1', '2' ) )
);
CsvAssert::same_array(
	'tab',
	Csv::parse( "a\tb\n1\t2\n", "\t" )['rows'],
	array( array( 'a', 'b' ), array( '1', '2' ) )
);
CsvAssert::same( 'detect comma', Csv::detect_delimiter( "a,b,c\n1,2,3" ), ',' );
CsvAssert::same( 'detect semicolon', Csv::detect_delimiter( "a;b;c\n1;2;3" ), ';' );
CsvAssert::same( 'detect tab', Csv::detect_delimiter( "a\tb\tc\n1\t2\t3" ), "\t" );
CsvAssert::same( 'single column falls back to comma', Csv::detect_delimiter( "only\nrows" ), ',' );

/* ------------------------------------------------------------------------- */
echo "\n[3] Persian digits and decimal separator\n";
/* ------------------------------------------------------------------------- */
CsvAssert::same( 'Persian digits', Csv::normalize_number( '۱۲۳۴' ), '1234' );
CsvAssert::same( 'Arabic-Indic digits', Csv::normalize_number( '١٢٣' ), '123' );
CsvAssert::same( 'Persian decimal separator', Csv::normalize_number( '۱۲٫۵' ), '12.5' );
CsvAssert::same( 'NBSP removed', Csv::normalize_number( "1\xC2\xA0000" ), '1000' );
CsvAssert::same( 'mixed', Csv::normalize_number( ' ۲٫۲۵ ' ), '2.25' );

/* ------------------------------------------------------------------------- */
echo "\n[4] Empty cells and huge rows\n";
/* ------------------------------------------------------------------------- */
CsvAssert::same_array(
	'empty cells preserved',
	Csv::parse( "a,,c\n,,,\n" )['rows'],
	array( array( 'a', '', 'c' ), array( '', '', '', '' ) )
);

// A row longer than MAX_ROW_BYTES stops the reader instead of buffering forever.
$huge = str_repeat( 'x', Csv::MAX_ROW_BYTES + 10 ) . "\n";
$parsed_huge = Csv::parse( $huge );
CsvAssert::check( 'oversized row is not returned as data', 0 === count( $parsed_huge['rows'] ) || strlen( $parsed_huge['rows'][0][0] ?? '' ) === 0 );

/* ------------------------------------------------------------------------- */
echo "\n[5] CSV injection guard\n";
/* ------------------------------------------------------------------------- */
$dangerous = array( '=1+1', '+SUM(A1)', '@cmd', "\tTAB", "\rCR", '=HYPERLINK("http://x")' );

foreach ( $dangerous as $payload ) {
	$escaped = Csv::escape_cell( $payload );

	CsvAssert::check(
		sprintf( '%s is prefixed', wp_json_encode_compat( $payload ) ),
		str_starts_with( $escaped, "'" ),
		$escaped
	);

	CsvAssert::same(
		sprintf( '%s round trips', wp_json_encode_compat( $payload ) ),
		Csv::unescape_cell( $escaped ),
		$payload
	);
}

// Negative numbers keep their sign; other minus-prefixed text is escaped.
CsvAssert::same( 'negative number untouched', Csv::escape_cell( '-12.5' ), '-12.5' );
CsvAssert::same( 'negative integer untouched', Csv::escape_cell( '-8' ), '-8' );
CsvAssert::same( 'minus text is escaped', Csv::escape_cell( '-cmd' ), "'-cmd" );
CsvAssert::same( 'escaped minus text round trips', Csv::unescape_cell( "'-cmd" ), '-cmd' );
CsvAssert::same( 'negative number not unescaped wrongly', Csv::unescape_cell( '-12.5' ), '-12.5' );

// A legitimate apostrophe is preserved.
CsvAssert::same( "O'Brien untouched", Csv::escape_cell( "O'Brien" ), "O'Brien" );
CsvAssert::same( "O'Brien round trips", Csv::unescape_cell( "O'Brien" ), "O'Brien" );
CsvAssert::same( 'single apostrophe only', Csv::unescape_cell( "'" ), "'" );

// Escaped cells survive a full serialise/parse cycle.
$inject_row = array( '=1+1', '-cmd', '-12.5', "O'Brien" );
$inject_parsed = Csv::parse( Csv::serialize_row( $inject_row ) )['rows'][0];
$restored = array_map( array( Csv::class, 'unescape_cell' ), $inject_parsed );
CsvAssert::same_array( 'injection cells round trip', $restored, $inject_row );

/* ------------------------------------------------------------------------- */
echo "\n[6] Header normalisation and UTF-8\n";
/* ------------------------------------------------------------------------- */
CsvAssert::same( 'header lowercased', Csv::normalize_header( 'BasePrice' ) === 'baseprice' ? 'baseprice' : 'x', 'baseprice' );
CsvAssert::same( 'header underscores', Csv::normalize_header( 'base price' ), 'base_price' );
CsvAssert::same( 'header dashes', Csv::normalize_header( 'base-price' ), 'base_price' );
CsvAssert::same( 'header BOM + spaces', Csv::normalize_header( "\xEF\xBB\xBF  ID " ), 'id' );
CsvAssert::same( 'header ZWNJ removed', Csv::normalize_header( "base\xE2\x80\x8Cprice" ), 'baseprice' );

CsvAssert::check( 'valid UTF-8 accepted', Csv::is_valid_utf8( 'سلام دنیا' ) );
CsvAssert::check( 'ASCII accepted', Csv::is_valid_utf8( 'plain' ) );
CsvAssert::check( 'invalid UTF-8 rejected', ! Csv::is_valid_utf8( "\xC3\x28" ) );

$bad = Csv::parse( "a,b\n\xC3\x28,x\n" );
CsvAssert::same( 'invalid UTF-8 payload rejected', $bad['error'], 'invalid_utf8' );

/* ------------------------------------------------------------------------- */
echo "\n[7] Bulk change: none / set / clear\n";
/* ------------------------------------------------------------------------- */
$r = BulkChange::apply( 100.0, BulkChange::OP_NONE );
CsvAssert::check( 'none does not change', false === $r['changed'] && 100.0 === $r['value'] );
CsvAssert::float( 'set absolute', BulkChange::apply( 100.0, BulkChange::OP_SET, 250.0 )['value'], 250.0 );
CsvAssert::check( 'clear returns null', null === BulkChange::apply( 100.0, BulkChange::OP_CLEAR )['value'] );
CsvAssert::check( 'clear marks changed', true === BulkChange::apply( 100.0, BulkChange::OP_CLEAR )['changed'] );

/* ------------------------------------------------------------------------- */
echo "\n[8] Bulk change: percentages and amounts\n";
/* ------------------------------------------------------------------------- */
CsvAssert::float( '+5%', BulkChange::apply( 100.0, BulkChange::OP_INCREASE_PERCENT, 5.0 )['value'], 105.0 );
CsvAssert::float( '-5%', BulkChange::apply( 100.0, BulkChange::OP_DECREASE_PERCENT, 5.0 )['value'], 95.0 );
CsvAssert::float( '+1000', BulkChange::apply( 100.0, BulkChange::OP_INCREASE_AMOUNT, 1000.0 )['value'], 1100.0 );
CsvAssert::float( '-25', BulkChange::apply( 100.0, BulkChange::OP_DECREASE_AMOUNT, 25.0 )['value'], 75.0 );

// Realistic price: 256,450,000 + 5% = 269,272,500
CsvAssert::float(
	'price +5%',
	BulkChange::apply( 256450000.0, BulkChange::OP_INCREASE_PERCENT, 5.0 )['value'],
	269272500.0
);

// Missing current value: percentage of nothing stays 0, amount works.
CsvAssert::float( '+5% of unset = 0', BulkChange::apply( null, BulkChange::OP_INCREASE_PERCENT, 5.0 )['value'], 0.0 );
CsvAssert::float( '+100 of unset', BulkChange::apply( null, BulkChange::OP_INCREASE_AMOUNT, 100.0 )['value'], 100.0 );

// Never negative.
CsvAssert::float( '-50% of 100 = 50', BulkChange::apply( 100.0, BulkChange::OP_DECREASE_PERCENT, 50.0 )['value'], 50.0 );
CsvAssert::float( '-90% of 100 = 10', BulkChange::apply( 100.0, BulkChange::OP_DECREASE_PERCENT, 90.0 )['value'], 10.0 );
CsvAssert::float( '-150% clamps to 0', BulkChange::apply( 100.0, BulkChange::OP_DECREASE_PERCENT, 150.0 )['value'], 0.0 );
CsvAssert::float( 'subtract more than exists clamps to 0', BulkChange::apply( 10.0, BulkChange::OP_DECREASE_AMOUNT, 500.0 )['value'], 0.0 );
CsvAssert::float( 'set negative clamps to 0', BulkChange::apply( 10.0, BulkChange::OP_SET, -50.0 )['value'], 0.0 );

// Unknown operation behaves like "none".
CsvAssert::check( 'unknown op is none', false === BulkChange::apply( 10.0, 'nope', 5.0 )['changed'] );
CsvAssert::same( 'operation() normalises', BulkChange::operation( 'SET' ), 'set' );

/* ------------------------------------------------------------------------- */
echo "\n[9] Bulk change: mode validation\n";
/* ------------------------------------------------------------------------- */
CsvAssert::same( 'currency without currency', BulkChange::validate_mode( 'currency', false, false ), 'currency_required' );
CsvAssert::same( 'formula without formula', BulkChange::validate_mode( 'formula', false, false ), 'formula_required' );
CsvAssert::same( 'currency with currency', BulkChange::validate_mode( 'currency', true, false ), '' );
CsvAssert::same( 'formula with formula', BulkChange::validate_mode( 'formula', false, true ), '' );
CsvAssert::same( 'off needs nothing', BulkChange::validate_mode( 'off', false, false ), '' );

/* ------------------------------------------------------------------------- */
echo "\n[10] Bulk change: combined scenario + product CSV validation\n";
/* ------------------------------------------------------------------------- */
// "base price +5%" on a currency product.
$base = BulkChange::apply( 510.0, BulkChange::OP_INCREASE_PERCENT, 5.0 );
CsvAssert::float( 'base price 510 +5% = 535.5', $base['value'], 535.5 );
CsvAssert::check( 'min price cleared', null === BulkChange::apply( 1000000.0, BulkChange::OP_CLEAR )['value'] );

require_once __DIR__ . '/../includes/ImportExport/Package.php';
require_once __DIR__ . '/../includes/ImportExport/ImportPlan.php';
require_once __DIR__ . '/../includes/ImportExport/ProductCsv.php';

use RateBridge\ImportExport\ProductCsv;

$columns = ProductCsv::columns();
CsvAssert::check( 'csv has 19 columns', 19 === count( $columns ), (string) count( $columns ) );
CsvAssert::check( 'id and sku present', in_array( 'id', $columns, true ) && in_array( 'sku', $columns, true ) );
CsvAssert::check( 'mode and formula_slug present', in_array( 'mode', $columns, true ) && in_array( 'formula_slug', $columns, true ) );

CsvAssert::same( 'header maps without error', ProductCsv::map_header( $columns )['error'], '' );
CsvAssert::same( 'missing mode rejected', ProductCsv::map_header( array( 'id', 'sku' ) )['error'], 'missing_columns' );
CsvAssert::same( 'missing identifier rejected', ProductCsv::map_header( array( 'mode' ) )['error'], 'missing_identifier' );
CsvAssert::same( 'header is case insensitive', ProductCsv::map_header( array( 'ID', 'MODE', 'Base Price' ) )['error'], '' );

$ctx = array(
	'currencies' => array( 'USD' ),
	'formulas'   => array( 'gold' => array( 'enabled' => true, 'custom' => array( 'wage' ) ) ),
	'store_unit' => 'toman',
	'file_unit'  => 'toman',
	'convert'    => false,
);

$row = array(
	'id'           => '42',
	'sku'          => 'SKU-1',
	'mode'         => 'currency',
	'currency'     => 'usd',
	'base_price'   => '1,234.5',
	'profit_type'  => 'percent',
	'profit_value' => '10',
);

$checked = ProductCsv::validate_row( $row, $ctx );
CsvAssert::check( 'valid row accepted', true === $checked['ok'], $checked['error'] );
CsvAssert::check( 'currency upper-cased', 'USD' === ( $checked['data']['currency'] ?? '' ) );
CsvAssert::float( 'thousands separator removed', (float) ( $checked['data']['base_price'] ?? 0 ), 1234.5 );

foreach (
	array(
		'bad mode'         => array( array( 'mode' => 'nope' ), 'bad_mode' ),
		'no currency'      => array( array( 'mode' => 'currency' ), 'currency_required' ),
		'unknown currency' => array( array( 'mode' => 'currency', 'currency' => 'XXX' ), 'unknown_currency' ),
		'no formula'       => array( array( 'mode' => 'formula' ), 'formula_required' ),
		'unknown formula'  => array( array( 'mode' => 'formula', 'formula_slug' => 'zzz' ), 'unknown_formula' ),
		'not a number'     => array( array( 'mode' => 'currency', 'currency' => 'USD', 'base_price' => 'abc' ), 'bad_number:base_price' ),
		'negative'         => array( array( 'mode' => 'currency', 'currency' => 'USD', 'min_price' => '-5' ), 'negative_number:min_price' ),
	) as $label => $case
) {
	$result = ProductCsv::validate_row( $case[0], $ctx );

	CsvAssert::check(
		sprintf( '%s rejected', $label ),
		false === $result['ok'] && $result['error'] === $case[1],
		$result['error']
	);
}

$vars = array(
	'mode'         => 'formula',
	'formula_slug' => 'gold',
	'formula_vars' => '{"nope": 5}',
);

CsvAssert::same( 'undeclared formula variable rejected', ProductCsv::validate_row( $vars, $ctx )['error'], 'unknown_formula_var:nope' );

$vars['formula_vars'] = '{"wage": 12}';
CsvAssert::check( 'declared formula variable accepted', true === ProductCsv::validate_row( $vars, $ctx )['ok'] );

$vars['formula_vars'] = 'not json';
CsvAssert::same( 'bad JSON rejected', ProductCsv::validate_row( $vars, $ctx )['error'], 'bad_formula_vars' );

// Unit conversion inside a row (file in rial, site in toman).
$rial_row = array( 'mode' => 'currency', 'currency' => 'USD', 'min_price' => '500000', 'profit_value' => '10' );
$conv     = ProductCsv::validate_row(
	$rial_row,
	array( 'currencies' => array( 'USD' ), 'formulas' => array(), 'store_unit' => 'toman', 'file_unit' => 'rial', 'convert' => true )
);

CsvAssert::float( 'min_price rial -> toman', (float) ( $conv['data']['min_price'] ?? 0 ), 50000.0 );
CsvAssert::float( 'percent profit untouched', (float) ( $conv['data']['profit_value'] ?? 0 ), 10.0 );

/* ------------------------------------------------------------------------- */
echo "\n[11] JSONL session file (row writer/reader)\n";
/* ------------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/ImportExport/RowFile.php';
require_once __DIR__ . '/../includes/Core/CacheGeneration.php';

use RateBridge\Core\CacheGeneration;
use RateBridge\ImportExport\RowFile;

$tmp = sys_get_temp_dir() . '/rbw-rowfile-' . bin2hex( random_bytes( 6 ) ) . '.jsonl';
$file = new RowFile( $tmp );

$sample = array(
	array( '1', 'plain' ),
	array( '2', 'سلام، دنیا' ),
	array( '3', 'has "quotes" and, commas' ),
	array( '4', "newline\ninside" ),
	array( '5', str_repeat( 'x', 4000 ) ),
	array( '6', '' ),
);

$write = $file->write( $sample );
CsvAssert::check( 'write succeeds', true === $write['ok'], $write['error'] );
CsvAssert::check( 'write reports every row', 6 === $write['rows'], (string) $write['rows'] );
CsvAssert::check( 'file has bytes', $write['bytes'] > 0 );
CsvAssert::check( 'no BOM in the file', ! str_starts_with( (string) file_get_contents( $tmp ), "\xEF\xBB\xBF" ) );

// Reading the whole file at once.
$all = $file->read( 0, 100 );
CsvAssert::check( 'read returns all rows', 6 === count( $all['rows'] ), (string) count( $all['rows'] ) );
CsvAssert::check( 'eof on a full read', true === $all['eof'] );
CsvAssert::check( 'Persian text survives', 'سلام، دنیا' === ( $all['rows'][1][1] ?? '' ), (string) ( $all['rows'][1][1] ?? '' ) );
CsvAssert::check( 'quotes and commas survive', 'has "quotes" and, commas' === ( $all['rows'][2][1] ?? '' ) );
CsvAssert::check( 'newline inside a cell survives', "newline\ninside" === ( $all['rows'][3][1] ?? '' ) );
CsvAssert::check( 'large cell survives', 4000 === strlen( $all['rows'][4][1] ?? '' ) );
CsvAssert::check( 'empty cell survives', '' === ( $all['rows'][5][1] ?? 'x' ) );

// Offset reading in chunks (the import path).
$first = $file->read( 0, 2 );
CsvAssert::check( 'first chunk has 2 rows', 2 === count( $first['rows'] ) );
CsvAssert::check( 'first chunk is not EOF', false === $first['eof'] );

$second = $file->read( (int) $first['next'], 2 );
CsvAssert::check( 'second chunk continues', 2 === count( $second['rows'] ) );
CsvAssert::check( 'second chunk starts at row 3', '3' === ( $second['rows'][0][0] ?? '' ), (string) ( $second['rows'][0][0] ?? '' ) );

$third = $file->read( (int) $second['next'], 2 );
CsvAssert::check( 'third chunk finishes', 2 === count( $third['rows'] ) && true === $third['eof'] );

// Offsets are monotonically increasing (no re-reads).
CsvAssert::check( 'offsets increase', (int) $first['next'] < (int) $second['next'] && (int) $second['next'] < (int) $third['next'] );

// A trailing partial line (simulating a crash) is not returned as data.
file_put_contents( $tmp, "[\"a\"]\n[\"b\"]\n[\"trunc", FILE_APPEND );
$after_truncated = $file->read( 0, 100 );
CsvAssert::check( 'truncated trailing line is skipped', 8 === count( $after_truncated['rows'] ), (string) count( $after_truncated['rows'] ) );

// count_rows() counts physical lines: 8 complete rows + the truncated one.
CsvAssert::check( 'count_rows counts lines', 9 === $file->count_rows(), (string) $file->count_rows() );

// Too many rows is refused before anything is written.
$idle = sys_get_temp_dir() . '/rbw-rowfile-big-' . bin2hex( random_bytes( 4 ) ) . '.jsonl';
$big  = new RowFile( $idle );
$many = array_fill( 0, RowFile::MAX_ROWS + 1, array( 'x' ) );
$ref  = $big->write( $many );
CsvAssert::check( 'over 20,000 rows is refused', false === $ref['ok'] && 'too_many_rows' === $ref['error'] );
CsvAssert::check( 'nothing was written for the refused file', ! file_exists( $idle ) );

// delete()
CsvAssert::check( 'delete() removes the file', true === $file->delete() && ! file_exists( $tmp ) );
CsvAssert::check( 'delete() on a missing file is a no-op', true === $file->delete() );

// Reading a missing file returns an empty result, never an error.
$missing = ( new RowFile( $tmp . '.nope' ) )->read( 0, 10 );
CsvAssert::check( 'missing file reads as empty', array() === $missing['rows'] && true === $missing['eof'] );

/* ------------------------------------------------------------------------- */
echo "\n[12] Cache generation keys\n";
/* ------------------------------------------------------------------------- */
$k1 = CacheGeneration::key( 'rbw_html_', 'abc', 0 );
$k2 = CacheGeneration::key( 'rbw_html_', 'abc', 0 );
$k3 = CacheGeneration::key( 'rbw_html_', 'abc', 1 );

CsvAssert::check( 'same input + generation => same key', $k1 === $k2 );
CsvAssert::check( 'bumping the generation changes the key', $k1 !== $k3 );
CsvAssert::check( 'key keeps the prefix', str_starts_with( $k1, 'rbw_html_' ) );
CsvAssert::check( 'key carries the generation', str_ends_with( $k1, '_g0' ) && str_ends_with( $k3, '_g1' ) );
CsvAssert::check( 'different input => different key', CacheGeneration::key( 'rbw_html_', 'abd', 0 ) !== $k1 );
CsvAssert::check( 'option name for frontend', 'rbw_cache_gen' === CacheGeneration::option_name( 'frontend' ) );
CsvAssert::check( 'option name for a provider', 'rbw_cache_gen_provider_navasan' === CacheGeneration::option_name( 'navasan' ) );
CsvAssert::check(
	'unsafe provider scope is sanitised',
	'rbw_cache_gen_provider_abscript' === CacheGeneration::option_name( 'a!b<script>' ),
	CacheGeneration::option_name( 'a!b<script>' )
);
CsvAssert::check( 'empty scope has no option', '' === CacheGeneration::option_name( '!!' ) );

echo "\n", str_repeat( '=', 60 ), "\n";
printf( "RESULT: %d passed, %d failed\n", CsvAssert::$passed, CsvAssert::$failed );

exit( CsvAssert::$failed > 0 ? 1 : 0 );
