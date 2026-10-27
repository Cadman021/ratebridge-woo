<?php
/**
 * Formula value object.
 *
 * @package RateBridge
 */

namespace RateBridge\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only representation of a row from the formulas table.
 */
final class FormulaModel {

	/**
	 * Variable type: a currency rate (store unit per 1 foreign unit).
	 */
	public const TYPE_CURRENCY = 'currency';

	/**
	 * Variable type: a WooCommerce product field (weight/dimensions).
	 */
	public const TYPE_PRODUCT = 'product';

	/**
	 * Variable type: a value typed by the admin.
	 */
	public const TYPE_CUSTOM = 'custom';

	/**
	 * Product fields a `product` variable may read.
	 *
	 * @var string[]
	 */
	public const PRODUCT_FIELDS = array( 'weight', 'length', 'width', 'height' );

	/**
	 * Constructor.
	 *
	 * @param int                          $id            Row id.
	 * @param string                       $name          Display name.
	 * @param string                       $slug          Unique ASCII slug.
	 * @param string                       $expression    Raw expression.
	 * @param string                       $description   Free text.
	 * @param array<int, array<string,mixed>> $variables Variable definitions.
	 * @param string                       $rounding_mode Override ('' = global).
	 * @param float                        $rounding_step Override step (0 = global).
	 * @param bool                         $is_enabled    Enabled flag.
	 * @param string                       $created_at    UTC datetime.
	 * @param string                       $updated_at    UTC datetime.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly string $expression,
		public readonly string $description,
		public readonly array $variables,
		public readonly string $rounding_mode,
		public readonly float $rounding_step,
		public readonly bool $is_enabled,
		public readonly string $created_at = '',
		public readonly string $updated_at = ''
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param object $row Row object.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		$variables = array();

		if ( ! empty( $row->variables ) ) {
			$decoded = json_decode( (string) $row->variables, true );

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $variable ) {
					$clean = self::sanitize_variable( $variable );

					if ( null !== $clean ) {
						$variables[] = $clean;
					}
				}
			}
		}

		return new self(
			(int) ( $row->id ?? 0 ),
			(string) ( $row->name ?? '' ),
			(string) ( $row->slug ?? '' ),
			(string) ( $row->expression ?? '' ),
			(string) ( $row->description ?? '' ),
			$variables,
			(string) ( $row->rounding_mode ?? '' ),
			(float) ( $row->rounding_step ?? 0 ),
			! empty( $row->is_enabled ),
			(string) ( $row->created_at ?? '' ),
			(string) ( $row->updated_at ?? '' )
		);
	}

	/**
	 * Sanitise one variable definition.
	 *
	 * @param mixed $variable Raw definition.
	 * @return array<string, mixed>|null
	 */
	public static function sanitize_variable( mixed $variable ): ?array {
		if ( ! is_array( $variable ) ) {
			return null;
		}

		$name = isset( $variable['name'] ) ? (string) $variable['name'] : '';

		if ( ! Formula::is_valid_variable_name( $name ) ) {
			return null;
		}

		$type = isset( $variable['type'] ) ? sanitize_key( (string) $variable['type'] ) : '';

		if ( ! in_array( $type, array( self::TYPE_CURRENCY, self::TYPE_PRODUCT, self::TYPE_CUSTOM ), true ) ) {
			$type = self::TYPE_CUSTOM;
		}

		$clean = array(
			'name'  => $name,
			'label' => isset( $variable['label'] ) ? sanitize_text_field( (string) $variable['label'] ) : $name,
			'type'  => $type,
		);

		if ( self::TYPE_CURRENCY === $type ) {
			$code = isset( $variable['currency_code'] ) ? strtoupper( sanitize_text_field( (string) $variable['currency_code'] ) ) : '';
			$code = preg_replace( '/[^A-Z0-9_\-]/', '', $code ) ?? '';

			$clean['currency_code'] = $code;
		}

		if ( self::TYPE_PRODUCT === $type ) {
			$field = isset( $variable['product_field'] ) ? sanitize_key( (string) $variable['product_field'] ) : 'weight';

			$clean['product_field'] = in_array( $field, self::PRODUCT_FIELDS, true ) ? $field : 'weight';
		}

		if ( self::TYPE_CUSTOM === $type ) {
			$clean['default']  = isset( $variable['default'] ) ? max( 0.0, (float) $variable['default'] ) : 0.0;
			$clean['is_money'] = ! empty( $variable['is_money'] );
			$clean['min']      = isset( $variable['min'] ) && '' !== $variable['min'] ? (float) $variable['min'] : null;
			$clean['max']      = isset( $variable['max'] ) && '' !== $variable['max'] ? (float) $variable['max'] : null;
		}

		return $clean;
	}

	/**
	 * Sanitise a whole variable list.
	 *
	 * @param mixed $variables Raw list.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_variables( mixed $variables ): array {
		if ( is_string( $variables ) ) {
			$decoded = json_decode( $variables, true );
			$variables = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $variables ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $variables as $variable ) {
			$item = self::sanitize_variable( $variable );

			if ( null === $item ) {
				continue;
			}

			// Names are identifiers: a duplicate would silently shadow the first.
			if ( isset( $seen[ $item['name'] ] ) ) {
				continue;
			}

			$seen[ $item['name'] ] = true;
			$clean[]               = $item;
		}

		return $clean;
	}

	/**
	 * Variable definitions keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function variables_by_name(): array {
		$map = array();

		foreach ( $this->variables as $variable ) {
			$map[ (string) $variable['name'] ] = $variable;
		}

		return $map;
	}

	/**
	 * Variable names.
	 *
	 * @return string[]
	 */
	public function variable_names(): array {
		return array_keys( $this->variables_by_name() );
	}

	/**
	 * Currency codes referenced by currency variables.
	 *
	 * @return string[]
	 */
	public function currency_codes(): array {
		$codes = array();

		foreach ( $this->variables as $variable ) {
			if ( self::TYPE_CURRENCY === $variable['type'] && ! empty( $variable['currency_code'] ) ) {
				$codes[] = (string) $variable['currency_code'];
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Variables of one type.
	 *
	 * @param string $type currency|product|custom.
	 * @return array<int, array<string, mixed>>
	 */
	public function variables_of_type( string $type ): array {
		return array_values(
			array_filter(
				$this->variables,
				static fn ( array $variable ): bool => $type === $variable['type']
			)
		);
	}

	/**
	 * Convert to an array (AJAX payloads, tests).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'name'          => $this->name,
			'slug'          => $this->slug,
			'expression'    => $this->expression,
			'description'   => $this->description,
			'variables'     => $this->variables,
			'rounding_mode' => $this->rounding_mode,
			'rounding_step' => $this->rounding_step,
			'is_enabled'    => $this->is_enabled,
			'created_at'    => $this->created_at,
			'updated_at'    => $this->updated_at,
		);
	}

	/**
	 * Compile the expression (cached by the caller when needed).
	 *
	 * @return CompiledFormula|FormulaError
	 */
	public function compile(): CompiledFormula|FormulaError {
		return Formula::compile( $this->expression );
	}
}
