<?php
/**
 * JSON package envelope: validation and parsing (pure PHP).
 *
 * The package carries settings + currencies + formulas. This class knows the
 * schema, validates the envelope and returns a normalised structure. It never
 * touches WordPress, so `tests/import-export.php` can exercise it directly.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * A validation error with a machine code.
 */
final class PackageError {

	/**
	 * Constructor.
	 *
	 * @param string $code    Machine code.
	 * @param string $detail  Extra context (path of the offending key).
	 * @param int    $position Not used for JSON; kept for symmetry.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $detail = '',
		public readonly int $position = 0
	) {}

	/**
	 * Serialise.
	 *
	 * @return array{code:string,detail:string}
	 */
	public function to_array(): array {
		return array(
			'code'   => $this->code,
			'detail' => $this->detail,
		);
	}
}

/**
 * Validates and normalises a RateBridge package.
 */
final class Package {

	/**
	 * Envelope discriminator.
	 */
	public const FORMAT = 'ratebridge-woo-package';

	/**
	 * Supported schema version.
	 */
	public const SCHEMA = 1;

	/**
	 * Maximum accepted file size in bytes (2 MB).
	 */
	public const MAX_BYTES = 2097152;

	/**
	 * Maximum JSON nesting depth accepted by json_decode.
	 */
	public const MAX_DEPTH = 16;

	/**
	 * Section names a package may contain.
	 *
	 * @return string[]
	 */
	public static function sections(): array {
		return array( 'settings', 'currencies', 'formulas' );
	}

	/**
	 * Keys that must never appear anywhere in an exported package.
	 *
	 * Provider settings (API keys, tokens), request-budget counters and logs are
	 * deliberately excluded from the export; this list is also the guard used by
	 * the unit test.
	 *
	 * @return string[]
	 */
	public static function forbidden_key_patterns(): array {
		return array( 'key', 'token', 'secret', 'password', 'passwd', 'auth', 'credential', 'budget', '_log', 'log' );
	}

	/**
	 * Settings keys that legitimately contain a forbidden substring.
	 *
	 * `default_provider` is configuration, not a credential.
	 *
	 * @return string[]
	 */
	public static function allowed_keys(): array {
		return array(
			'store_currency_unit',
			'rounding_mode',
			'rounding_step',
			'default_update_interval',
			'delete_data_on_uninstall',
			'default_provider',
			'max_rate_age_hours',
			'show_rate_in_orders',
			'product_block_enabled',
			'product_block_position',
			'product_block_template',
			'date_format',
			'use_persian_digits',
		);
	}

	/**
	 * Validate a raw JSON string.
	 *
	 * @param string $json Raw bytes.
	 * @return array<string, mixed> {
	 *     @type bool          $ok       Whether the package is usable.
	 *     @type PackageError|null $error Validation error.
	 *     @type array<string, mixed> $data Normalised package.
	 * }
	 */
	public static function validate_json( string $json ): array {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return self::fail( 'too_large' );
		}

		if ( ! Csv::is_valid_utf8( $json ) ) {
			return self::fail( 'not_utf8' );
		}

		$json = Csv::strip_bom( $json );

		$decoded = json_decode( $json, true, self::MAX_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$message = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'invalid';

			// Depth errors are worth their own code so the UI can explain them.
			$code = JSON_ERROR_DEPTH === json_last_error() ? 'too_deep' : 'invalid_json';

			return self::fail( $code, $message );
		}

		return self::validate( $decoded );
	}

	/**
	 * Validate an already-decoded package.
	 *
	 * @param mixed $data Decoded data.
	 * @return array<string, mixed>
	 */
	public static function validate( mixed $data ): array {
		if ( ! is_array( $data ) ) {
			return self::fail( 'not_an_object' );
		}

		$format = isset( $data['format'] ) ? (string) $data['format'] : '';

		if ( self::FORMAT !== $format ) {
			return self::fail( 'bad_format', $format );
		}

		$schema = isset( $data['schema'] ) ? (int) $data['schema'] : 0;

		if ( $schema < 1 ) {
			return self::fail( 'bad_schema', (string) $schema );
		}

		if ( $schema > self::SCHEMA ) {
			return self::fail( 'unsupported_schema', (string) $schema );
		}

		$sections = isset( $data['sections'] ) && is_array( $data['sections'] ) ? $data['sections'] : array();

		$clean = array(
			'format'         => self::FORMAT,
			'schema'         => $schema,
			'plugin_version' => isset( $data['plugin_version'] ) ? (string) $data['plugin_version'] : '',
			'exported_at'    => isset( $data['exported_at'] ) ? (string) $data['exported_at'] : '',
			'store_unit'     => self::unit( $data['store_unit'] ?? 'toman' ),
			'site'           => isset( $data['site'] ) ? (string) $data['site'] : '',
			'sections'       => array(),
		);

		foreach ( self::sections() as $section ) {
			if ( ! array_key_exists( $section, $sections ) ) {
				continue;
			}

			$value = $sections[ $section ];

			if ( ! is_array( $value ) ) {
				return self::fail( 'bad_section', $section );
			}

			$clean['sections'][ $section ] = $section === 'settings'
				? self::clean_settings( $value )
				: array_values( array_filter( $value, 'is_array' ) );
		}

		if ( empty( $clean['sections'] ) ) {
			return self::fail( 'no_sections' );
		}

		return array(
			'ok'    => true,
			'error' => null,
			'data'  => $clean,
		);
	}

	/**
	 * Keep only the general settings, dropping anything credential-shaped.
	 *
	 * @param array<string, mixed> $settings Raw settings map.
	 * @return array<string, mixed>
	 */
	public static function clean_settings( array $settings ): array {
		$clean = array();

		foreach ( self::allowed_keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$clean[ $key ] = $settings[ $key ];
			}
		}

		return $clean;
	}

	/**
	 * Whether a key name looks like a credential.
	 *
	 * @param string $key Key name.
	 * @return bool
	 */
	public static function is_forbidden_key( string $key ): bool {
		$key = strtolower( $key );

		if ( in_array( $key, self::allowed_keys(), true ) ) {
			return false;
		}

		foreach ( self::forbidden_key_patterns() as $pattern ) {
			if ( str_contains( $key, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively find forbidden keys in a structure (used by the leak test).
	 *
	 * @param mixed  $value Raw structure.
	 * @param string $path  Current path for the report.
	 * @return string[] Paths of offending keys.
	 */
	public static function find_forbidden_keys( mixed $value, string $path = '' ): array {
		$found = array();

		if ( ! is_array( $value ) ) {
			return $found;
		}

		foreach ( $value as $key => $child ) {
			$child_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_string( $key ) && self::is_forbidden_key( $key ) ) {
				$found[] = $child_path;
			}

			$found = array_merge( $found, self::find_forbidden_keys( $child, $child_path ) );
		}

		return $found;
	}

	/**
	 * Normalise a unit key.
	 *
	 * @param mixed $unit Raw value.
	 * @return string
	 */
	public static function unit( mixed $unit ): string {
		return 'rial' === $unit ? 'rial' : 'toman';
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $code   Code.
	 * @param string $detail Detail.
	 * @return array<string, mixed>
	 */
	private static function fail( string $code, string $detail = '' ): array {
		return array(
			'ok'    => false,
			'error' => new PackageError( $code, $detail ),
			'data'  => array(),
		);
	}
}
