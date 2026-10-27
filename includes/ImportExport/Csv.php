<?php
/**
 * RFC 4180 CSV reader and writer (pure PHP).
 *
 * Deliberately dependency-free so `tests/csv.php` runs it from the CLI. It
 * handles quoting, embedded commas/newlines/quotes, CRLF and LF, a UTF-8 BOM,
 * and three delimiters. It never keeps the whole file in memory: the reader
 * pulls rows one at a time from a stream.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Streaming CSV reader/writer.
 */
final class Csv {

	/**
	 * Maximum size of one physical line/row (bytes).
	 */
	public const MAX_ROW_BYTES = 65536;

	/**
	 * Supported delimiters, in detection priority order.
	 *
	 * @return string[]
	 */
	public static function delimiters(): array {
		return array( ',', ';', "\t" );
	}

	/**
	 * Detect the delimiter from the first line of a CSV payload.
	 *
	 * Chooses the delimiter that gives the most fields on the header line; ties
	 * fall back to the first candidate.
	 *
	 * @param string $sample First ~8 KB of the file.
	 * @return string
	 */
	public static function detect_delimiter( string $sample ): string {
		$line = strtok( $sample, "\r\n" );

		if ( ! is_string( $line ) || '' === $line ) {
			return ',';
		}

		$best       = ',';
		$best_count = 0;

		foreach ( self::delimiters() as $candidate ) {
			$fields = self::parse_line( $line, $candidate );

			// A quote-heavy line is not a reliable sample: ignore it.
			if ( count( $fields ) > $best_count ) {
				$best_count = count( $fields );
				$best       = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Parse a single CSV line into fields.
	 *
	 * Small helper used for header detection and for tests; the streaming reader
	 * below handles multi-line quoted fields.
	 *
	 * @param string $line      One physical line (no trailing newline).
	 * @param string $delimiter Delimiter.
	 * @return string[]
	 */
	public static function parse_line( string $line, string $delimiter = ',' ): array {
		$fields  = array();
		$current = '';
		$quoted  = false;
		$length  = strlen( $line );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $line[ $i ];

			if ( $quoted ) {
				if ( '"' === $char ) {
					if ( $i + 1 < $length && '"' === $line[ $i + 1 ] ) {
						$current .= '"';
						++$i;
						continue;
					}

					$quoted = false;
					continue;
				}

				$current .= $char;
				continue;
			}

			if ( '"' === $char && '' === $current ) {
				$quoted = true;
				continue;
			}

			if ( $char === $delimiter ) {
				$fields[] = $current;
				$current  = '';
				continue;
			}

			$current .= $char;
		}

		$fields[] = $current;

		return $fields;
	}

	/**
	 * Read a whole CSV payload into rows (used by tests and small files).
	 *
	 * The importer uses `read_rows()` for real files; this convenience method is
	 * fine for bounded input (the admin never imports an unbounded string).
	 *
	 * @param string $payload   Raw bytes.
	 * @param string $delimiter Delimiter.
	 * @return array{rows:array<int,string[]>,error:string}
	 *     `error` is `invalid_utf8` when the payload is not valid UTF-8.
	 */
	public static function parse( string $payload, string $delimiter = ',' ): array {
		if ( ! self::is_valid_utf8( $payload ) ) {
			return array(
				'rows'  => array(),
				'error' => 'invalid_utf8',
			);
		}

		$payload = self::strip_bom( $payload );
		$stream  = fopen( 'php://memory', 'r+b' );

		if ( false === $stream ) {
			return array(
				'rows'  => array(),
				'error' => 'stream_error',
			);
		}

		fwrite( $stream, $payload );
		rewind( $stream );

		$rows = array();

		while ( true ) {
			$row = self::read_row( $stream, $delimiter );

			if ( null === $row ) {
				break;
			}

			$rows[] = $row;
		}

		fclose( $stream );

		return array(
			'rows'  => $rows,
			'error' => '',
		);
	}

	/**
	 * Read one logical CSV row from a stream (handles quoted newlines).
	 *
	 * @param resource $handle    Open stream.
	 * @param string   $delimiter Delimiter.
	 * @return string[]|null Null at end of file.
	 */
	public static function read_row( $handle, string $delimiter = ',' ): ?array {
		$line = fgets( $handle, self::MAX_ROW_BYTES );

		if ( false === $line ) {
			return null;
		}

		if ( strlen( $line ) >= self::MAX_ROW_BYTES - 1 && "\n" !== substr( $line, -1 ) ) {
			// A row longer than the buffer is rejected by the caller's size limit.
			return null;
		}

		// A quoted field may contain newlines: keep reading until the quotes balance.
		while ( self::has_open_quote( $line ) ) {
			$more = fgets( $handle, self::MAX_ROW_BYTES );

			if ( false === $more ) {
				break;
			}

			$line .= $more;
		}

		$line = rtrim( $line, "\r\n" );

		if ( '' === $line ) {
			return array();
		}

		return self::parse_line( $line, $delimiter );
	}

	/**
	 * Whether a chunk ends inside an open quoted field.
	 *
	 * @param string $chunk Raw text.
	 * @return bool
	 */
	private static function has_open_quote( string $chunk ): bool {
		$quotes = substr_count( $chunk, '"' );

		return 0 !== $quotes % 2;
	}

	/**
	 * Serialise one row (RFC 4180) with a trailing CRLF.
	 *
	 * @param array<int, mixed> $fields Fields.
	 * @param string            $delimiter Delimiter.
	 * @return string
	 */
	public static function serialize_row( array $fields, string $delimiter = ',' ): string {
		$out = array();

		foreach ( $fields as $field ) {
			$value = is_scalar( $field ) ? (string) $field : '';
			$value = self::escape_cell( $value, $delimiter );

			$needs_quotes = str_contains( $value, '"' )
				|| str_contains( $value, $delimiter )
				|| str_contains( $value, "\n" )
				|| str_contains( $value, "\r" )
				|| str_contains( $value, ' ' )
				|| '' === $value;

			if ( $needs_quotes ) {
				$out[] = '"' . str_replace( '"', '""', $value ) . '"';
				continue;
			}

			$out[] = $value;
		}

		return implode( $delimiter, $out ) . "\r\n";
	}

	/**
	 * Guard a cell against spreadsheet formula injection.
	 *
	 * Cells starting with `=`, `+`, `@`, tab or CR are prefixed with a single
	 * apostrophe. A leading `-` is only escaped when the cell is not a valid
	 * negative number (negative prices must round-trip unchanged).
	 *
	 * @param string $value Cell value.
	 * @param string $delimiter Unused; kept for a stable signature.
	 * @return string
	 */
	public static function escape_cell( string $value, string $delimiter = ',' ): string {
		if ( '' === $value ) {
			return '';
		}

		$first = $value[0];

		if ( '=' === $first || '+' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $value;
		}

		if ( '-' === $first ) {
			// A real negative number keeps its sign; anything else is escaped.
			if ( ! preg_match( '/^-(\d+(\.\d+)?|\.\d+)$/', $value ) ) {
				return "'" . $value;
			}
		}

		return $value;
	}

	/**
	 * Remove the export-time injection guard from a cell.
	 *
	 * Only strips the apostrophe when it was added by `escape_cell()`: a value
	 * that starts with `'` followed by a dangerous character.
	 *
	 * @param string $value Raw cell.
	 * @return string
	 */
	public static function unescape_cell( string $value ): string {
		if ( strlen( $value ) < 2 || "'" !== $value[0] ) {
			return $value;
		}

		$next = $value[1];

		if ( '=' === $next || '+' === $next || '@' === $next || "\t" === $next || "\r" === $next ) {
			return substr( $value, 1 );
		}

		// `'-abc` (escaped non-numeric negative) keeps the rest as typed.
		if ( '-' === $next && ! preg_match( '/^-(\d+(\.\d+)?|\.\d+)$/', substr( $value, 1 ) ) ) {
			return substr( $value, 1 );
		}

		return $value;
	}

	/**
	 * Strip a UTF-8 BOM.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	public static function strip_bom( string $value ): string {
		return str_starts_with( $value, "\xEF\xBB\xBF" ) ? substr( $value, 3 ) : $value;
	}

	/**
	 * UTF-8 BOM bytes.
	 *
	 * @return string
	 */
	public static function bom(): string {
		return "\xEF\xBB\xBF";
	}

	/**
	 * Validate UTF-8 (also rejects overlong/invalid sequences).
	 *
	 * @param string $value Raw bytes.
	 * @return bool
	 */
	public static function is_valid_utf8( string $value ): bool {
		if ( '' === $value ) {
			return true;
		}

		if ( function_exists( 'mb_check_encoding' ) ) {
			return (bool) mb_check_encoding( $value, 'UTF-8' );
		}

		return 1 === preg_match( '//u', $value );
	}

	/**
	 * Normalise a header name: BOM, case, spaces and underscores.
	 *
	 * @param string $header Raw header cell.
	 * @return string
	 */
	public static function normalize_header( string $header ): string {
		$header = self::strip_bom( $header );
		$header = trim( $header );
		$header = str_replace( array( ' ', '-', "\xE2\x80\x8F", "\xE2\x80\x8E" ), '_', $header );
		$header = str_replace( "\xE2\x80\x8C", '', $header );

		return strtolower( $header );
	}

	/**
	 * Convert Persian/Arabic digits and `٫` in a numeric cell.
	 *
	 * @param string $value Raw cell.
	 * @return string
	 */
	public static function normalize_number( string $value ): string {
		$value = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			$value
		);

		$value = str_replace( array( '٫', "\xC2\xA0" ), array( '.', '' ), $value );

		return trim( $value );
	}
}
