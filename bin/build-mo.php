<?php
/**
 * Dependency-free .po → .mo compiler.
 *
 * Usage:
 *   php bin/build-mo.php                        # compiles every .po in languages/
 *   php bin/build-mo.php path/to/file.po        # compiles one file
 *
 * Produces a sibling .mo file using the GNU MO binary layout (little endian),
 * so no gettext/msgfmt tooling is required on the build machine.
 *
 * @package RateBridge
 */

declare( strict_types=1 );

/**
 * Minimal PO parser and MO writer.
 */
final class RbwPoCompiler {

	/**
	 * Parsed entries: array of array( context, msgid, msgid_plural, msgstr[] ).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $entries = array();

	/**
	 * Parse a .po file into entries.
	 *
	 * @param string $path Path to the .po file.
	 * @return bool False when the file cannot be read.
	 */
	public function parse( string $path ): bool {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			return false;
		}

		$current = $this->blank_entry();
		$field   = '';   // Which field the next continuation belongs to.
		$index   = 0;    // Plural index for msgstr[N].
		$seen    = false; // Whether the current entry has seen any msgid line.

		while ( false !== ( $line = fgets( $handle ) ) ) {
			$line = rtrim( $line, "\r\n" );

			if ( '' === trim( $line ) || '#' === substr( ltrim( $line ), 0, 1 ) ) {
				// Blank line terminates the current entry (the header uses an empty
				// msgid, so a plain truthiness test would drop it).
				if ( '' === trim( $line ) && $seen ) {
					$this->entries[] = $current;
					$current         = $this->blank_entry();
					$field           = '';
					$index           = 0;
					$seen            = false;
				}
				continue;
			}

			if ( preg_match( '/^msgctxt\s+"(.*)"$/', $line, $m ) ) {
				$current['msgctxt'] = $this->unescape( $m[1] );
				$field              = 'msgctxt';
				continue;
			}

			if ( preg_match( '/^msgid_plural\s+"(.*)"$/', $line, $m ) ) {
				$current['msgid_plural'] = $this->unescape( $m[1] );
				$field                   = 'msgid_plural';
				continue;
			}

			if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
				$current['msgid'] = $this->unescape( $m[1] );
				$field            = 'msgid';
				$seen             = true;
				continue;
			}

			if ( preg_match( '/^msgstr\[(\d+)\]\s+"(.*)"$/', $line, $m ) ) {
				$index                        = (int) $m[1];
				$current['msgstr'][ $index ]  = $this->unescape( $m[2] );
				$field                        = 'msgstr';
				continue;
			}

			if ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
				$current['msgstr'][0] = $this->unescape( $m[1] );
				$field                = 'msgstr';
				$index                = 0;
				continue;
			}

			if ( preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$value = $this->unescape( $m[1] );

				if ( 'msgctxt' === $field ) {
					$current['msgctxt'] .= $value;
				} elseif ( 'msgid_plural' === $field ) {
					$current['msgid_plural'] .= $value;
				} elseif ( 'msgid' === $field ) {
					$current['msgid'] .= $value;
				} elseif ( 'msgstr' === $field ) {
					$current['msgstr'][ $index ] = ( $current['msgstr'][ $index ] ?? '' ) . $value;
				}
			}
		}

		// Trailing entry without a blank line.
		if ( $seen ) {
			$this->entries[] = $current;
		}

		fclose( $handle );

		return true;
	}

	/**
	 * A blank entry structure.
	 *
	 * @return array<string, mixed>
	 */
	private function blank_entry(): array {
		return array(
			'msgctxt'      => '',
			'msgid'        => '',
			'msgid_plural' => '',
			'msgstr'       => array(),
		);
	}

	/**
	 * Convert PO escape sequences into raw bytes.
	 *
	 * @param string $value Escaped value.
	 * @return string
	 */
	private function unescape( string $value ): string {
		return str_replace(
			array( '\\n', '\\t', '\\r', '\\"', '\\\\' ),
			array( "\n", "\t", "\r", '"', '\\' ),
			$value
		);
	}

	/**
	 * Build the binary .mo content.
	 *
	 * @return string
	 */
	public function to_mo(): string {
		// The header entry is the one with an empty msgid.
		$pairs = array();

		foreach ( $this->entries as $entry ) {
			$msgid  = (string) $entry['msgid'];
			$plural = (string) $entry['msgid_plural'];
			$ctxt   = (string) $entry['msgctxt'];
			$strs   = $entry['msgstr'];

			// Skip untranslated entries, but always keep the header.
			if ( '' !== $msgid && '' === ( $strs[0] ?? '' ) ) {
				continue;
			}

			$key = '' !== $ctxt ? $ctxt . "\x04" . $msgid : $msgid;

			if ( '' !== $plural ) {
				$key .= "\x00" . $plural;
			}

			$pairs[ $key ] = implode( "\x00", $strs );
		}

		ksort( $pairs, SORT_STRING );

		$count   = count( $pairs );
		$keys    = array_keys( $pairs );
		$values  = array_values( $pairs );

		// Layout: header (28) + orig table + trans table + string data.
		$header_size   = 28;
		$table_size    = $count * 8;
		$data_offset   = $header_size + ( $table_size * 2 );
		$orig_table    = '';
		$trans_table   = '';
		$string_data   = '';

		foreach ( $keys as $index => $key ) {
			$length = strlen( $key );

			$orig_table .= pack( 'VV', $length, $data_offset + strlen( $string_data ) );
			$string_data .= $key . "\x00";
		}

		foreach ( $values as $value ) {
			$length = strlen( $value );

			$trans_table .= pack( 'VV', $length, $data_offset + strlen( $string_data ) );
			$string_data .= $value . "\x00";
		}

		$header = pack(
			'V7',
			0x950412de, // Magic.
			0,          // Format revision.
			$count,     // Number of strings.
			$header_size, // Offset of the original strings table.
			$header_size + $table_size, // Offset of the translations table.
			0,          // Hash table size (unused).
			$data_offset // Offset of the string data.
		);

		return $header . $orig_table . $trans_table . $string_data;
	}

	/**
	 * Number of parsed entries.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->entries );
	}

	/**
	 * Entries that have an empty translation (excluding the header).
	 *
	 * @return string[]
	 */
	public function untranslated(): array {
		$missing = array();

		foreach ( $this->entries as $entry ) {
			$msgid = (string) $entry['msgid'];

			if ( '' === $msgid ) {
				continue;
			}

			if ( '' === ( $entry['msgstr'][0] ?? '' ) ) {
				$missing[] = $msgid;
			}
		}

		return $missing;
	}
}

/* ------------------------------------------------------------------------- */
/* CLI                                                                        */
/* ------------------------------------------------------------------------- */

$arguments = $argv ?? array();
$targets   = array_slice( $arguments, 1 );

if ( empty( $targets ) ) {
	$found = glob( __DIR__ . '/../languages/*.po' );

	$targets = is_array( $found ) ? $found : array();
}

if ( empty( $targets ) ) {
	fwrite( STDERR, "No .po files found. Pass a path or add files to languages/.\n" );
	exit( 1 );
}

$exit = 0;

foreach ( $targets as $po_path ) {
	if ( ! is_readable( $po_path ) ) {
		fwrite( STDERR, "Cannot read: {$po_path}\n" );
		$exit = 1;
		continue;
	}

	$compiler = new RbwPoCompiler();

	if ( ! $compiler->parse( $po_path ) ) {
		fwrite( STDERR, "Cannot parse: {$po_path}\n" );
		$exit = 1;
		continue;
	}

	$mo_path = preg_replace( '/\.po$/', '.mo', $po_path ) ?? ( $po_path . '.mo' );
	$binary  = $compiler->to_mo();

	if ( false === file_put_contents( $mo_path, $binary ) ) {
		fwrite( STDERR, "Cannot write: {$mo_path}\n" );
		$exit = 1;
		continue;
	}

	printf(
		"Compiled %s -> %s (%d entries, %d bytes)\n",
		basename( $po_path ),
		basename( $mo_path ),
		$compiler->count(),
		strlen( $binary )
	);

	$missing = $compiler->untranslated();

	if ( ! empty( $missing ) ) {
		printf( "  warning: %d untranslated entries\n", count( $missing ) );

		foreach ( array_slice( $missing, 0, 10 ) as $entry ) {
			printf( "    - %s\n", $entry );
		}
	}
}

exit( $exit );
