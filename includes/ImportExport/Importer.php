<?php
/**
 * Package importer: validation, planning and execution.
 *
 * Three phases, all server-side:
 *  1. `analyse()`  — validate + build a plan (create/update/skip/invalid) — the dry run;
 *  2. `execute()`  — apply an already-validated plan (re-validated, never trusting the client);
 *  3. backup helpers for the undo path.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

use RateBridge\Core\Logger;
use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Formula\Formula;
use RateBridge\Formula\FormulaError;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;
use RateBridge\Providers\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a package to the site.
 */
final class Importer {

	/**
	 * Backup option name (non-autoloaded).
	 */
	public const BACKUP_OPTION = 'rbw_backups';

	/**
	 * How many backups are kept.
	 */
	public const MAX_BACKUPS = 3;

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
	 * Constructor.
	 *
	 * @param CurrencyRepository|null $currencies Currency repository.
	 * @param FormulaRepository|null  $formulas   Formula repository.
	 */
	public function __construct( ?CurrencyRepository $currencies = null, ?FormulaRepository $formulas = null ) {
		$this->currencies = $currencies ?? new CurrencyRepository();
		$this->formulas   = $formulas ?? new FormulaRepository();
	}

	/**
	 * Validate a package and build the dry-run plan.
	 *
	 * @param array<string, mixed> $package  Validated package data (from Package::validate()).
	 * @param string               $policy   skip|update.
	 * @param bool                 $convert  Whether to convert money amounts.
	 * @return array<string, mixed> {
	 *     @type array $counts   section => [create, update, skip, invalid].
	 *     @type array $messages [ { level, section, item, message } ] max 50.
	 *     @type array $sample   First rows showing old -> new.
	 *     @type array $rows     The full plan (server-side only).
	 *     @type array $meta     store_unit_from, store_unit_to, convert.
	 * }
	 */
	public function analyse( array $package, string $policy = ImportPlan::POLICY_SKIP, bool $convert = true ): array {
		$policy = ImportPlan::policy( $policy );
		$from   = Package::unit( $package['store_unit'] ?? 'toman' );
		$to     = Package::unit( Settings::store_unit() );
		$sections = isset( $package['sections'] ) && is_array( $package['sections'] ) ? $package['sections'] : array();

		$result = array(
			'counts'   => array(),
			'messages' => array(),
			'sample'   => array(),
			'rows'     => array(),
			'meta'     => array(
				'store_unit_from' => $from,
				'store_unit_to'   => $to,
				'unit_differs'    => ImportPlan::units_differ( $from, $to ),
				'convert'         => $convert,
				'policy'          => $policy,
			),
		);

		if ( isset( $sections['settings'] ) ) {
			$this->plan_settings( (array) $sections['settings'], $result );
		}

		if ( isset( $sections['currencies'] ) ) {
			$this->plan_currencies( (array) $sections['currencies'], $policy, $from, $to, $convert, $result );
		}

		if ( isset( $sections['formulas'] ) ) {
			$this->plan_formulas( (array) $sections['formulas'], $policy, $from, $to, $convert, $result );
		}

		return $result;
	}

	/**
	 * Plan the settings section (always a merge of known keys).
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param array<string, mixed> $result   Result (by reference).
	 * @return void
	 */
	private function plan_settings( array $settings, array &$result ): void {
		$clean = Package::clean_settings( $settings );

		$result['counts']['settings'] = array(
			'create'  => 0,
			'update'  => count( $clean ),
			'skip'    => 0,
			'invalid' => 0,
		);

		$result['rows']['settings'] = $clean;

		if ( $clean === $settings ) {
			return;
		}

		$this->add_message( $result, 'warning', 'settings', '-', __( 'Unknown or credential-like settings keys were ignored.', 'ratebridge-woo' ) );
	}

	/**
	 * Plan the currencies section.
	 *
	 * @param array<int, mixed> $rows    Raw rows.
	 * @param string            $policy  Conflict policy.
	 * @param string            $from    Package unit.
	 * @param string            $to      Site unit.
	 * @param bool              $convert Whether to convert amounts.
	 * @param array<string, mixed> $result Result (by reference).
	 * @return void
	 */
	private function plan_currencies( array $rows, string $policy, string $from, string $to, bool $convert, array &$result ): void {
		$counts = array(
			'create'  => 0,
			'update'  => 0,
			'skip'    => 0,
			'invalid' => 0,
		);

		$plan     = array();
		$known    = ProviderRegistry::instance()->choices();

		foreach ( $rows as $index => $row ) {
			$line = $index + 1;

			if ( ! is_array( $row ) ) {
				++$counts['invalid'];
				$this->add_message( $result, 'error', 'currencies', (string) $line, __( 'The row is not an object.', 'ratebridge-woo' ) );
				continue;
			}

			$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $row['code'] ?? '' ) ) ?? '' );

			if ( '' === $code ) {
				++$counts['invalid'];
				$this->add_message( $result, 'error', 'currencies', (string) $line, __( 'Missing currency code.', 'ratebridge-woo' ) );
				continue;
			}

			$clean = ImportPlan::convert_currency_row( $row, $from, $to, $convert );
			$clean['code'] = $code;

			// Unknown provider: import disabled with a warning.
			$provider = sanitize_key( (string) ( $clean['provider'] ?? 'manual' ) );

			if ( ! isset( $known[ $provider ] ) ) {
				$clean['provider']   = 'manual';
				$clean['is_enabled'] = 0;
				$this->add_message(
					$result,
					'warning',
					'currencies',
					$code,
					sprintf(
						/* translators: %s: provider key */
						__( 'Unknown price source “%s”: the currency is imported disabled with the manual source.', 'ratebridge-woo' ),
						$provider
					)
				);
			}

			// Symbol must match the provider catalogue when it has one.
			if ( isset( $clean['provider_symbol'] ) ) {
				$clean['provider_symbol'] = sanitize_text_field( (string) $clean['provider_symbol'] );
			}

			$existing = $this->currencies->get_by_code( $code );

			if ( $existing instanceof Currency ) {
				if ( ImportPlan::POLICY_UPDATE !== $policy ) {
					++$counts['skip'];
					continue;
				}

				++$counts['update'];
				$plan[] = array(
					'action'   => 'update',
					'id'       => $existing->id,
					'code'     => $code,
					'data'     => $clean,
					'old'      => array(
						'name'             => $existing->name,
						'profit_value'     => $existing->profit_value,
						'commission_value' => $existing->commission_value,
						'extra_amount'     => $existing->extra_amount,
					),
					'new'      => array(
						'name'             => (string) ( $clean['name'] ?? $existing->name ),
						'profit_value'     => (float) ( $clean['profit_value'] ?? $existing->profit_value ),
						'commission_value' => (float) ( $clean['commission_value'] ?? $existing->commission_value ),
						'extra_amount'     => (float) ( $clean['extra_amount'] ?? $existing->extra_amount ),
					),
				);
				continue;
			}

			++$counts['create'];
			$plan[] = array(
				'action' => 'create',
				'id'     => 0,
				'code'   => $code,
				'data'   => $clean,
				'old'    => array(),
				'new'    => array(
					'name'         => (string) ( $clean['name'] ?? $code ),
					'extra_amount' => (float) ( $clean['extra_amount'] ?? 0 ),
				),
			);
		}

		$result['counts']['currencies'] = $counts;
		$result['rows']['currencies']   = $plan;

		foreach ( array_slice( $plan, 0, 5 ) as $item ) {
			$result['sample'][] = array(
				'section' => 'currencies',
				'item'    => $item['code'],
				'action'  => $item['action'],
				'old'     => $this->format_pairs( $item['old'] ),
				'new'     => $this->format_pairs( $item['new'] ),
			);
		}
	}

	/**
	 * Plan the formulas section.
	 *
	 * @param array<int, mixed> $rows    Raw rows.
	 * @param string            $policy  Conflict policy.
	 * @param string            $from    Package unit.
	 * @param string            $to      Site unit.
	 * @param bool              $convert Whether to convert amounts.
	 * @param array<string, mixed> $result Result (by reference).
	 * @return void
	 */
	private function plan_formulas( array $rows, string $policy, string $from, string $to, bool $convert, array &$result ): void {
		$counts = array(
			'create'  => 0,
			'update'  => 0,
			'skip'    => 0,
			'invalid' => 0,
		);

		$plan     = array();
		$known    = array();

		foreach ( $this->currencies->get_all() as $currency ) {
			$known[] = $currency->code;
		}

		// Currency codes introduced by the package itself also count as known.
		foreach ( (array) ( $result['rows']['currencies'] ?? array() ) as $item ) {
			$known[] = (string) ( $item['code'] ?? '' );
		}

		foreach ( $rows as $index => $row ) {
			$line = $index + 1;

			if ( ! is_array( $row ) ) {
				++$counts['invalid'];
				$this->add_message( $result, 'error', 'formulas', (string) $line, __( 'The row is not an object.', 'ratebridge-woo' ) );
				continue;
			}

			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );

			if ( '' === $slug ) {
				++$counts['invalid'];
				$this->add_message( $result, 'error', 'formulas', (string) $line, __( 'Missing formula slug.', 'ratebridge-woo' ) );
				continue;
			}

			$expression = (string) ( $row['expression'] ?? '' );
			$variables  = FormulaModel::sanitize_variables( $row['variables'] ?? array() );
			$declared   = array_column( $variables, 'name' );

			// Every formula must compile and declare what it uses.
			$report = Formula::check( $expression, $declared );

			if ( ! $report['ok'] ) {
				++$counts['invalid'];
				$this->add_message(
					$result,
					'error',
					'formulas',
					$slug,
					sprintf(
						/* translators: 1: formula slug, 2: error code, 3: position */
						__( 'Formula “%1$s” is invalid (%2$s at position %3$d) and was skipped.', 'ratebridge-woo' ),
						$slug,
						(string) $report['code'],
						(int) $report['position']
					)
				);
				continue;
			}

			if ( ! empty( $report['undeclared'] ) ) {
				++$counts['invalid'];
				$this->add_message(
					$result,
					'error',
					'formulas',
					$slug,
					sprintf(
						/* translators: 1: formula slug, 2: variable list */
						__( 'Formula “%1$s” uses undeclared variables (%2$s) and was skipped.', 'ratebridge-woo' ),
						$slug,
						implode( ', ', (array) $report['undeclared'] )
					)
				);
				continue;
			}

			// Currency variables must point at a code that exists on the site or in the package.
			$missing_codes = array();

			foreach ( $variables as $variable ) {
				if ( FormulaModel::TYPE_CURRENCY !== ( $variable['type'] ?? '' ) ) {
					continue;
				}

				$code = (string) ( $variable['currency_code'] ?? '' );

				if ( '' !== $code && ! in_array( $code, $known, true ) ) {
					$missing_codes[] = $code;
				}
			}

			if ( ! empty( $missing_codes ) ) {
				$this->add_message(
					$result,
					'warning',
					'formulas',
					$slug,
					sprintf(
						/* translators: 1: formula slug, 2: currency codes */
						__( 'Formula “%1$s” references currencies that do not exist yet: %2$s. Its prices will not be calculated until they are created.', 'ratebridge-woo' ),
						$slug,
						implode( ', ', array_unique( $missing_codes ) )
					)
				);
			}

			$converted = ImportPlan::convert_formula_row( $row, $from, $to, $convert );
			$clean     = array(
				'slug'          => $slug,
				'name'          => sanitize_text_field( (string) ( $row['name'] ?? $slug ) ),
				'description'   => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
				'expression'    => $expression,
				'variables'     => $variables,
				'rounding_mode' => sanitize_key( (string) ( $converted['rounding_mode'] ?? '' ) ),
				'rounding_step' => (float) ( $converted['rounding_step'] ?? 0 ),
				'is_enabled'    => ! empty( $row['is_enabled'] ),
			);

			$existing = $this->formulas->get_by_slug( $slug );

			if ( $existing instanceof FormulaModel ) {
				if ( ImportPlan::POLICY_UPDATE !== $policy ) {
					++$counts['skip'];
					continue;
				}

				++$counts['update'];
				$plan[] = array(
					'action' => 'update',
					'id'     => $existing->id,
					'slug'   => $slug,
					'data'   => $clean,
					'old'    => array( 'expression' => $existing->expression ),
					'new'    => array( 'expression' => $expression ),
				);
				continue;
			}

			++$counts['create'];
			$plan[] = array(
				'action' => 'create',
				'id'     => 0,
				'slug'   => $slug,
				'data'   => $clean,
				'old'    => array(),
				'new'    => array( 'expression' => $expression ),
			);
		}

		$result['counts']['formulas'] = $counts;
		$result['rows']['formulas']   = $plan;

		foreach ( array_slice( $plan, 0, 5 ) as $item ) {
			$result['sample'][] = array(
				'section' => 'formulas',
				'item'    => $item['slug'],
				'action'  => $item['action'],
				'old'     => $this->format_pairs( $item['old'] ),
				'new'     => $this->format_pairs( $item['new'] ),
			);
		}
	}

	/**
	 * Execute an already-built plan.
	 *
	 * The plan is re-derived from the raw package by the caller; this method only
	 * applies rows and never trusts anything from the browser.
	 *
	 * @param array<string, mixed> $plan Plan from analyse().
	 * @return array<string, mixed> {
	 *     @type array $counts   Applied counts.
	 *     @type array $messages Messages collected while applying.
	 *     @type int[] $currencies Touched currency ids.
	 *     @type int[] $formulas   Touched formula ids.
	 * }
	 */
	public function execute( array $plan ): array {
		$applied  = array(
			'create'  => 0,
			'update'  => 0,
			'skip'    => 0,
			'invalid' => 0,
		);
		$messages  = array();
		$currencies = array();
		$formulas   = array();

		$rows = isset( $plan['rows'] ) && is_array( $plan['rows'] ) ? $plan['rows'] : array();

		// 1. Automatic backup BEFORE any write.
		$this->create_backup( 'pre-import' );

		// 2. Settings first: the unit is needed for everything else.
		if ( isset( $rows['settings'] ) && is_array( $rows['settings'] ) && ! empty( $rows['settings'] ) ) {
			Settings::update( $rows['settings'] );
			++$applied['update'];
		}

		// 3. Currencies.
		foreach ( (array) ( $rows['currencies'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				++$applied['invalid'];
				continue;
			}

			$data = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : array();

			if ( 'update' === ( $item['action'] ?? '' ) && ! empty( $item['id'] ) ) {
				$result = $this->currencies->update( (int) $item['id'], $data );

				if ( $result ) {
					++$applied['update'];
					$currencies[] = (int) $item['id'];
				} else {
					++$applied['invalid'];
					$messages[] = array(
						'level'   => 'error',
						'section' => 'currencies',
						'item'    => (string) ( $item['code'] ?? '' ),
						'message' => __( 'Could not update the currency.', 'ratebridge-woo' ),
					);
				}
				continue;
			}

			$id = $this->currencies->create( $data );

			if ( $id > 0 ) {
				++$applied['create'];
				$currencies[] = $id;
			} else {
				++$applied['invalid'];
				$messages[] = array(
					'level'   => 'error',
					'section' => 'currencies',
					'item'    => (string) ( $item['code'] ?? '' ),
					'message' => __( 'Could not create the currency.', 'ratebridge-woo' ),
				);
			}
		}

		// 4. Formulas.
		foreach ( (array) ( $rows['formulas'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				++$applied['invalid'];
				continue;
			}

			$data = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : array();

			// Re-validate on execute: never trust the analysed payload blindly.
			$check = Formula::check(
				(string) ( $data['expression'] ?? '' ),
				array_column( (array) ( $data['variables'] ?? array() ), 'name' )
			);

			if ( ! $check['ok'] ) {
				++$applied['invalid'];
				$messages[] = array(
					'level'   => 'error',
					'section' => 'formulas',
					'item'    => (string) ( $item['slug'] ?? '' ),
					'message' => __( 'The formula failed validation and was skipped.', 'ratebridge-woo' ),
				);
				continue;
			}

			if ( 'update' === ( $item['action'] ?? '' ) && ! empty( $item['id'] ) ) {
				$result = $this->formulas->update( (int) $item['id'], $data );

				if ( $result ) {
					++$applied['update'];
					$formulas[] = (int) $item['id'];
				} else {
					++$applied['invalid'];
				}
				continue;
			}

			$id = $this->formulas->create( $data );

			if ( $id > 0 ) {
				++$applied['create'];
				$formulas[] = $id;
			} else {
				++$applied['invalid'];
			}
		}

		// 5. Log a summary line.
		Logger::add(
			array(
				'status'     => $applied['invalid'] > 0 ? 'error' : 'success',
				'message'    => sprintf(
					/* translators: 1: created, 2: updated, 3: skipped, 4: invalid */
					__( 'Package import: %1$d created, %2$d updated, %3$d skipped, %4$d invalid.', 'ratebridge-woo' ),
					$applied['create'],
					$applied['update'],
					(int) ( $plan['counts']['currencies']['skip'] ?? 0 ) + (int) ( $plan['counts']['formulas']['skip'] ?? 0 ),
					$applied['invalid']
				),
				'currencies' => array_map(
					static function ( int $id ): string {
						$currency = ( new CurrencyRepository() )->get( $id );

						return $currency instanceof Currency ? $currency->code : (string) $id;
					},
					$currencies
				),
				'trigger'    => 'import',
			)
		);

		return array(
			'counts'     => $applied,
			'messages'   => $messages,
			'currencies' => array_values( array_unique( $currencies ) ),
			'formulas'   => array_values( array_unique( $formulas ) ),
		);
	}

	/**
	 * Format a before/after pair list for the dry-run sample table.
	 *
	 * @param array<string, mixed> $values Values keyed by field.
	 * @return string[]
	 */
	private function format_pairs( array $values ): array {
		$out = array();

		foreach ( $values as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$text = is_float( $value ) || is_int( $value )
				? rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' )
				: (string) $value;

			$out[] = $key . ': ' . ( '' === $text ? '—' : $text );
		}

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Backups                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Snapshot the current configuration into the backup ring.
	 *
	 * @param string $reason Short reason label.
	 * @return string Backup id.
	 */
	public function create_backup( string $reason = 'manual' ): string {
		$snapshot = ( new Exporter() )->build( Package::sections() );

		$backups = $this->backups();

		$id = gmdate( 'Ymd-His' ) . '-' . substr( md5( (string) wp_json_encode( $snapshot ) ), 0, 6 );

		array_unshift(
			$backups,
			array(
				'id'        => $id,
				'created'   => time(),
				'reason'    => sanitize_key( $reason ),
				'version'   => defined( 'RBW_VERSION' ) ? RBW_VERSION : '',
				'counts'    => array(
					'currencies' => count( $snapshot['sections']['currencies'] ?? array() ),
					'formulas'   => count( $snapshot['sections']['formulas'] ?? array() ),
				),
				// The package is stored inline (non-autoloaded option).
				'package'   => $snapshot,
			)
		);

		$backups = array_slice( $backups, 0, self::MAX_BACKUPS );

		update_option( self::BACKUP_OPTION, $backups, false );

		return $id;
	}

	/**
	 * Stored backups, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function backups(): array {
		$backups = get_option( self::BACKUP_OPTION, array() );

		return is_array( $backups ) ? array_values( array_filter( $backups, 'is_array' ) ) : array();
	}

	/**
	 * Public summary of the backups (no package bodies).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function backup_summaries(): array {
		$out = array();

		foreach ( $this->backups() as $backup ) {
			$out[] = array(
				'id'       => (string) ( $backup['id'] ?? '' ),
				'created'  => (int) ( $backup['created'] ?? 0 ),
				'date'     => ! empty( $backup['created'] ) ? wp_date( 'Y-m-d H:i', (int) $backup['created'] ) : '—',
				'reason'   => (string) ( $backup['reason'] ?? '' ),
				'version'  => (string) ( $backup['version'] ?? '' ),
				'counts'   => (array) ( $backup['counts'] ?? array() ),
			);
		}

		return $out;
	}

	/**
	 * Restore a backup by id (default: the newest).
	 *
	 * @param string $backup_id Backup id.
	 * @param string $policy    Conflict policy for the restore.
	 * @return array<string, mixed>|null Result, or null when the backup is missing.
	 */
	public function restore( string $backup_id = '', string $policy = ImportPlan::POLICY_UPDATE ): ?array {
		$backups = $this->backups();

		if ( empty( $backups ) ) {
			return null;
		}

		$target = null;

		foreach ( $backups as $backup ) {
			if ( '' === $backup_id || (string) ( $backup['id'] ?? '' ) === $backup_id ) {
				$target = $backup;
				break;
			}
		}

		if ( null === $target || empty( $target['package'] ) || ! is_array( $target['package'] ) ) {
			return null;
		}

		$validated = Package::validate( $target['package'] );

		if ( ! $validated['ok'] ) {
			return null;
		}

		// Back up the current state first, so a restore is itself undoable.
		$this->create_backup( 'pre-restore' );

		$plan = $this->analyse( $validated['data'], $policy, false );

		return $this->execute( $plan );
	}
}
