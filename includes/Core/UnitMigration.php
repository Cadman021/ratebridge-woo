<?php
/**
 * Store-unit migration (Toman <-> Rial).
 *
 * Rates, fixed currency amounts, product fixed overrides, minimum prices and
 * fluctuation margins are all expressed in the store unit. Changing
 * "تومان / ریال" in the settings therefore invalidates every stored amount by
 * a factor of 10. This class converts them in one place, in batches, and then
 * asks for a repricing run.
 *
 * @package RateBridge
 */

namespace RateBridge\Core;

use RateBridge\Currency\CurrencyRepository;
use RateBridge\Pricing\RepriceJob;
use RateBridge\Product\ProductData;

defined( 'ABSPATH' ) || exit;

/**
 * Converts every store-unit amount when the store unit really changes.
 */
final class UnitMigration {

	/**
	 * Products converted per batch.
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Option holding a migration state between batches.
	 */
	public const STATE_OPTION = 'rbw_unit_migration';

	/**
	 * Multiply helper accessible from tests: amount in Toman -> amount in unit.
	 *
	 * @param float  $amount Amount in the current unit.
	 * @param string $from   'toman' or 'rial'.
	 * @param string $to     'toman' or 'rial'.
	 * @return float Converted amount (unchanged when from === to).
	 */
	public static function convert( float $amount, string $from, string $to ): float {
		if ( $from === $to || ! is_finite( $amount ) ) {
			return $amount;
		}

		if ( 'toman' === $from && 'rial' === $to ) {
			return $amount * 10.0;
		}

		if ( 'rial' === $from && 'toman' === $to ) {
			return $amount / 10.0;
		}

		return $amount;
	}

	/**
	 * Factor between two units (10, 0.1 or 1).
	 *
	 * @param string $from Source unit.
	 * @param string $to   Target unit.
	 * @return float
	 */
	public static function factor( string $from, string $to ): float {
		if ( $from === $to ) {
			return 1.0;
		}

		return 'toman' === $from ? 10.0 : 0.1;
	}

	/**
	 * Migrate everything. Does nothing when the unit did not really change.
	 *
	 * @param string $old_unit Unit before the change.
	 * @param string $new_unit Unit after the change.
	 * @return array<string, mixed> {
	 *     @type bool $changed Whether anything was converted.
	 *     @type int  $currencies Number of currency rows touched.
	 *     @type int  $products   Number of products touched.
	 *     @type int  $posts      Number of meta rows updated.
	 *     @type array $codes     Currency codes needing a reprice.
	 * }
	 */
	public function migrate( string $old_unit, string $new_unit ): array {
		$old = in_array( $old_unit, array( 'toman', 'rial' ), true ) ? $old_unit : 'toman';
		$new = in_array( $new_unit, array( 'toman', 'rial' ), true ) ? $new_unit : 'toman';

		$result = array(
			'changed'    => false,
			'currencies' => 0,
			'products'   => 0,
			'posts'      => 0,
			'codes'      => array(),
			'step'       => 1.0,
			'formulas'   => 0,
		);

		if ( $old === $new ) {
			return $result;
		}

		$factor = self::factor( $old, $new );

		// 1. Currency rates + fixed profit/commission/extra amounts.
		$repository           = new CurrencyRepository();
		$result['currencies'] = $repository->scale_amounts( $factor );

		foreach ( $repository->get_all() as $currency ) {
			$result['codes'][] = $currency->code;
		}

		// 2. Rounding step (a store-unit amount too).
		$step = (float) Settings::get( 'rounding_step', 1000 );
		$new_step = self::convert( $step, $old, $new );
		$new_step = max( 1.0, round( $new_step ) );

		Settings::update( array( 'rounding_step' => $new_step ) );

		$result['step'] = $new_step;

		// 3. Formula custom variables marked as money (defaults + overrides).
		$result['formulas'] = $this->migrate_formulas( $factor );

		// 4. Product-level store amounts (batched over all `_rbw_*` products).
		$counts              = $this->migrate_products( $factor );
		$result['products']  = $counts['products'];
		$result['posts']     = $counts['posts'];
		$result['changed']   = true;

		Logger::add(
			array(
				'status'     => 'success',
				'message'    => sprintf(
					/* translators: 1: old unit, 2: new unit, 3: currency rows, 4: products, 5: rounding step, 6: formula count */
					__( 'Store unit changed from %1$s to %2$s. Converted %3$d currencies, %4$d products and %6$d formulas; rounding step is now %5$s.', 'ratebridge-woo' ),
					$old,
					$new,
					$result['currencies'],
					$result['products'],
					(string) $new_step,
					(int) $result['formulas']
				),
				'currencies' => $result['codes'],
				'trigger'    => 'unit',
			)
		);

		return $result;
	}

	/**
	 * Convert money-type custom variable defaults in every formula.
	 *
	 * Only variables flagged `is_money` are amounts in the store unit; plain
	 * numbers inside an expression (literals) are NOT converted — that
	 * limitation is documented in the README and in the confirmation text.
	 *
	 * @param float $factor Multiplier.
	 * @return int Number of formulas whose variables changed.
	 */
	private function migrate_formulas( float $factor ): int {
		$repository = new \RateBridge\Formula\FormulaRepository();
		$changed    = 0;

		foreach ( $repository->get_all() as $formula ) {
			$variables = $formula->variables;
			$touched   = false;

			foreach ( $variables as $index => $variable ) {
				if ( \RateBridge\Formula\FormulaModel::TYPE_CUSTOM !== ( $variable['type'] ?? '' ) ) {
					continue;
				}

				if ( empty( $variable['is_money'] ) ) {
					continue;
				}

				foreach ( array( 'default', 'min', 'max' ) as $key ) {
					if ( isset( $variable[ $key ] ) && is_numeric( $variable[ $key ] ) ) {
						$variables[ $index ][ $key ] = round( (float) $variable[ $key ] * $factor, 4 );
						$touched = true;
					}
				}
			}

			if ( $touched && $repository->update( $formula->id, array( 'variables' => $variables ) ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Convert every store-unit product meta amount, in batches.
	 *
	 * @param float $factor Multiplier.
	 * @return array{products:int,posts:int}
	 */
	private function migrate_products( float $factor ): array {
		global $wpdb;

		$product_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				ProductData::META_ENABLED
			)
		);

		$product_ids = array_values( array_unique( array_map( 'intval', is_array( $product_ids ) ? $product_ids : array() ) ) );

		if ( empty( $product_ids ) ) {
			return array(
				'products' => 0,
				'posts'    => 0,
			);
		}

		$posts    = 0;
		$products = 0;

		foreach ( array_chunk( $product_ids, self::BATCH_SIZE ) as $chunk ) {
			foreach ( $chunk as $post_id ) {
				++$products;
				$posts += $this->convert_post( $post_id, $factor );
			}
		}

		return array(
			'products' => $products,
			'posts'    => $posts,
		);
	}

	/**
	 * Convert the store-unit meta of one post.
	 *
	 * Only *fixed* override amounts are converted: a percent amount is
	 * unit-less and must stay as it is.
	 *
	 * @param int   $post_id Post id.
	 * @param float $factor  Multiplier.
	 * @return int Number of meta rows updated.
	 */
	private function convert_post( int $post_id, float $factor ): int {
		$updated = 0;

		// Always-converted keys (store-unit amounts).
		foreach ( array( ProductData::META_MIN_PRICE, ProductData::META_FLUCTUATION, ProductData::META_LAST_PRICE ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( '' === $value || ! is_numeric( $value ) ) {
				continue;
			}

			$new_value = round( (float) $value * $factor, 4 );

			update_post_meta( $post_id, $key, wc_format_decimal( $new_value, 4 ) );
			++$updated;
		}

		// Fixed overrides only (percent values are unit-less).
		$pairs = array(
			array( ProductData::META_PROFIT_TYPE, ProductData::META_PROFIT_VALUE ),
			array( ProductData::META_COMMISSION_TYPE, ProductData::META_COMMISSION_VALUE ),
		);

		foreach ( $pairs as $pair ) {
			$type  = (string) get_post_meta( $post_id, $pair[0], true );
			$value = get_post_meta( $post_id, $pair[1], true );

			if ( 'fixed' !== strtolower( $type ) || '' === $value || ! is_numeric( $value ) ) {
				continue;
			}

			update_post_meta( $post_id, $pair[1], wc_format_decimal( round( (float) $value * $factor, 4 ), 4 ) );
			++$updated;
		}

		/*
		 * Per-product formula overrides: only the variables flagged `is_money`
		 * are store amounts. The formula definition is the only place that knows
		 * which names those are, so the products are walked afterwards by
		 * migrate_formula_overrides().
		 */

		// Extra amount is always a fixed store amount.
		$extra = get_post_meta( $post_id, ProductData::META_EXTRA_AMOUNT, true );

		if ( '' !== $extra && is_numeric( $extra ) ) {
			update_post_meta( $post_id, ProductData::META_EXTRA_AMOUNT, wc_format_decimal( round( (float) $extra * $factor, 4 ), 4 ) );
			++$updated;
		}

		// Clear the fluctuation baseline: it is in the old unit.
		delete_post_meta( $post_id, ProductData::META_LAST_PRICE );

		$updated += $this->convert_formula_overrides( $post_id, $factor );

		return $updated;
	}

	/**
	 * Convert a product's formula-variable overrides that are money amounts.
	 *
	 * @param int   $post_id Product id.
	 * @param float $factor  Multiplier.
	 * @return int Number of overrides changed.
	 */
	private function convert_formula_overrides( int $post_id, float $factor ): int {
		$overrides = ProductData::formula_overrides( $post_id );

		if ( empty( $overrides ) ) {
			return 0;
		}

		$formula_id = ProductData::formula_id( $post_id );

		if ( 0 === $formula_id ) {
			$parent = (int) wp_get_post_parent_id( $post_id );
			$formula_id = $parent > 0 ? ProductData::formula_id( $parent ) : 0;
		}

		if ( 0 === $formula_id ) {
			return 0;
		}

		$formula = ( new \RateBridge\Formula\FormulaRepository() )->get( $formula_id );

		if ( null === $formula ) {
			return 0;
		}

		$money_names = array();
		$changed     = 0;

		foreach ( $formula->variables_of_type( \RateBridge\Formula\FormulaModel::TYPE_CUSTOM ) as $variable ) {
			if ( ! empty( $variable['is_money'] ) ) {
				$money_names[] = (string) $variable['name'];
			}
		}

		foreach ( $money_names as $name ) {
			if ( isset( $overrides[ $name ] ) ) {
				$overrides[ $name ] = round( $overrides[ $name ] * $factor, 4 );
				++$changed;
			}
		}

		if ( $changed > 0 ) {
			update_post_meta( $post_id, ProductData::META_FORMULA_VARS, wp_json_encode( $overrides ) );
		}

		return $changed;
	}

	/**
	 * Queue a repricing run for every currency after a migration.
	 *
	 * @param string[] $codes Currency codes.
	 * @return void
	 */
	public static function queue_reprice( array $codes ): void {
		$job = new RepriceJob();

		foreach ( array_unique( array_map( 'strval', $codes ) ) as $code ) {
			if ( '' !== $code ) {
				$job->enqueue( $code );
			}
		}
	}
}
