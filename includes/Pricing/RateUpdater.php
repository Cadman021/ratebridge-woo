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
use RateBridge\Providers\AbstractProvider;
use RateBridge\Providers\ProviderInterface;
use RateBridge\Providers\ProviderRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches rates for due currencies and stores them safely.
 */
final class RateUpdater {

	/**
	 * Minimum absolute difference that counts as a real rate change.
	 */
	public const RATE_EPSILON = 0.0000001;

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
	 * Currencies whose configured interval is shorter than the provider's
	 * effective minimum are skipped (rather than fetched every 5 minutes).
	 *
	 * @param string $trigger 'cron' or 'manual'.
	 * @return array<string, mixed> Summary: array( 'updated' => int, 'failed' => int, 'skipped' => int ).
	 */
	public function update_due( string $trigger = 'cron' ): array {
		$due = array_filter(
			$this->repository->get_enabled(),
			fn ( Currency $currency ): bool => $this->is_due_for_provider( $currency )
		);

		return $this->update_currencies( array_values( $due ), $trigger, false );
	}

	/**
	 * Whether a currency should be refreshed now, accounting for the provider's
	 * effective minimum interval.
	 *
	 * @param Currency $currency Currency row.
	 * @return bool
	 */
	private function is_due_for_provider( Currency $currency ): bool {
		if ( ! $currency->is_due() ) {
			return false;
		}

		$provider = $this->registry->get( $currency->provider );

		if ( ! $provider instanceof AbstractProvider ) {
			return true;
		}

		$effective = $provider->get_effective_min_interval_minutes();

		if ( $effective <= 0 || $currency->update_interval_minutes >= $effective ) {
			return true;
		}

		// The row asks for more frequent updates than the provider allows: only
		// fetch once the provider's own minimum has actually elapsed.
		$last = $currency->last_updated;

		if ( null === $last ) {
			return true;
		}

		$timestamp = strtotime( $last . ' UTC' );

		if ( false === $timestamp ) {
			return true;
		}

		return ( time() - $timestamp ) >= $effective * MINUTE_IN_SECONDS;
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
				/*
				 * The provider message already carries the ROOT cause first (an auth
				 * failure or the last real provider error), followed by any backoff
				 * note. Prefixing it with the provider label makes a currency row
				 * self-explanatory: «NetArz: توکن/کلید نامعتبر… (HTTP 401)».
				 */
				foreach ( $items as $currency ) {
					$this->repository->set_error( $currency->id, $this->compose_provider_error( $provider, $rates ) );
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

					/*
					 * Product prices follow the rate. The action only fires when
					 * the stored value really changed (a provider that keeps
					 * returning the same number must not enqueue jobs forever).
					 */
					if ( abs( $rate - $currency->rate ) > self::RATE_EPSILON ) {
						/**
						 * A currency rate changed.
						 *
						 * @param string $code     Currency code (e.g. USD).
						 * @param float  $old_rate Previous rate (0 when never set).
						 * @param float  $new_rate New rate, in the store unit.
						 * @param int    $currency_id Currency row id.
						 */
						do_action( 'rbw_rate_updated', $currency->code, (float) $currency->rate, (float) $rate, (int) $currency->id );
					}
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
	 * Attempt one immediate update after a currency lost its rate.
	 *
	 * Fired by the repository (`rbw_currency_rate_pending`) when the provider or
	 * provider symbol changed. It goes through the normal update path, so
	 * `RequestBudget` still applies: if the source is rate-limited the currency
	 * stays in the «waiting for the first update» state and the next cron run
	 * picks it up. Nothing is fetched for manual currencies.
	 *
	 * @param int    $currency_id Currency id.
	 * @param string $code        Currency code (unused, kept for the action).
	 * @return void
	 */
	public function on_rate_pending( $currency_id, $code = '' ): void {
		unset( $code );

		$currency = $this->repository->get( (int) $currency_id );

		if ( null === $currency ) {
			return;
		}

		// Manual rates are typed by the admin; nothing to fetch.
		if ( 'manual' === $currency->provider ) {
			return;
		}

		// Only react to the pending state, so a plain save does not cost quota.
		if ( ! $currency->is_pending_first_update() ) {
			return;
		}

		$this->update_one( $currency->id, 'manual' );
	}

	/**
	 * Provider label + root-cause-first message for a currency row.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param WP_Error          $error    Error.
	 * @return string
	 */
	private function compose_provider_error( ProviderInterface $provider, WP_Error $error ): string {
		$message = trim( $error->get_error_message() );

		if ( '' === $message ) {
			return $provider->get_label();
		}

		// The provider already prefixes its own label for auth errors.
		if ( str_starts_with( $message, $provider->get_label() ) ) {
			return $message;
		}

		return $provider->get_label() . ': ' . $message;
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
