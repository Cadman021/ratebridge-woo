<?php
/**
 * Formula pricing: variable resolution + price post-processing.
 *
 * Bridges the pure `RateBridge\Formula` engine to WooCommerce. It resolves the
 * variable values for a product (currency rates, WC product fields, per-product
 * custom overrides) and then applies the SAME post-processing steps the currency
 * mode uses: minimum price, rounding, fluctuation margin.
 *
 * Formula mode deliberately does NOT apply the currency/product profit,
 * commission or extra rules — the formula itself expresses them.
 *
 * @package RateBridge
 */

namespace RateBridge\Pricing;

use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Formula\CompiledFormula;
use RateBridge\Formula\Formula;
use RateBridge\Formula\FormulaError;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Formula\Result;
use RateBridge\Product\ProductData;
use WC_Product;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves formula variables and computes the final price.
 */
final class FormulaPricer {

	/**
	 * Error code: the assigned formula does not exist anymore.
	 */
	public const ERROR_FORMULA_MISSING = 'formula_missing';

	/**
	 * Error code: the formula is disabled.
	 */
	public const ERROR_FORMULA_DISABLED = 'formula_disabled';

	/**
	 * Error code: the formula expression does not compile.
	 */
	public const ERROR_FORMULA_INVALID = 'formula_invalid';

	/**
	 * Error code: a currency variable has no usable rate.
	 */
	public const ERROR_RATE = 'formula_rate_unusable';

	/**
	 * Error code: a currency variable's rate is stale.
	 */
	public const ERROR_RATE_STALE = 'formula_rate_stale';

	/**
	 * Error code: a `product` variable has no value (e.g. no weight).
	 */
	public const ERROR_MISSING_PRODUCT_VALUE = 'formula_missing_product_value';

	/**
	 * Error code: a custom variable has no value or default.
	 */
	public const ERROR_MISSING_CUSTOM = 'formula_missing_custom';

	/**
	 * Error code: a custom variable violates min/max.
	 */
	public const ERROR_OUT_OF_RANGE = 'formula_out_of_range';

	/**
	 * Repository.
	 *
	 * @var FormulaRepository
	 */
	private FormulaRepository $repository;

	/**
	 * Currency repository.
	 *
	 * @var CurrencyRepository
	 */
	private CurrencyRepository $currencies;

	/**
	 * Compiled expressions cache (per request).
	 *
	 * @var array<string, CompiledFormula|FormulaError>
	 */
	private static array $compiled = array();

	/**
	 * Constructor.
	 *
	 * @param FormulaRepository|null  $repository Formula repository.
	 * @param CurrencyRepository|null $currencies Currency repository.
	 */
	public function __construct( ?FormulaRepository $repository = null, ?CurrencyRepository $currencies = null ) {
		$this->repository = $repository ?? new FormulaRepository();
		$this->currencies = $currencies ?? new CurrencyRepository();
	}

	/**
	 * Formula assigned to a product (variation inherits from its parent).
	 *
	 * @param int $post_id Product or variation id.
	 * @return FormulaModel|null
	 */
	public function formula_for( int $post_id ): ?FormulaModel {
		$id = ProductData::formula_id( $post_id );

		if ( 0 === $id ) {
			$parent = (int) wp_get_post_parent_id( $post_id );

			if ( $parent > 0 ) {
				$id = ProductData::formula_id( $parent );
			}
		}

		return $id > 0 ? $this->repository->get( $id ) : null;
	}

	/**
	 * Whether a product is priced by a formula and the formula is usable.
	 *
	 * @param int $post_id Product id.
	 * @return bool
	 */
	public function is_formula_product( int $post_id ): bool {
		return ProductData::is_formula_mode( $post_id ) && null !== $this->formula_for( $post_id );
	}

	/**
	 * Compiled expression for a formula (cached per request).
	 *
	 * @param FormulaModel $formula Formula.
	 * @return CompiledFormula|FormulaError
	 */
	public function compiled( FormulaModel $formula ): CompiledFormula|FormulaError {
		$key = $formula->id . '|' . md5( $formula->expression );

		if ( ! isset( self::$compiled[ $key ] ) ) {
			self::$compiled[ $key ] = Formula::compile( $formula->expression );
		}

		return self::$compiled[ $key ];
	}

	/**
	 * Resolve every variable value for a product.
	 *
	 * @param FormulaModel $formula Formula.
	 * @param WC_Product   $product Product (variation aware).
	 * @param bool         $strict  Whether unusable rates/values are errors.
	 * @return array<string, float>|FormulaError Values, or the first blocking error.
	 */
	public function resolve_variables( FormulaModel $formula, WC_Product $product, bool $strict = true ): array|FormulaError {
		$values    = array();
		$product_id = (int) $product->get_id();
		$parent_id  = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		// Overrides can live on the variation or on the parent.
		$overrides = ProductData::formula_overrides( $product_id );

		if ( empty( $overrides ) && $parent_id > 0 ) {
			$overrides = ProductData::formula_overrides( $parent_id );
		}

		foreach ( $formula->variables as $variable ) {
			$name = (string) $variable['name'];
			$type = (string) $variable['type'];

			if ( FormulaModel::TYPE_CURRENCY === $type ) {
				$currency = $this->currencies->get_by_code( (string) ( $variable['currency_code'] ?? '' ) );

				if ( ! $currency instanceof Currency || $currency->rate <= 0 ) {
					if ( $strict ) {
						return new FormulaError( self::ERROR_RATE, 0, $name );
					}

					$values[ $name ] = 0.0;
					continue;
				}

				$values[ $name ] = (float) $currency->rate;
				continue;
			}

			if ( FormulaModel::TYPE_PRODUCT === $type ) {
				$field = (string) ( $variable['product_field'] ?? 'weight' );
				$value = $this->product_field( $product, $field, $parent_id );

				if ( null === $value ) {
					if ( $strict ) {
						return new FormulaError( self::ERROR_MISSING_PRODUCT_VALUE, 0, $name );
					}

					$values[ $name ] = 0.0;
					continue;
				}

				$values[ $name ] = $value;
				continue;
			}

			// Custom variable: override, else the formula default.
			if ( array_key_exists( $name, $overrides ) ) {
				$value = (float) $overrides[ $name ];
			} elseif ( isset( $variable['default'] ) && is_numeric( $variable['default'] ) ) {
				$value = (float) $variable['default'];
			} else {
				if ( $strict ) {
					return new FormulaError( self::ERROR_MISSING_CUSTOM, 0, $name );
				}

				$values[ $name ] = 0.0;
				continue;
			}

			$min = $variable['min'] ?? null;
			$max = $variable['max'] ?? null;

			if ( is_numeric( $min ) && $value < (float) $min ) {
				if ( $strict ) {
					return new FormulaError( self::ERROR_OUT_OF_RANGE, 0, $name );
				}

				$value = (float) $min;
			}

			if ( is_numeric( $max ) && $value > (float) $max ) {
				if ( $strict ) {
					return new FormulaError( self::ERROR_OUT_OF_RANGE, 0, $name );
				}

				$value = (float) $max;
			}

			$values[ $name ] = $value;
		}

		return $values;
	}

	/**
	 * Read a WC product field with variation → parent inheritance.
	 *
	 * @param WC_Product $product   Product.
	 * @param string     $field     weight|length|width|height.
	 * @param int        $parent_id Parent id (0 for simple products).
	 * @return float|null Null when the product has no value.
	 */
	public function product_field( WC_Product $product, string $field, int $parent_id = 0 ): ?float {
		if ( ! in_array( $field, FormulaModel::PRODUCT_FIELDS, true ) ) {
			return null;
		}

		$getter = 'get_' . $field;

		if ( ! method_exists( $product, $getter ) ) {
			return null;
		}

		$value = $product->{$getter}();

		if ( '' !== $value && null !== $value && is_numeric( $value ) && (float) $value > 0 ) {
			return (float) $value;
		}

		// Variations inherit the parent's measurements when they have none.
		if ( $product instanceof WC_Product_Variation && $parent_id > 0 ) {
			$parent = wc_get_product( $parent_id );

			if ( $parent instanceof WC_Product && method_exists( $parent, $getter ) ) {
				$parent_value = $parent->{$getter}();

				if ( '' !== $parent_value && null !== $parent_value && is_numeric( $parent_value ) && (float) $parent_value > 0 ) {
					return (float) $parent_value;
				}
			}
		}

		return null;
	}

	/**
	 * Compute the final price of a formula product.
	 *
	 * @param WC_Product   $product  Product.
	 * @param FormulaModel $formula  Formula.
	 * @param float        $previous Previous price (fluctuation baseline).
	 * @param bool         $strict   Whether missing inputs block the calculation.
	 * @return array<string, mixed> {
	 *     @type bool   $ok        Whether a price was produced.
	 *     @type string $code      Error code when !ok.
	 *     @type float  $value     Raw formula result.
	 *     @type float  $price     Final price after min/round/fluctuation.
	 *     @type array  $breaks    Breakdown rows for the preview.
	 *     @type array  $variables Variable values used.
	 *     @type bool   $kept      Fluctuation kept the previous price.
	 * }
	 */
	public function calculate( WC_Product $product, FormulaModel $formula, float $previous = 0.0, bool $strict = true ): array {
		$product_id = (int) $product->get_id();

		if ( ! $formula->is_enabled ) {
			return $this->failure( self::ERROR_FORMULA_DISABLED );
		}

		$compiled = $this->compiled( $formula );

		if ( $compiled instanceof FormulaError ) {
			return $this->failure( self::ERROR_FORMULA_INVALID, $compiled );
		}

		$variables = $this->resolve_variables( $formula, $product, $strict );

		if ( $variables instanceof FormulaError ) {
			return $this->failure( $variables->code, $variables );
		}

		$result = $compiled->evaluate( $variables );

		if ( ! $result instanceof Result ) {
			return $this->failure( $result->code, $result );
		}

		if ( ! is_finite( $result->value ) ) {
			return $this->failure( 'not_finite' );
		}

		$value = (float) $result->value;

		// Reuse the exact post-processing steps of the currency mode.
		$processed = $this->post_process( $product_id, $value, $previous, $formula );

		$breaks = array(
			array(
				'key'   => 'formula',
				'label' => __( 'Formula result', 'ratebridge-woo' ),
				'value' => $value,
			),
		);

		foreach ( $processed['steps'] as $step ) {
			$breaks[] = $step;
		}

		if ( ! $processed['ok'] ) {
			return array_merge( $this->failure( (string) $processed['code'] ), array( 'breaks' => $breaks ) );
		}

		return array(
			'ok'        => true,
			'code'      => '',
			'value'     => $value,
			'price'     => (float) $processed['price'],
			'breaks'    => $breaks,
			'variables' => $result->trace,
			'kept'      => (bool) $processed['kept'],
		);
	}

	/**
	 * Apply minimum price, rounding and the fluctuation margin.
	 *
	 * Mirrors `PriceCalculator` steps 6–8 (and reuses its helpers), so formula
	 * prices behave exactly like currency prices.
	 *
	 * @param int          $post_id  Product id (min price / fluctuation meta).
	 * @param float        $value    Raw formula result.
	 * @param float        $previous Previous price.
	 * @param FormulaModel $formula  Formula (rounding override).
	 * @return array{ok:bool,code:string,price:float,kept:bool,steps:array<int,array<string,mixed>>}
	 */
	public function post_process( int $post_id, float $value, float $previous, FormulaModel $formula ): array {
		$steps = array();

		if ( $value <= 0 || ! is_finite( $value ) ) {
			return array(
				'ok'    => false,
				'code'  => 'non_positive_result',
				'price' => 0.0,
				'kept'  => false,
				'steps' => $steps,
			);
		}

		$min_price = ProductData::to_decimal( get_post_meta( $post_id, ProductData::META_MIN_PRICE, true ) );
		$value     = max( $value, $min_price );

		if ( $min_price > 0 && $value === $min_price ) {
			$steps[] = array(
				'key'   => 'min_price',
				'label' => __( 'Minimum price applied', 'ratebridge-woo' ),
				'value' => $value,
			);
		}

		// Rounding: the formula may override the global settings.
		$mode = '' !== $formula->rounding_mode ? $formula->rounding_mode : Settings::rounding_mode();
		$step = $formula->rounding_step > 0 ? $formula->rounding_step : Settings::rounding_step();

		$rounded = PriceCalculator::round_to_step( $value, $mode, $step );

		if ( 'none' !== $mode && $step > 0 && abs( $rounded - $value ) > 0.000001 ) {
			$steps[] = array(
				'key'   => 'rounded',
				'label' => __( 'Rounded', 'ratebridge-woo' ),
				'value' => $rounded,
			);
		}

		$decimals = Settings::store_decimals();
		$final    = round( $rounded, $decimals );
		$kept     = false;

		// Fluctuation margin (store unit), identical to the currency mode.
		$margin = ProductData::to_decimal( get_post_meta( $post_id, ProductData::META_FLUCTUATION, true ) );

		if ( $previous > 0 && $margin > 0 && abs( $final - $previous ) < $margin ) {
			$final = $previous;
			$kept  = true;

			$steps[] = array(
				'key'   => 'fluctuation',
				'label' => __( 'Previous price kept (fluctuation margin)', 'ratebridge-woo' ),
				'value' => $final,
			);
		}

		if ( $final <= 0 || ! is_finite( $final ) ) {
			return array(
				'ok'    => false,
				'code'  => 'non_positive_result',
				'price' => 0.0,
				'kept'  => $kept,
				'steps' => $steps,
			);
		}

		$steps[] = array(
			'key'   => 'final',
			'label' => __( 'Final price', 'ratebridge-woo' ),
			'value' => $final,
		);

		return array(
			'ok'    => true,
			'code'  => '',
			'price' => (float) $final,
			'kept'  => $kept,
			'steps' => $steps,
		);
	}

	/**
	 * Build a failure array.
	 *
	 * @param string                  $code  Error code.
	 * @param FormulaError|Result|null $error Source error.
	 * @return array<string, mixed>
	 */
	private function failure( string $code, FormulaError|Result|null $error = null ): array {
		$variables = array();

		if ( $error instanceof Result ) {
			$variables = $error->trace;
		}

		/* translators: %s: machine code, replaced by the WordPress layer. */
		return array(
			'ok'        => false,
			'code'      => '' !== $code ? $code : 'formula_error',
			'value'     => 0.0,
			'price'     => 0.0,
			'breaks'    => array(),
			'variables' => $variables,
			'kept'      => false,
			'detail'    => $error instanceof FormulaError ? $error->detail : '',
		);
	}
}
