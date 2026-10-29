<?php
/**
 * Overview tab model.
 *
 * A read-only aggregate of data the plugin already stores — no new tables, no
 * new options: currencies, formulas, providers/budgets, the update log and the
 * existing managed-product meta.
 *
 * @package RateBridge
 */

namespace RateBridge\Admin;

use RateBridge\Core\Logger;
use RateBridge\Core\Settings;
use RateBridge\Cron\Scheduler;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Pricing\Repricer;
use RateBridge\Product\ProductData;
use RateBridge\Providers\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the view model for the «نمای کلی» tab.
 */
final class Overview {

	/**
	 * Currency repository.
	 *
	 * @var CurrencyRepository
	 */
	private CurrencyRepository $currencies;

	/**
	 * Formula repository.
	 *
	 * @var FormulaRepository
	 */
	private FormulaRepository $formulas;

	/**
	 * Repricer (for the stale-rate rule).
	 *
	 * @var Repricer
	 */
	private Repricer $repricer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->currencies = new CurrencyRepository();
		$this->formulas   = new FormulaRepository();
		$this->repricer   = new Repricer( $this->currencies );
	}

	/**
	 * Everything the Overview tab needs.
	 *
	 * @return array<string, mixed>
	 */
	public function model(): array {
		$all       = $this->currencies->get_all();
		$enabled   = array_values( array_filter( $all, static fn ( Currency $c ): bool => $c->is_enabled ) );
		$last_ok   = $this->last_successful_update( $enabled );
		$attention = $this->needs_attention( $all );

		return array(
			'kpis'      => array(
				'active_currencies' => count( $enabled ),
				'total_currencies'  => count( $all ),
				'last_update'       => $last_ok,
				'products'          => $this->managed_product_count(),
				'next_run'          => Scheduler::next_run(),
				'formulas'          => count( array_filter( $this->formulas->get_all( true ) ) ),
			),
			'providers' => $this->providers(),
			'attention' => $attention,
			'activity'  => array_slice( Logger::all(), 0, 10 ),
			'health'    => $this->health( $enabled, $attention ),
			'empty'     => empty( $all ),
		);
	}

	/**
	 * Health pill for the page header.
	 *
	 * @param Currency[]                 $enabled   Enabled currencies.
	 * @param array<int, array<string,mixed>> $attention Attention rows.
	 * @return array{level:string,label:string,details:string}
	 */
	public function health( array $enabled, array $attention ): array {
		if ( empty( $enabled ) ) {
			return array(
				'level'   => 'warn',
				'label'   => __( 'No active currency', 'ratebridge-woo' ),
				'details' => __( 'Enable at least one currency so prices can be calculated.', 'ratebridge-woo' ),
			);
		}

		$errors = array_filter( $attention, static fn ( array $row ): bool => 'error' === $row['level'] );

		if ( ! empty( $errors ) ) {
			return array(
				'level'   => 'error',
				'label'   => sprintf(
					/* translators: %d: number of issues */
					_n( '%d issue needs attention', '%d issues need attention', count( $errors ), 'ratebridge-woo' ),
					count( $errors )
				),
				'details' => implode( ' · ', array_slice( array_column( $errors, 'title' ), 0, 3 ) ),
			);
		}

		if ( ! empty( $attention ) ) {
			return array(
				'level'   => 'warn',
				'label'   => sprintf(
					/* translators: %d: number of warnings */
					_n( '%d warning', '%d warnings', count( $attention ), 'ratebridge-woo' ),
					count( $attention )
				),
				'details' => implode( ' · ', array_slice( array_column( $attention, 'title' ), 0, 3 ) ),
			);
		}

		return array(
			'level'   => 'ok',
			'label'   => __( 'All good', 'ratebridge-woo' ),
			'details' => __( 'Every active currency has a fresh rate.', 'ratebridge-woo' ),
		);
	}

	/**
	 * Timestamp + label of the most recent successful rate update.
	 *
	 * @param Currency[] $enabled Enabled currencies.
	 * @return array{timestamp:int,label:string}
	 */
	private function last_successful_update( array $enabled ): array {
		$latest = 0;

		foreach ( $enabled as $currency ) {
			$timestamp = \RateBridge\Frontend\Frontend::currency_timestamp( $currency );

			if ( $timestamp > $latest ) {
				$latest = $timestamp;
			}
		}

		return array(
			'timestamp' => $latest,
			'label'     => $latest > 0
				? \RateBridge\Frontend\Frontend::time_ago( $latest )
				: __( 'never', 'ratebridge-woo' ),
		);
	}

	/**
	 * Number of products the plugin prices (managed parents, not variations).
	 *
	 * @return int
	 */
	private function managed_product_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE pm.meta_key = %s AND pm.meta_value = %s
				   AND p.post_type = 'product'
				   AND p.post_status <> 'trash'",
				ProductData::META_ENABLED,
				'yes'
			)
		);
	}

	/**
	 * Provider status cards.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function providers(): array {
		$registry = ProviderRegistry::instance();
		$service  = new ProviderSettings( $registry );
		$cards    = array();

		foreach ( $registry->with_settings() as $provider ) {
			$card   = $service->card_model( $provider );
			$budget = $card['budget'];

			$status = 'connected';
			$label  = __( 'Connected', 'ratebridge-woo' );

			if ( ! empty( $budget['auth_failed'] ) ) {
				// Broken credentials beat every quota message.
				$status = 'needs_key';
				$label  = __( 'Needs a key / auth error', 'ratebridge-woo' );
			} elseif ( ! $card['uses_network'] ) {
				$status = 'local';
				$label  = __( 'Local', 'ratebridge-woo' );
			} elseif ( ! $card['configured'] ) {
				$status = 'needs_key';
				$label  = __( 'Needs a key', 'ratebridge-woo' );
			} elseif ( ! empty( $budget['daily_limit'] ) && (int) $budget['used_today'] >= (int) $budget['daily_limit'] ) {
				$status = 'exhausted';
				$label  = __( 'Quota exhausted', 'ratebridge-woo' );
			} elseif ( $budget['limit'] > 0 && $budget['used'] >= $budget['limit'] ) {
				$status = 'exhausted';
				$label  = __( 'Quota exhausted', 'ratebridge-woo' );
			} elseif ( '' !== (string) $budget['last_error'] ) {
				$status = 'error';
				$label  = __( 'Error', 'ratebridge-woo' );
			}

			$cards[] = array(
				'key'         => (string) $card['key'],
				'label'       => (string) $card['label'],
				'status'      => $status,
				'status_text' => $label,
				'configured'  => (bool) $card['configured'],
				'uses_network' => (bool) $card['uses_network'],
				'used'        => (int) $budget['used'],
				'limit'       => (int) $budget['limit'],
				'percent'     => $budget['limit'] > 0 ? min( 100, (int) round( $budget['used'] / $budget['limit'] * 100 ) ) : 0,
				'next_allowed' => (string) $budget['next_allowed_fmt'],
				'last_error'  => '' !== (string) $budget['auth_message'] && ! empty( $budget['auth_failed'] )
					? (string) $budget['auth_message']
					: (string) $budget['last_error'],
				'auth_failed' => ! empty( $budget['auth_failed'] ),
			);
		}

		return $cards;
	}

	/**
	 * «نیازمند توجه» rows built from existing states.
	 *
	 * @param Currency[] $all All currencies.
	 * @return array<int, array<string, mixed>>
	 */
	private function needs_attention( array $all ): array {
		$rows    = array();
		$max_age = Settings::max_rate_age_hours();

		foreach ( $all as $currency ) {
			if ( ! $currency->is_enabled ) {
				continue;
			}

			if ( ! $currency->has_rate() ) {
				$rows[] = array(
					'level'  => 'warn',
					'title'  => sprintf(
						/* translators: %s: currency code */
						__( '%s is waiting for its first rate', 'ratebridge-woo' ),
						$currency->code
					),
					'text'   => $currency->error_message(),
					'tab'    => 'currencies',
					'action' => __( 'Open currencies', 'ratebridge-woo' ),
				);
				continue;
			}

			if ( '' !== $currency->error_message() ) {
				$rows[] = array(
					'level'  => 'error',
					'title'  => sprintf(
						/* translators: %s: currency code */
						__( '%s reported an error', 'ratebridge-woo' ),
						$currency->code
					),
					'text'   => $currency->error_message(),
					'tab'    => 'currencies',
					'action' => __( 'Review the currency', 'ratebridge-woo' ),
				);
			}

			if ( $max_age > 0 && $this->repricer->is_stale( $currency ) ) {
				$rows[] = array(
					'level'  => 'warn',
					'title'  => sprintf(
						/* translators: %s: currency code */
						__( '%s has a stale rate', 'ratebridge-woo' ),
						$currency->code
					),
					'text'   => sprintf(
						/* translators: 1: hours, 2: last update */
						__( 'The rate is older than %1$d hours (last update: %2$s). Products keep their price until a fresh rate arrives.', 'ratebridge-woo' ),
						$max_age,
						SettingsPage::format_datetime( $currency->last_updated )
					),
					'tab'    => 'currencies',
					'action' => __( 'Update now', 'ratebridge-woo' ),
				);
			}
		}

		// Providers whose credentials were rejected (401/403) need the admin.
		$registry = ProviderRegistry::instance();
		$service  = new ProviderSettings( $registry );

		foreach ( $registry->with_settings() as $provider ) {
			$card   = $service->card_model( $provider );
			$budget = $card['budget'];

			if ( empty( $budget['auth_failed'] ) ) {
				continue;
			}

			$rows[] = array(
				'level'  => 'error',
				'title'  => sprintf(
					/* translators: %s: provider label */
					__( '%s rejected the credentials', 'ratebridge-woo' ),
					(string) $card['label']
				),
				'text'   => (string) $budget['auth_message'],
				'tab'    => 'sources',
				'action' => __( 'Open price sources', 'ratebridge-woo' ),
			);
		}

		// Formulas that no longer compile would silently stop repricing.
		foreach ( $this->formulas->get_all( true ) as $formula ) {
			if ( ! $formula instanceof FormulaModel ) {
				continue;
			}

			$compiled = $formula->compile();

			if ( $compiled instanceof \RateBridge\Formula\FormulaError ) {
				$rows[] = array(
					'level'  => 'error',
					'title'  => sprintf(
						/* translators: %s: formula name */
						__( 'Formula “%s” is invalid', 'ratebridge-woo' ),
						$formula->name
					),
					'text'   => sprintf(
						/* translators: 1: error code, 2: position */
						__( 'Error %1$s at position %2$d. Products using it are not repriced.', 'ratebridge-woo' ),
						$compiled->code,
						$compiled->position
					),
					'tab'    => 'formulas',
					'action' => __( 'Fix the formula', 'ratebridge-woo' ),
				);
			}
		}

		return $rows;
	}
}
