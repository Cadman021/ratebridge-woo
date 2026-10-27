<?php
/**
 * Asynchronous repricing job (Action Scheduler, with a cron fallback).
 *
 * @package RateBridge
 */

namespace RateBridge\Pricing;

use RateBridge\Core\Logger;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues and runs batched repricing after a rate change.
 */
final class RepriceJob {

	/**
	 * Action Scheduler hook name.
	 */
	public const HOOK = 'rbw_reprice_currency';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'ratebridge-woo';

	/**
	 * Products handled per batch.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Cron fallback hook (used when Action Scheduler is unavailable).
	 */
	public const CRON_HOOK = 'rbw_reprice_cron';

	/**
	 * Option holding the cron-fallback queue: code => array( 'page' => int, 'summary' => array ).
	 */
	public const QUEUE_OPTION = 'rbw_reprice_queue';

	/**
	 * Register the Action Scheduler callback and the cron fallback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 4 );
		add_action( self::CRON_HOOK, array( $this, 'run_cron_queue' ) );

		/*
		 * Formula dependencies: a formula that references a currency must reprice
		 * its products when that rate changes, and every product using a formula
		 * must be repriced when the formula itself is saved.
		 */
		add_action( 'rbw_formula_saved', array( $this, 'on_formula_saved' ), 10, 1 );
	}

	/**
	 * Enqueue ONE async job for a currency, de-duplicated.
	 *
	 * @param string $currency_code Currency code.
	 * @return bool Whether a job was enqueued (false = one was already pending).
	 */
	public function enqueue( string $currency_code ): bool {
		$code = strtoupper( trim( $currency_code ) );

		if ( '' === $code ) {
			return false;
		}

		if ( $this->has_pending( $code ) ) {
			return false;
		}

		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			as_enqueue_async_action(
				self::HOOK,
				array(
					'code'   => $code,
					'page'   => 1,
					'summary' => array(),
				),
				self::GROUP
			);

			return true;
		}

		// Fallback: a single cron event carrying the queue in an option.
		$this->queue_cron( $code );

		return true;
	}

	/**
	 * Whether an async job for this currency is already scheduled or running.
	 *
	 * @param string $code Currency code.
	 * @return bool
	 */
	public function has_pending( string $code ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			$pending = as_has_scheduled_action(
				self::HOOK,
				array(
					'code'   => strtoupper( $code ),
					'page'   => 1,
					'summary' => array(),
				),
				self::GROUP
			);

			if ( $pending ) {
				return true;
			}
		}

		// Cron fallback queue.
		$queue = get_option( self::QUEUE_OPTION, array() );

		return is_array( $queue ) && isset( $queue[ strtoupper( $code ) ] );
	}

	/**
	 * Action Scheduler callback: process one batch and reschedule itself.
	 *
	 * @param string              $code    Currency code.
	 * @param int                 $page    1-based page number.
	 * @param array<string, int>  $summary Counters carried across batches.
	 * @param int                 $round   Safety counter (stops runaway loops).
	 * @return void
	 */
	public function run( string $code = '', int $page = 1, array $summary = array(), int $round = 0 ): void {
		$code = strtoupper( trim( $code ) );

		if ( '' === $code ) {
			return;
		}

		if ( $round > 10000 ) {
			Logger::add(
				array(
					'status'     => 'error',
					'message'    => sprintf(
						/* translators: %s: currency code */
						__( '%s: repricing stopped after too many batches.', 'ratebridge-woo' ),
						$code
					),
					'currencies' => array( $code ),
					'trigger'    => 'async',
				)
			);

			return;
		}

		$summary = wp_parse_args(
			$summary,
			array(
				'repriced'  => 0,
				'skipped'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			)
		);

		$batch = $this->process_page_for( $code, $page );

		foreach ( $batch['summary'] as $key => $value ) {
			$summary[ $key ] = (int) ( $summary[ $key ] ?? 0 ) + (int) $value;
		}

		if ( $batch['done'] ) {
			Repricer::log_summary( $code, $summary, 'async' );

			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK,
				array(
					'code'    => $code,
					'page'    => $page + 1,
					'summary' => $summary,
					'round'   => $round + 1,
				),
				self::GROUP
			);

			return;
		}

		// Fallback: keep going on the next cron tick.
		$queue = get_option( self::QUEUE_OPTION, array() );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$queue[ $code ] = array(
			'page'    => $page + 1,
			'summary' => $summary,
		);

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Enqueue repricing for the products using a formula (deduplicated).
	 *
	 * @param int    $formula_id Formula id.
	 * @param string $reason     created|updated (for the log).
	 * @return bool Whether a job was enqueued.
	 */
	public function enqueue_formula( int $formula_id, string $reason = 'updated' ): bool {
		if ( $formula_id <= 0 ) {
			return false;
		}

		$key = 'formula:' . $formula_id;

		if ( $this->has_pending( $key ) ) {
			return false;
		}

		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			as_enqueue_async_action(
				self::HOOK,
				array(
					'code'    => $key,
					'page'    => 1,
					'summary' => array(),
					'round'   => 0,
				),
				self::GROUP
			);

			return true;
		}

		$this->queue_cron( $key );

		unset( $reason );

		return true;
	}

	/**
	 * React to a saved/created formula.
	 *
	 * @param int $formula_id Formula id.
	 * @return void
	 */
	public function on_formula_saved( $formula_id ): void {
		$this->enqueue_formula( (int) $formula_id );
	}

	/**
	 * Enqueue repricing for the formulas that reference a currency.
	 *
	 * Called by the rate-change listener so formula products follow rates they
	 * actually use.
	 *
	 * @param string $currency_code Currency code.
	 * @return int Number of formula jobs enqueued.
	 */
	public function enqueue_for_currency_formulas( string $currency_code ): int {
		$code = strtoupper( trim( $currency_code ) );

		if ( '' === $code ) {
			return 0;
		}

		$repository = new \RateBridge\Formula\FormulaRepository();
		$enqueued   = 0;

		foreach ( $repository->find_using_currency( $code ) as $formula ) {
			if ( $this->enqueue_formula( $formula->id ) ) {
				++$enqueued;
			}
		}

		return $enqueued;
	}

	/**
	 * Process one batch for a job key (`USD` or `formula:12`).
	 *
	 * @param string $key  Currency code or `formula:<id>`.
	 * @param int    $page Page number.
	 * @return array{summary:array<string,int>,done:bool,counts:int}
	 */
	public function process_page_for( string $key, int $page ): array {
		if ( str_starts_with( $key, 'formula:' ) ) {
			return $this->process_formula_page( (int) substr( $key, 8 ), $page );
		}

		return $this->process_page( $key, $page );
	}

	/**
	 * Process one batch of products using a formula.
	 *
	 * @param int $formula_id Formula id.
	 * @param int $page       Page number (1-based over an id slice).
	 * @return array{summary:array<string,int>,done:bool,counts:int}
	 */
	public function process_formula_page( int $formula_id, int $page ): array {
		$repository = new \RateBridge\Formula\FormulaRepository();
		$empty      = array(
			'repriced'  => 0,
			'skipped'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);

		$ids = $repository->reprice_target_ids( $formula_id );

		if ( empty( $ids ) ) {
			return array(
				'summary' => $empty,
				'done'    => true,
				'counts'  => 0,
			);
		}

		$slice = array_slice( $ids, ( max( 1, $page ) - 1 ) * self::BATCH_SIZE, self::BATCH_SIZE );
		$summary = $empty;
		$repricer = new Repricer();

		foreach ( $slice as $product_id ) {
			$product = wc_get_product( (int) $product_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$result = $repricer->reprice_product( $product );

			$key = match ( $result['status'] ) {
				'repriced'  => 'repriced',
				'failed'    => 'failed',
				'unchanged' => 'unchanged',
				default     => 'skipped',
			};

			++$summary[ $key ];
		}

		return array(
			'summary' => $summary,
			'done'    => count( $slice ) < self::BATCH_SIZE,
			'counts'  => count( $slice ),
		);
	}

	/**
	 * Process a single batch of managed products for one currency.
	 *
	 * Exposed so a WP-CLI command or a test can drive it synchronously.
	 *
	 * @param string $code Currency code.
	 * @param int    $page Page number.
	 * @return array{summary:array<string,int>,done:bool,counts:int}
	 */
	public function process_page( string $code, int $page ): array {
		$repricer = new Repricer();
		$empty    = array(
			'repriced'  => 0,
			'skipped'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);

		$products = wc_get_products( Repricer::managed_query_args( $code, $page, self::BATCH_SIZE ) );

		if ( empty( $products ) ) {
			return array(
				'summary' => $empty,
				'done'    => true,
				'counts'  => 0,
			);
		}

		$summary = $empty;

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// No currency passed on purpose: a variable product's variations may
			// override it, so the Repricer resolves it per product (cheaply cached
			// by the options/post caches).
			$result = $repricer->reprice_product( $product );

			$key = match ( $result['status'] ) {
				'repriced'  => 'repriced',
				'failed'    => 'failed',
				'unchanged' => 'unchanged',
				default     => 'skipped',
			};

			++$summary[ $key ];
		}

		return array(
			'summary' => $summary,
			'done'    => count( $products ) < self::BATCH_SIZE,
			'counts'  => count( $products ),
		);
	}

	/**
	 * Cron fallback: drain the queue one page per tick.
	 *
	 * @return void
	 */
	public function run_cron_queue(): void {
		$queue = get_option( self::QUEUE_OPTION, array() );

		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		foreach ( $queue as $code => $state ) {
			$code  = strtoupper( (string) $code );
			$page  = max( 1, (int) ( $state['page'] ?? 1 ) );
			$batch = $this->process_page_for( $code, $page );

			$summary = wp_parse_args(
				is_array( $state['summary'] ?? null ) ? $state['summary'] : array(),
				array(
					'repriced'  => 0,
					'skipped'   => 0,
					'unchanged' => 0,
					'failed'    => 0,
				)
			);

			foreach ( $batch['summary'] as $key => $value ) {
				$summary[ $key ] = (int) ( $summary[ $key ] ?? 0 ) + (int) $value;
			}

			if ( $batch['done'] ) {
				unset( $queue[ $code ] );
				Repricer::log_summary( $code, $summary, 'async' );
				continue;
			}

			$queue[ $code ] = array(
				'page'    => $page + 1,
				'summary' => $summary,
			);
		}

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Ensure the cron fallback event exists while something is queued.
	 *
	 * @param string $code Currency code.
	 * @return void
	 */
	private function queue_cron( string $code ): void {
		$queue = get_option( self::QUEUE_OPTION, array() );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$queue[ $code ] = array(
			'page'    => 1,
			'summary' => array(
				'repriced'  => 0,
				'skipped'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			),
		);

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}
}
