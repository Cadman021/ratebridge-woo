<?php
/**
 * JSON Lines row file for chunked CSV imports (pure PHP).
 *
 * The import used to keep every parsed row in a transient, which means up to
 * 20,000 rows of `wp_options` (and a `max_allowed_packet` risk). Rows now go to a
 * private file — one JSON object per line — and the browser reads them back by
 * **byte offset**, so neither the upload nor the chunk step ever loads the whole
 * file into memory.
 *
 * No WordPress dependency: `tests/csv.php` exercises the writer/reader directly.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only writer and offset reader for `import-{token}.jsonl`.
 */
final class RowFile {

	/**
	 * Maximum number of rows a session may hold.
	 */
	public const MAX_ROWS = 20000;

	/**
	 * Absolute file path.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path to a `.jsonl` file.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * Write rows to a new file.
	 *
	 * Each row is a `string[]` of already-normalised cells. Rows are JSON
	 * encoded with `JSON_UNESCAPED_UNICODE` so Persian text stays readable, and
	 * every line ends with a single `\n` (JSONL: no blank lines, no BOM).
	 *
	 * @param array<int, array<int, string>> $rows Rows.
	 * @return array{ok:bool,error:string,rows:int,bytes:int}
	 */
	public function write( array $rows ): array {
		if ( count( $rows ) > self::MAX_ROWS ) {
			return array(
				'ok'    => false,
				'error' => 'too_many_rows',
				'rows'  => 0,
				'bytes' => 0,
			);
		}

		$handle = @fopen( $this->path, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- private temp file.

		if ( false === $handle ) {
			return array(
				'ok'    => false,
				'error' => 'not_writable',
				'rows'  => 0,
				'bytes' => 0,
			);
		}

		$written = 0;

		foreach ( $rows as $row ) {
			$line = $this->encode( is_array( $row ) ? $row : array() );

			if ( false === fwrite( $handle, $line ) ) {
				fclose( $handle );

				return array(
					'ok'    => false,
					'error' => 'write_failed',
					'rows'  => $written,
					'bytes' => 0,
				);
			}

			++$written;
		}

		fflush( $handle );
		fclose( $handle );

		$size = @filesize( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return array(
			'ok'    => true,
			'error' => '',
			'rows'  => $written,
			'bytes' => false === $size ? 0 : (int) $size,
		);
	}

	/**
	 * Encode one row as a JSONL line.
	 *
	 * @param array<int, mixed> $row Cells.
	 * @return string
	 */
	public function encode( array $row ): string {
		$cells = array();

		foreach ( $row as $cell ) {
			if ( ! is_scalar( $cell ) ) {
				$cells[] = '';
				continue;
			}

			$cells[] = (string) $cell;
		}

		$json = wp_json_encode_compat( $cells );

		return ( null === $json ? '[]' : $json ) . "\n";
	}

	/**
	 * Decode one JSONL line.
	 *
	 * @param string $line One line without the trailing newline.
	 * @return string[]|null Null when the line is not a JSON array.
	 */
	public function decode( string $line ): ?array {
		$line = trim( $line );

		if ( '' === $line ) {
			return null;
		}

		$decoded = json_decode( $line, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$cells = array();

		foreach ( $decoded as $cell ) {
			$cells[] = is_scalar( $cell ) ? (string) $cell : '';
		}

		return $cells;
	}

	/**
	 * Read up to $limit rows starting at a byte offset.
	 *
	 * Reads line by line so a chunk never buffers the whole file. A partial
	 * trailing line (never written by `write()`, but possible after a crash) is
	 * skipped rather than returned as data.
	 *
	 * @param int $offset Byte offset to start at.
	 * @param int $limit  Maximum rows.
	 * @return array{rows:array<int,string[]>,next:int,eof:bool,read:int}
	 *         `next` is the byte offset of the following chunk.
	 */
	public function read( int $offset, int $limit ): array {
		$offset = max( 0, $offset );
		$limit  = max( 1, $limit );

		$result = array(
			'rows' => array(),
			'next' => $offset,
			'eof'  => true,
			'read' => 0,
		);

		if ( ! is_readable( $this->path ) ) {
			return $result;
		}

		$handle = @fopen( $this->path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- private temp file.

		if ( false === $handle ) {
			return $result;
		}

		if ( $offset > 0 && 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );

			return $result;
		}

		$position = $offset;
		$count    = 0;

		while ( $count < $limit ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			$position += strlen( $line );

			// Only a trailing newline marks a complete row.
			if ( ! str_ends_with( $line, "\n" ) ) {
				break;
			}

			$cells = $this->decode( $line );

			if ( null === $cells ) {
				continue;
			}

			$result['rows'][] = $cells;
			++$count;
		}

		// Do we still have data after this chunk?
		$peek = fgets( $handle );

		$result['eof']  = false === $peek;
		$result['next'] = $position;
		$result['read'] = $count;

		fclose( $handle );

		return $result;
	}

	/**
	 * Number of rows in the file (counts newlines; cheap for a bounded file).
	 *
	 * @return int
	 */
	public function count_rows(): int {
		if ( ! is_readable( $this->path ) ) {
			return 0;
		}

		$handle = @fopen( $this->path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- private temp file.

		if ( false === $handle ) {
			return 0;
		}

		$count = 0;

		while ( false !== fgets( $handle ) ) {
			++$count;
		}

		fclose( $handle );

		return $count;
	}

	/**
	 * Absolute path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Delete the file (best effort).
	 *
	 * @return bool
	 */
	public function delete(): bool {
		if ( ! file_exists( $this->path ) ) {
			return true;
		}

		return (bool) @unlink( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Absolute byte size (0 when missing).
	 *
	 * @return int
	 */
	public function size(): int {
		$size = @filesize( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $size ? 0 : (int) $size;
	}
}

/**
 * `wp_json_encode()` without WordPress.
 *
 * Persian text is kept readable (`JSON_UNESCAPED_UNICODE`) and slashes are not
 * escaped, so a JSONL line stays diff-friendly and small.
 *
 * @param mixed $value Value.
 * @return string|null Null when encoding fails.
 */
function wp_json_encode_compat( mixed $value ): ?string {
	$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	return false === $json ? null : $json;
}
