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

		// 3. Product-level store amounts (batched over all `_rbw_*` products).
		$counts              = $this->migrate_products( $factor );
		$result['products']  = $counts['products'];
		$result['posts']     = $counts['posts'];
		$result['changed']   = true;

		Logger::add(
			array(
				'status'     => 'success',
				'message'    => sprintf(
					/* translators: 1: old unit, 2: new unit, 3: currency rows, 4: products */
					__( 'Store unit changed from %1$s to %2$s. Converted %3$d currencies and %4$d products; rounding step is now %5$s.', 'ratebridge-woo' ),
					$old,
					$new,
					$result['currencies'],
					$result['products'],
					(string) $new_step
				),
				'currencies' => $result['codes'],
				'trigger'    => 'unit',
			)
		);

		return $result;
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

		// Extra amount is always a fixed store amount.
		$extra = get_post_meta( $post_id, ProductData::META_EXTRA_AMOUNT, true );

		if ( '' !== $extra && is_numeric( $extra ) ) {
			update_post_meta( $post_id, ProductData::META_EXTRA_AMOUNT, wc_format_decimal( round( (float) $extra * $factor, 4 ), 4 ) );
			++$updated;
		}

		// Clear the fluctuation baseline: it is in the old unit.
		delete_post_meta( $post_id, ProductData::META_LAST_PRICE );

		return $updated;
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
