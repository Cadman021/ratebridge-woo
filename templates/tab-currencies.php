<?php
/**
 * Currencies tab.
 *
 * @var array<string, mixed> $rbw_view View data.
 *
 * @package RateBridge
 */

use RateBridge\Admin\SettingsPage;
use RateBridge\Currency\Currency;
use RateBridge\Pricing\Repricer;

defined( 'ABSPATH' ) || exit;

$rbw_currencies = isset( $rbw_view['currencies'] ) && is_array( $rbw_view['currencies'] ) ? $rbw_view['currencies'] : array();
$rbw_providers  = isset( $rbw_view['providers'] ) && is_array( $rbw_view['providers'] ) ? $rbw_view['providers'] : array();
$rbw_settings   = isset( $rbw_view['settings'] ) && is_array( $rbw_view['settings'] ) ? $rbw_view['settings'] : array();
$rbw_log        = isset( $rbw_view['log'] ) && is_array( $rbw_view['log'] ) ? $rbw_view['log'] : array();
$rbw_unit       = SettingsPage::unit_label();
$rbw_next_run   = isset( $rbw_view['next_run'] ) ? (int) $rbw_view['next_run'] : 0;
$rbw_min        = isset( $rbw_view['min_interval'] ) ? (int) $rbw_view['min_interval'] : 5;
$rbw_symbols    = isset( $rbw_view['symbols'] ) && is_array( $rbw_view['symbols'] ) ? $rbw_view['symbols'] : array();
$rbw_repricer   = new Repricer();

// Only providers with a preset catalogue can power the "add from presets" list.
$rbw_preset_providers = array();

foreach ( $rbw_providers as $rbw_provider_key => $rbw_provider_label ) {
	if ( ! empty( $rbw_symbols[ $rbw_provider_key ] ) ) {
		$rbw_preset_providers[ $rbw_provider_key ] = $rbw_provider_label;
	}
}

/**
 * Status of one currency: enabled / disabled / pending / error.
 *
 * @param Currency $currency Currency.
 * @param Repricer $repricer Repricer (stale rule).
 * @return array{key:string,label:string,error:string}
 */
$rbw_status = static function ( Currency $currency, Repricer $repricer ): array {
	$error = $currency->error_message();

	if ( ! $currency->is_enabled ) {
		return array(
			'key'   => 'disabled',
			'label' => __( 'Disabled', 'ratebridge-woo' ),
			'error' => $error,
		);
	}

	if ( ! $currency->has_rate() ) {
		return array(
			'key'   => 'pending',
			'label' => __( 'Waiting for a rate', 'ratebridge-woo' ),
			'error' => $error,
		);
	}

	if ( '' !== $error || $repricer->is_stale( $currency ) ) {
		return array(
			'key'   => 'error',
			'label' => '' !== $error ? __( 'Error', 'ratebridge-woo' ) : __( 'Stale rate', 'ratebridge-woo' ),
			'error' => '' !== $error ? $error : __( 'The rate is older than the configured maximum age.', 'ratebridge-woo' ),
		);
	}

	return array(
		'key'   => 'enabled',
		'label' => __( 'Enabled', 'ratebridge-woo' ),
		'error' => '',
	);
};
?>

<div class="rbw-toolbar">
	<button type="button" class="button button-primary" id="rbw-add-currency">
		<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-plus"></use></svg>
		<?php esc_html_e( 'Add currency', 'ratebridge-woo' ); ?>
	</button>
	<button type="button" class="button" id="rbw-add-preset">
		<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-download"></use></svg>
		<?php esc_html_e( 'Add from presets', 'ratebridge-woo' ); ?>
	</button>
	<button type="button" class="button" id="rbw-update-all">
		<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-refresh"></use></svg>
		<?php esc_html_e( 'Update all now', 'ratebridge-woo' ); ?>
	</button>
	<span class="rbw-muted rbw-info-line">
		<?php
		printf(
			/* translators: 1: unit label (Rial/Toman), 2: next cron run time */
			esc_html__( 'Rates are stored in %1$s per 1 unit. Next automatic run: %2$s', 'ratebridge-woo' ),
			esc_html( $rbw_unit ),
			esc_html( $rbw_next_run ? wp_date( 'Y-m-d H:i', $rbw_next_run ) : __( 'not scheduled', 'ratebridge-woo' ) )
		);
		?>
	</span>
</div>

<p class="rbw-warning" id="rbw-interval-warning" hidden></p>

<!-- Client-side search + filters -->
<div class="rbw-filters" role="search">
	<label class="screen-reader-text" for="rbw-currency-search"><?php esc_html_e( 'Search currencies', 'ratebridge-woo' ); ?></label>
	<span class="rbw-filter-search">
		<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-search"></use></svg>
		<input type="search" id="rbw-currency-search" placeholder="<?php esc_attr_e( 'Search name or code…', 'ratebridge-woo' ); ?>" />
	</span>

	<label class="screen-reader-text" for="rbw-filter-status"><?php esc_html_e( 'Filter by status', 'ratebridge-woo' ); ?></label>
	<select id="rbw-filter-status">
		<option value=""><?php esc_html_e( 'All statuses', 'ratebridge-woo' ); ?></option>
		<option value="enabled"><?php esc_html_e( 'Enabled', 'ratebridge-woo' ); ?></option>
		<option value="disabled"><?php esc_html_e( 'Disabled', 'ratebridge-woo' ); ?></option>
		<option value="pending"><?php esc_html_e( 'Waiting for a rate', 'ratebridge-woo' ); ?></option>
		<option value="error"><?php esc_html_e( 'Error or stale', 'ratebridge-woo' ); ?></option>
	</select>

	<label class="screen-reader-text" for="rbw-filter-provider"><?php esc_html_e( 'Filter by price source', 'ratebridge-woo' ); ?></label>
	<select id="rbw-filter-provider">
		<option value=""><?php esc_html_e( 'All price sources', 'ratebridge-woo' ); ?></option>
		<?php foreach ( $rbw_providers as $rbw_key => $rbw_label ) : ?>
			<option value="<?php echo esc_attr( (string) $rbw_key ); ?>"><?php echo esc_html( (string) $rbw_label ); ?></option>
		<?php endforeach; ?>
	</select>

	<span class="rbw-filter-count" id="rbw-filter-count" aria-live="polite"></span>
</div>

<div class="rbw-table-scroll">
	<table class="rbw-table" id="rbw-currencies-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Currencies and their current rates', 'ratebridge-woo' ); ?></caption>
		<thead>
			<tr>
				<th class="rbw-col-enabled"><?php esc_html_e( 'Enabled', 'ratebridge-woo' ); ?></th>
				<th><?php esc_html_e( 'Currency', 'ratebridge-woo' ); ?></th>
				<th><?php esc_html_e( 'Status', 'ratebridge-woo' ); ?></th>
				<th class="rbw-col-rate"><?php echo esc_html( sprintf( /* translators: %s: currency unit */ __( 'Rate (%s)', 'ratebridge-woo' ), $rbw_unit ) ); ?></th>
				<th><?php esc_html_e( 'Price source', 'ratebridge-woo' ); ?></th>
				<th class="rbw-col-interval"><?php esc_html_e( 'Interval (min)', 'ratebridge-woo' ); ?></th>
				<th class="rbw-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ratebridge-woo' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rbw_currencies ) ) : ?>
				<tr class="rbw-empty-row">
					<td colspan="7">
						<?php esc_html_e( 'No currencies yet. Use “Add currency” to create one.', 'ratebridge-woo' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rbw_currencies as $rbw_currency ) : ?>
					<?php
					if ( ! $rbw_currency instanceof Currency ) {
						continue;
					}

					$rbw_provider_label = (string) ( $rbw_providers[ $rbw_currency->provider ] ?? $rbw_currency->provider );
					$rbw_state          = $rbw_status( $rbw_currency, $rbw_repricer );
					$rbw_timestamp      = \RateBridge\Frontend\Frontend::currency_timestamp( $rbw_currency );
					?>
					<tr
						data-id="<?php echo esc_attr( (string) $rbw_currency->id ); ?>"
						data-status="<?php echo esc_attr( $rbw_state['key'] ); ?>"
						data-provider="<?php echo esc_attr( $rbw_currency->provider ); ?>"
						data-search="<?php echo esc_attr( mb_strtolower( $rbw_currency->name . ' ' . $rbw_currency->code . ' ' . $rbw_currency->symbol ) ); ?>"
					>
						<td class="rbw-col-enabled" data-label="<?php esc_attr_e( 'Enabled', 'ratebridge-woo' ); ?>">
							<label class="rbw-switch">
								<input
									type="checkbox"
									class="rbw-toggle"
									data-id="<?php echo esc_attr( (string) $rbw_currency->id ); ?>"
									<?php checked( $rbw_currency->is_enabled ); ?>
								/>
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: %s: currency name */
										esc_html__( 'Enable %s', 'ratebridge-woo' ),
										esc_html( $rbw_currency->name )
									);
									?>
								</span>
							</label>
						</td>

						<td data-label="<?php esc_attr_e( 'Currency', 'ratebridge-woo' ); ?>">
							<div class="rbw-c-name">
								<strong><?php echo esc_html( $rbw_currency->name ); ?></strong>
								<code dir="ltr"><?php echo esc_html( $rbw_currency->code ); ?></code>
								<?php if ( '' !== $rbw_currency->symbol ) : ?>
									<span class="rbw-muted" dir="ltr"><?php echo esc_html( $rbw_currency->symbol ); ?></span>
								<?php endif; ?>
							</div>
						</td>

						<td data-label="<?php esc_attr_e( 'Status', 'ratebridge-woo' ); ?>">
							<span class="rbw-badge is-<?php echo esc_attr( $rbw_state['key'] ); ?>" <?php echo '' !== $rbw_state['error'] ? 'title="' . esc_attr( $rbw_state['error'] ) . '"' : ''; ?>>
								<?php echo esc_html( $rbw_state['label'] ); ?>
							</span>
							<?php if ( '' !== $rbw_state['error'] ) : ?>
								<div class="rbw-error-text"><?php echo esc_html( $rbw_state['error'] ); ?></div>
							<?php endif; ?>
						</td>

						<td class="rbw-rate" data-rate="<?php echo esc_attr( (string) $rbw_currency->rate ); ?>" data-label="<?php echo esc_attr( sprintf( /* translators: %s: unit */ __( 'Rate (%s)', 'ratebridge-woo' ), $rbw_unit ) ); ?>">
							<span class="rbw-rate-value"><?php echo esc_html( SettingsPage::format_rate( $rbw_currency ) ); ?></span>
							<?php if ( $rbw_timestamp > 0 ) : ?>
								<div class="rbw-muted rbw-last-updated" title="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', $rbw_timestamp ) ); ?>">
									<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-clock"></use></svg>
									<time datetime="<?php echo esc_attr( gmdate( 'c', $rbw_timestamp ) ); ?>"><?php echo esc_html( \RateBridge\Frontend\Frontend::time_ago( $rbw_timestamp ) ); ?></time>
								</div>
							<?php endif; ?>
						</td>

						<td data-label="<?php esc_attr_e( 'Price source', 'ratebridge-woo' ); ?>">
							<span class="rbw-chip"><?php echo esc_html( $rbw_provider_label ); ?></span>
						</td>

						<td class="rbw-col-interval" data-label="<?php esc_attr_e( 'Interval (min)', 'ratebridge-woo' ); ?>">
							<?php echo esc_html( (string) $rbw_currency->update_interval_minutes ); ?>
						</td>

						<td class="rbw-actions" data-label="<?php esc_attr_e( 'Actions', 'ratebridge-woo' ); ?>">
							<button
								type="button"
								class="button button-small rbw-icon-button rbw-update-one"
								data-id="<?php echo esc_attr( (string) $rbw_currency->id ); ?>"
								<?php disabled( 'manual' === $rbw_currency->provider ); ?>
								title="<?php esc_attr_e( 'Update now', 'ratebridge-woo' ); ?>"
							>
								<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-refresh"></use></svg>
								<span class="screen-reader-text"><?php esc_html_e( 'Update now', 'ratebridge-woo' ); ?></span>
							</button>

							<div class="rbw-kebab">
								<button
									type="button"
									class="button button-small rbw-icon-button rbw-kebab-toggle"
									aria-haspopup="true"
									aria-expanded="false"
									title="<?php esc_attr_e( 'More actions', 'ratebridge-woo' ); ?>"
								>
									<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-more"></use></svg>
									<span class="screen-reader-text"><?php esc_html_e( 'More actions', 'ratebridge-woo' ); ?></span>
								</button>
								<ul class="rbw-menu" role="menu" hidden>
									<li role="none">
										<button type="button" role="menuitem" class="rbw-menu-item rbw-edit" data-id="<?php echo esc_attr( (string) $rbw_currency->id ); ?>">
											<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-edit"></use></svg>
											<?php esc_html_e( 'Edit', 'ratebridge-woo' ); ?>
										</button>
									</li>
									<li role="none">
										<button type="button" role="menuitem" class="rbw-menu-item is-danger rbw-delete" data-id="<?php echo esc_attr( (string) $rbw_currency->id ); ?>">
											<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-trash"></use></svg>
											<?php esc_html_e( 'Delete', 'ratebridge-woo' ); ?>
										</button>
									</li>
								</ul>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<p class="rbw-muted">
	<?php
	printf(
		/* translators: %d: minimum interval in minutes */
		esc_html__( 'The minimum refresh interval is %d minutes (WordPress cron limit). Manual currencies are never updated automatically.', 'ratebridge-woo' ),
		(int) $rbw_min
	);
	?>
</p>

<!-- Add / edit dialog (plain HTML + JS, no build step) -->
<div class="rbw-modal" id="rbw-currency-modal" hidden>
	<div class="rbw-modal-backdrop" data-rbw-close="1"></div>
	<div class="rbw-modal-content" role="dialog" aria-modal="true" aria-labelledby="rbw-modal-title">
		<h2 id="rbw-modal-title"><?php esc_html_e( 'Add currency', 'ratebridge-woo' ); ?></h2>

		<form id="rbw-currency-form" onsubmit="return false;">
			<input type="hidden" name="id" value="0" />

			<div class="rbw-modal-body">
				<div class="rbw-modal-error" id="rbw-currency-error" role="alert" hidden></div>

				<h3 class="rbw-subsection-title"><?php esc_html_e( 'Basic information', 'ratebridge-woo' ); ?></h3>
				<div class="rbw-grid-form">
					<p>
						<label for="rbw-field-name"><?php esc_html_e( 'Name', 'ratebridge-woo' ); ?> <span class="rbw-required">*</span></label>
						<input type="text" id="rbw-field-name" name="name" class="regular-text" required />
					</p>
					<p>
						<label for="rbw-field-code"><?php esc_html_e( 'Code', 'ratebridge-woo' ); ?> <span class="rbw-required">*</span></label>
						<input type="text" id="rbw-field-code" name="code" class="regular-text" required maxlength="32" placeholder="USD" dir="ltr" />
						<span class="description"><?php esc_html_e( 'Unique identifier, letters/numbers only (e.g. USD, GOLD18).', 'ratebridge-woo' ); ?></span>
					</p>
					<p>
						<label for="rbw-field-symbol"><?php esc_html_e( 'Symbol', 'ratebridge-woo' ); ?></label>
						<input type="text" id="rbw-field-symbol" name="symbol" class="regular-text" />
					</p>
					<p>
						<label for="rbw-field-sort"><?php esc_html_e( 'Sort order', 'ratebridge-woo' ); ?></label>
						<input type="number" id="rbw-field-sort" name="sort_order" class="small-text" step="1" value="0" />
					</p>
				</div>

				<h3 class="rbw-subsection-title"><?php esc_html_e( 'Price source', 'ratebridge-woo' ); ?></h3>
				<div class="rbw-grid-form">
					<p>
						<label for="rbw-field-provider"><?php esc_html_e( 'Provider', 'ratebridge-woo' ); ?></label>
						<select id="rbw-field-provider" name="provider">
							<?php foreach ( $rbw_providers as $rbw_key => $rbw_label ) : ?>
								<option value="<?php echo esc_attr( (string) $rbw_key ); ?>"><?php echo esc_html( (string) $rbw_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="rbw-field-interval"><?php esc_html_e( 'Update interval (minutes)', 'ratebridge-woo' ); ?></label>
						<input
							type="number"
							id="rbw-field-interval"
							name="update_interval_minutes"
							class="small-text"
							min="<?php echo esc_attr( (string) $rbw_min ); ?>"
							step="1"
							value="<?php echo esc_attr( (string) (int) ( $rbw_settings['default_update_interval'] ?? 60 ) ); ?>"
						/>
					</p>
				</div>

				<p class="rbw-symbol-picker">
					<label for="rbw-symbol-search"><?php esc_html_e( 'Provider symbol', 'ratebridge-woo' ); ?></label>
					<input
						type="search"
						id="rbw-symbol-search"
						class="regular-text rbw-symbol-search"
						placeholder="<?php esc_attr_e( 'Search symbol…', 'ratebridge-woo' ); ?>"
						hidden
					/>
					<select id="rbw-field-provider-symbol" name="provider_symbol" class="rbw-symbol-select">
						<option value=""><?php esc_html_e( 'Custom symbol (free text)', 'ratebridge-woo' ); ?></option>
					</select>
					<input
						type="text"
						id="rbw-field-provider-symbol-text"
						class="regular-text"
						dir="ltr"
						placeholder="usd"
					/>
					<span class="description" id="rbw-symbol-hint">
						<?php esc_html_e( 'Pick a preset symbol or type a custom one. Leave empty to reuse the code.', 'ratebridge-woo' ); ?>
					</span>
				</p>

				<p>
					<label for="rbw-field-rate">
						<?php
						printf(
							/* translators: %s: currency unit */
							esc_html__( 'Manual rate (%s per 1 unit)', 'ratebridge-woo' ),
							esc_html( $rbw_unit )
						);
						?>
					</label>
					<input type="text" id="rbw-field-rate" name="rate" class="regular-text" dir="ltr" inputmode="decimal" />
					<span class="description" id="rbw-rate-hint"><?php esc_html_e( 'Used for the manual provider. Leave empty when editing to keep the current rate.', 'ratebridge-woo' ); ?></span>
				</p>

				<h3 class="rbw-subsection-title"><?php esc_html_e( 'Pricing settings', 'ratebridge-woo' ); ?></h3>
				<p class="description rbw-subsection-hint">
					<?php esc_html_e( 'Applied to every product priced by this currency. Products can override or extend these values.', 'ratebridge-woo' ); ?>
				</p>

				<div class="rbw-rule">
					<p>
						<label for="rbw-field-profit-value"><?php esc_html_e( 'Profit', 'ratebridge-woo' ); ?></label>
						<select id="rbw-field-profit-type" name="profit_type" class="rbw-type-select" data-slot="profit">
							<option value="percent"><?php esc_html_e( 'Percent', 'ratebridge-woo' ); ?></option>
							<option value="fixed"><?php esc_html_e( 'Fixed amount', 'ratebridge-woo' ); ?></option>
						</select>
						<input type="text" id="rbw-field-profit-value" name="profit_value" class="small-text rbw-number" dir="ltr" inputmode="decimal" value="0" />
						<span class="rbw-rule-unit" data-slot="profit">%</span>
					</p>
				</div>

				<div class="rbw-rule">
					<p>
						<label for="rbw-field-commission-value"><?php esc_html_e( 'Commission', 'ratebridge-woo' ); ?></label>
						<select id="rbw-field-commission-type" name="commission_type" class="rbw-type-select" data-slot="commission">
							<option value="percent"><?php esc_html_e( 'Percent', 'ratebridge-woo' ); ?></option>
							<option value="fixed"><?php esc_html_e( 'Fixed amount', 'ratebridge-woo' ); ?></option>
						</select>
						<input type="text" id="rbw-field-commission-value" name="commission_value" class="small-text rbw-number" dir="ltr" inputmode="decimal" value="0" />
						<span class="rbw-rule-unit" data-slot="commission">%</span>
					</p>
				</div>

				<div class="rbw-rule">
					<p>
						<label for="rbw-field-extra-amount"><?php esc_html_e( 'Extra amount', 'ratebridge-woo' ); ?></label>
						<input type="text" id="rbw-field-extra-amount" name="extra_amount" class="small-text rbw-number" dir="ltr" inputmode="decimal" value="0" />
						<span class="rbw-rule-unit"><?php echo esc_html( $rbw_unit ); ?></span>
					</p>
				</div>

				<p class="description rbw-percent-hint">
					<?php esc_html_e( 'A percentage is applied to the converted base value (profit) and to value + profit (commission). Fixed amounts are in the store unit.', 'ratebridge-woo' ); ?>
				</p>

				<p>
					<label class="rbw-inline">
						<input type="checkbox" id="rbw-field-enabled" name="is_enabled" value="1" />
						<?php esc_html_e( 'Enabled', 'ratebridge-woo' ); ?>
					</label>
				</p>
			</div>

			<p class="rbw-modal-actions">
				<button type="submit" class="button button-primary" id="rbw-save-currency"><?php esc_html_e( 'Save', 'ratebridge-woo' ); ?></button>
				<button type="button" class="button" data-rbw-close="1"><?php esc_html_e( 'Cancel', 'ratebridge-woo' ); ?></button>
			</p>
		</form>
	</div>
</div>

<!-- Add from presets dialog -->
<div class="rbw-modal" id="rbw-preset-modal" hidden>
	<div class="rbw-modal-backdrop" data-rbw-close="1"></div>
	<div class="rbw-modal-content" role="dialog" aria-modal="true" aria-labelledby="rbw-preset-title">
		<h2 id="rbw-preset-title"><?php esc_html_e( 'Add from presets', 'ratebridge-woo' ); ?></h2>

		<form id="rbw-preset-form" onsubmit="return false;">
			<div class="rbw-modal-body">
				<p class="rbw-muted">
					<?php esc_html_e( 'Pick a price source, then add one of its preset currencies. New rows start disabled so you can review them first.', 'ratebridge-woo' ); ?>
				</p>

				<div class="rbw-modal-error" id="rbw-preset-error" role="alert" hidden></div>
				<p>
					<label for="rbw-preset-provider"><?php esc_html_e( 'Price source', 'ratebridge-woo' ); ?></label>
					<select id="rbw-preset-provider" name="provider">
						<?php foreach ( $rbw_preset_providers as $rbw_preset_key => $rbw_preset_label ) : ?>
							<option value="<?php echo esc_attr( (string) $rbw_preset_key ); ?>"><?php echo esc_html( (string) $rbw_preset_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="rbw-preset-symbol-search"><?php esc_html_e( 'Currency', 'ratebridge-woo' ); ?></label>
					<input
						type="search"
						id="rbw-preset-symbol-search"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Search symbol…', 'ratebridge-woo' ); ?>"
					/>
					<select id="rbw-preset-symbol" name="symbol" size="8" class="rbw-preset-list"></select>
				</p>
				<p>
					<label for="rbw-preset-code"><?php esc_html_e( 'Code', 'ratebridge-woo' ); ?> <span class="rbw-required">*</span></label>
					<input type="text" id="rbw-preset-code" name="code" class="regular-text" dir="ltr" maxlength="32" />
					<span class="description" id="rbw-preset-code-hint">
						<?php esc_html_e( 'Generated from the symbol. Change it if the code is already taken.', 'ratebridge-woo' ); ?>
					</span>
				</p>
			</div>

			<p class="rbw-modal-actions">
				<button type="submit" class="button button-primary" id="rbw-preset-submit"><?php esc_html_e( 'Add currency', 'ratebridge-woo' ); ?></button>
				<button type="button" class="button" data-rbw-close="1"><?php esc_html_e( 'Cancel', 'ratebridge-woo' ); ?></button>
			</p>
		</form>
	</div>
</div>

<h2 class="rbw-section-title"><?php esc_html_e( 'Last 20 update attempts', 'ratebridge-woo' ); ?></h2>
<div class="rbw-table-scroll">
	<table class="rbw-table rbw-log-table" id="rbw-log-table">
		<thead>
			<tr>
				<th class="rbw-col-time"><?php esc_html_e( 'Time', 'ratebridge-woo' ); ?></th>
				<th class="rbw-col-status"><?php esc_html_e( 'Status', 'ratebridge-woo' ); ?></th>
				<th><?php esc_html_e( 'Message', 'ratebridge-woo' ); ?></th>
				<th class="rbw-col-trigger"><?php esc_html_e( 'Trigger', 'ratebridge-woo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rbw_log ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No update attempts recorded yet.', 'ratebridge-woo' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rbw_log as $rbw_entry ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Time', 'ratebridge-woo' ); ?>"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $rbw_entry['time'] ?? time() ) ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Status', 'ratebridge-woo' ); ?>">
							<span class="rbw-badge is-<?php echo esc_attr( 'success' === ( $rbw_entry['status'] ?? '' ) ? 'success' : 'error' ); ?>">
								<?php echo esc_html( 'success' === ( $rbw_entry['status'] ?? '' ) ? __( 'Success', 'ratebridge-woo' ) : __( 'Error', 'ratebridge-woo' ) ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( 'Message', 'ratebridge-woo' ); ?>"><?php echo esc_html( (string) ( $rbw_entry['message'] ?? '' ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Trigger', 'ratebridge-woo' ); ?>"><?php echo esc_html( 'manual' === ( $rbw_entry['trigger'] ?? '' ) ? __( 'Manual', 'ratebridge-woo' ) : __( 'Cron', 'ratebridge-woo' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
<p>
	<button type="button" class="button" id="rbw-clear-log">
		<?php esc_html_e( 'Clear log', 'ratebridge-woo' ); ?>
	</button>
	<button type="button" class="button" id="rbw-refresh-log">
		<?php esc_html_e( 'Refresh log', 'ratebridge-woo' ); ?>
	</button>
</p>
