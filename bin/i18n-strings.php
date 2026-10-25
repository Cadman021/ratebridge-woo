<?php
/**
 * Dependency-free string extractor + .pot generator.
 *
 * Usage: php bin/i18n-strings.php [--pot]
 *
 * Scans PHP files for WordPress i18n calls with the `ratebridge-woo` domain and
 * prints unique English source strings (or a POT skeleton with --pot).
 *
 * @package RateBridge
 */

declare( strict_types=1 );

namespace RateBridge\Tools;

/**
 * Collects translatable strings via the PHP tokenizer.
 */
final class Extractor {

	/**
	 * i18n functions whose first argument is the singular string.
	 */
	private const SINGLE = array(
		'__',
		'_e',
		'esc_html__',
		'esc_html_e',
		'esc_attr__',
		'esc_attr_e',
		'esc_html_x',
		'esc_attr_x',
		'_x',
	);

	/**
	 * Plural functions: arg 1 singular, arg 2 plural.
	 */
	private const PLURAL = array( '_n', '_nx' );

	/**
	 * Domain to filter on.
	 */
	private const DOMAIN = 'ratebridge-woo';

	/**
	 * Collected entries: text => array( plural => string|null, context => string|null ).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $entries = array();

	/**
	 * Scan a list of directories/files.
	 *
	 * @param string[] $paths Paths to scan.
	 * @return void
	 */
	public function scan( array $paths ): void {
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				$this->scan_file( $path );
				continue;
			}

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path ) );

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$this->scan_file( $file->getPathname() );
				}
			}
		}
	}

	/**
	 * Tokenize one file and pull out literal strings.
	 *
	 * @param string $path File path.
	 * @return void
	 */
	private function scan_file( string $path ): void {
		$source = (string) file_get_contents( $path );
		$tokens = token_get_all( $source );

		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			$function = strtolower( $token[1] );

			if ( ! in_array( $function, self::SINGLE, true ) && ! in_array( $function, self::PLURAL, true ) ) {
				continue;
			}

			$args = $this->collect_args( $tokens, $i );

			if ( null === $args ) {
				continue;
			}

			$is_plural = in_array( $function, self::PLURAL, true );

			// Domain position differs: _n(s,p,n,d) and _x(t,c,d) then normal.
			$domain_index = $is_plural ? 3 : ( in_array( $function, array( '_x', '_nx', 'esc_html_x', 'esc_attr_x' ), true ) ? 2 : 1 );

			if ( ! isset( $args[ $domain_index ] ) || self::DOMAIN !== $args[ $domain_index ] ) {
				continue;
			}

			$single = $args[0] ?? '';

			if ( '' === $single ) {
				continue;
			}

			$plural  = $is_plural ? ( $args[1] ?? '' ) : null;
			$context = null;

			if ( in_array( $function, array( '_x', '_nx', 'esc_html_x', 'esc_attr_x' ), true ) ) {
				$context = $is_plural ? ( $args[2] ?? '' ) : ( $args[1] ?? '' );
			}

			if ( ! isset( $this->entries[ $single ] ) ) {
				$this->entries[ $single ] = array(
					'plural'  => $plural,
					'context' => $context,
				);
			}
		}
	}

	/**
	 * Collect the literal arguments of a call starting at $start.
	 *
	 * Returns null when any argument is not a plain string literal (dynamic).
	 *
	 * @param array<int, mixed> $tokens All tokens.
	 * @param int               $start  Index of the function name token.
	 * @return string[]|null
	 */
	private function collect_args( array $tokens, int $start ): ?array {
		$count = count( $tokens );
		$i     = $start + 1;

		// Skip whitespace up to "(".
		while ( $i < $count && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			++$i;
		}

		if ( $i >= $count || '(' !== $tokens[ $i ] ) {
			return null;
		}

		++$i;

		$args       = array();
		$current    = '';
		$is_literal = true;
		$depth      = 0;

		for ( ; $i < $count; $i++ ) {
			$t = $tokens[ $i ];

			if ( is_array( $t ) ) {
				if ( T_CONSTANT_ENCAPSED_STRING === $t[0] && 0 === $depth ) {
					$current .= $this->unquote( $t[1] );
					continue;
				}

				if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) && 0 === $depth ) {
					continue;
				}

				// Anything else makes the argument dynamic.
				$is_literal = false;

				if ( in_array( $t[0], array( T_LNUMBER, T_DNUMBER, T_STRING, T_VARIABLE ), true ) && 0 === $depth ) {
					$current .= $t[1];
				}

				continue;
			}

			if ( '(' === $t || '[' === $t ) {
				++$depth;
				$is_literal = false;
				continue;
			}

			if ( ')' === $t || ']' === $t ) {
				if ( 0 === $depth ) {
					// Closing paren of the call itself.
					$args[] = $is_literal ? $current : "\0dynamic";

					return $args;
				}

				--$depth;
				continue;
			}

			if ( ',' === $t && 0 === $depth ) {
				$args[]     = $is_literal ? $current : "\0dynamic";
				$current    = '';
				$is_literal = true;
			}
		}

		return null;
	}

	/**
	 * Turn a PHP string literal token into its value.
	 *
	 * @param string $literal Token text including quotes.
	 * @return string
	 */
	private function unquote( string $literal ): string {
		$inner = substr( $literal, 1, -1 );

		if ( str_contains( $literal, "'" ) ) {
			return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner );
		}

		return stripcslashes( $inner );
	}

	/**
	 * Sorted entries.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function entries(): array {
		$entries = $this->entries;

		ksort( $entries );

		return $entries;
	}

	/**
	 * Render a POT skeleton.
	 *
	 * @return string
	 */
	public function to_pot(): string {
		$out = "msgid \"\"\n";
		$out .= "msgstr \"\"\n";
		$out .= "\"Project-Id-Version: RateBridge Woo\\n\"\n";
		$out .= "\"Report-Msgid-Bugs-To: https://example.com/ratebridge-woo\\n\"\n";
		$out .= "\"MIME-Version: 1.0\\n\"\n";
		$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
		$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
		$out .= "\"X-Domain: " . self::DOMAIN . "\\n\"\n\n";

		foreach ( $this->entries() as $text => $meta ) {
			$out .= '#: ' . self::DOMAIN . "\n";

			if ( ! empty( $meta['context'] ) ) {
				$out .= 'msgctxt "' . $this->esc( (string) $meta['context'] ) . "\"\n";
			}

			$out .= 'msgid "' . $this->esc( (string) $text ) . "\"\n";
			$out .= 'msgstr ""' . "\n\n";
		}

		return $out;
	}

	/**
	 * Escape a string for a .po quoted value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function esc( string $value ): string {
		return str_replace(
			array( '\\', '"', "\n", "\t" ),
			array( '\\\\', '\\"', '\\n', '\\t' ),
			$value
		);
	}
}

$paths = array(
	__DIR__ . '/../ratebridge-woo.php',
	__DIR__ . '/../includes',
	__DIR__ . '/../templates',
);

$extractor = new Extractor();
$extractor->scan( $paths );

if ( in_array( '--pot', $argv ?? array(), true ) ) {
	echo $extractor->to_pot();
	exit( 0 );
}

foreach ( array_keys( $extractor->entries() ) as $string ) {
	echo $string, "\n";
}
