<?php
/**
 * Rate update orchestration.
 *
 * @package RateBridge
 */

namespace RateBridge\Pricing;

use RateBridge\Core\Logger;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Providers\ProviderInterface;
use RateBridge\Providers\ProviderRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches rates for due currencies and stores them safely.
 */
final class RateUpdater {

	/**
	 * Constructor.
	 *
	 * @param CurrencyRepository|null $repository Currency repository.
	 * @param ProviderRegistry|null   $registry   Provider registry.
	 */
	public function __construct(
		private ?CurrencyRepository $repository = null,
		private ?ProviderRegistry $registry = null
	) {
		$this->repository = $repository ?? new CurrencyRepository();
		$this->registry   = $registry ?? ProviderRegistry::instance();
	}

	/**
	 * Update every enabled currency whose interval has elapsed.
	 *
	 * @param string $trigger 'cron' or 'manual'.
	 * @return array<string, mixed> Summary: array( 'updated' => int, 'failed' => int, 'skipped' => int ).
	 */
	public function update_due( string $trigger = 'cron' ): array {
		$due = array_filter(
			$this->repository->get_enabled(),
			static fn ( Currency $currency ): bool => $currency->is_due()
		);

		return $this->update_currencies( array_values( $due ), $trigger, false );
	}

	/**
	 * Force-update every enabled currency, regardless of interval.
	 *
	 * @param string $trigger 'cron' or 'manual'.
	 * @return array<string, mixed>
	 */
	public function update_all_enabled( string $trigger = 'manual' ): array {
		return $this->update_currencies( $this->repository->get_enabled(), $trigger, true );
	}

	/**
	 * Update a single currency by ID (used by "update now" in the row actions).
	 *
	 * @param int    $id      Currency ID.
	 * @param string $trigger 'cron' or 'manual'.
	 * @return array<string, mixed>
	 */
	public function update_one( int $id, string $trigger = 'manual' ): array {
		$currency = $this->repository->get( $id );

		if ( null === $currency ) {
			return array(
				'updated' => 0,
				'failed'  => 1,
				'skipped' => 0,
				'message' => __( 'Currency not found.', 'ratebridge-woo' ),
			);
		}

		return $this->update_currencies( array( $currency ), $trigger, true );
	}

	/**
	 * Core loop.
	 *
	 * @param Currency[] $currencies Currencies to update.
	 * @param string     $trigger    'cron' or 'manual'.
	 * @param bool       $force      Ignore last_updated / manual provider.
	 * @return array<string, mixed>
	 */
	private function update_currencies( array $currencies, string $trigger, bool $force ): array {
		$summary = array(
			'updated' => 0,
			'failed'  => 0,
			'skipped' => 0,
			'message' => '',
		);

		if ( empty( $currencies ) ) {
			$summary['message'] = __( 'No currencies were due for an update.', 'ratebridge-woo' );

			return $summary;
		}

		/** @var array<string, Currency[]> $grouped */
		$grouped = array();

		foreach ( $currencies as $currency ) {
			$grouped[ $currency->provider ][] = $currency;
		}

		$errors = array();

		foreach ( $grouped as $provider_key => $items ) {
			$provider = $this->registry->get( $provider_key );

			if ( ! $provider instanceof ProviderInterface ) {
				foreach ( $items as $currency ) {
					$message = sprintf(
						/* translators: %s: provider key */
						__( 'Unknown provider: %s', 'ratebridge-woo' ),
						$provider_key
					);
					$this->repository->set_error( $currency->id, $message );
					$errors[] = sprintf( '%s: %s', $currency->code, $message );
					++$summary['failed'];
				}
				continue;
			}

			$symbols = array();

			foreach ( $items as $currency ) {
				$symbol = '' !== $currency->provider_symbol ? $currency->provider_symbol : $currency->code;
				$symbols[] = $symbol;
			}

			$rates = $provider->fetch_rates( array_values( array_unique( $symbols ) ) );

			if ( is_wp_error( $rates ) ) {
				foreach ( $items as $currency ) {
					$this->repository->set_error( $currency->id, $rates->get_error_message() );
					$errors[] = sprintf( '%s: %s', $currency->code, $rates->get_error_message() );
					++$summary['failed'];
				}
				continue;
			}

			foreach ( $items as $currency ) {
				$symbol = '' !== $currency->provider_symbol ? $currency->provider_symbol : $currency->code;
				$rate   = isset( $rates[ $symbol ] ) ? (float) $rates[ $symbol ] : 0.0;

				if ( ! is_finite( $rate ) || $rate <= 0 ) {
					$message = sprintf(
						/* translators: %s: provider symbol */
						__( 'Provider returned no valid rate for "%s".', 'ratebridge-woo' ),
						$symbol
					);
					$this->repository->set_error( $currency->id, $message );
					$errors[] = sprintf( '%s: %s', $currency->code, $message );
					++$summary['failed'];
					continue;
				}

				if ( $this->repository->update_rate( $currency->id, $rate ) ) {
					++$summary['updated'];
				} else {
					++$summary['failed'];
					$errors[] = sprintf(
						/* translators: %s: currency code */
						__( '%s: could not persist the new rate.', 'ratebridge-woo' ),
						$currency->code
					);
				}
			}
		}

		if ( 0 === $summary['updated'] && 0 === $summary['failed'] ) {
			$summary['message'] = __( 'Nothing to update.', 'ratebridge-woo' );

			return $summary;
		}

		$codes = array_map(
			static fn ( Currency $currency ): string => $currency->code,
			$currencies
		);

		if ( $summary['failed'] > 0 ) {
			$summary['message'] = sprintf(
				/* translators: 1: updated count, 2: failed count, 3: error details */
				__( 'Updated %1$d, failed %2$d. %3$s', 'ratebridge-woo' ),
				$summary['updated'],
				$summary['failed'],
				implode( ' | ', array_slice( $errors, 0, 5 ) )
			);

			Logger::add(
				array(
					'status'     => $summary['updated'] > 0 ? 'success' : 'error',
					'message'    => $summary['message'],
					'currencies' => $codes,
					'trigger'    => $trigger,
				)
			);

			return $summary;
		}

		$summary['message'] = sprintf(
			/* translators: %d: updated currency count */
			_n( '%d currency updated.', '%d currencies updated.', $summary['updated'], 'ratebridge-woo' ),
			$summary['updated']
		);

		Logger::add(
			array(
				'status'     => 'success',
				'message'    => $summary['message'],
				'currencies' => $codes,
				'trigger'    => $trigger,
			)
		);

		return $summary;
	}

	/**
	 * Convert a WP_Error to a plain message (helper for UI).
	 *
	 * @param WP_Error $error Error object.
	 * @return string
	 */
	public static function error_message( WP_Error $error ): string {
		return $error->get_error_message();
	}
}
