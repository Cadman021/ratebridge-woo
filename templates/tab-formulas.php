<?php
/**
 * Formulas tab: list + builder.
 *
 * @var array<string, mixed> $rbw_view View data.
 *
 * @package RateBridge
 */

use RateBridge\Frontend\RateFormatter;

defined( 'ABSPATH' ) || exit;

$rbw_formulas      = isset( $rbw_view['formulas'] ) && is_array( $rbw_view['formulas'] ) ? $rbw_view['formulas'] : array();
$rbw_var_types     = isset( $rbw_view['variable_types'] ) && is_array( $rbw_view['variable_types'] ) ? $rbw_view['variable_types'] : array();
$rbw_product_fields = isset( $rbw_view['product_fields'] ) && is_array( $rbw_view['product_fields'] ) ? $rbw_view['product_fields'] : array();
$rbw_currencies    = isset( $rbw_view['currencies'] ) && is_array( $rbw_view['currencies'] ) ? $rbw_view['currencies'] : array();
$rbw_settings      = isset( $rbw_view['settings'] ) && is_array( $rbw_view['settings'] ) ? $rbw_view['settings'] : array();
$rbw_rounding      = (string) ( $rbw_settings['rounding_mode'] ?? 'nearest' );
$rbw_step          = (int) ( $rbw_settings['rounding_step'] ?? 1000 );
$rbw_unit          = RateFormatter::unit_label( (string) ( $rbw_settings['store_currency_unit'] ?? 'toman' ), __( 'Toman', 'ratebridge-woo' ), __( 'Rial', 'ratebridge-woo' ) );

/*
 * The footnote used to print the raw stored value (`nearest`), which means
 * nothing to a Persian-speaking admin. Same vocabulary as the General tab's
 * dropdown, so the two screens can never disagree, plus the step when the mode
 * uses one — «گرد کردن به نزدیک‌ترین مضرب ۱۰٬۰۰۰».
 */
$rbw_rounding_labels = array(
	'none'    => __( 'None', 'ratebridge-woo' ),
	'nearest' => __( 'Round to the nearest multiple of %s', 'ratebridge-woo' ),
	'ceil'    => __( 'Round up to a multiple of %s', 'ratebridge-woo' ),
	'floor'   => __( 'Round down to a multiple of %s', 'ratebridge-woo' ),
);

$rbw_rounding_label = $rbw_rounding_labels[ $rbw_rounding ] ?? $rbw_rounding_labels['nearest'];

if ( 'none' !== $rbw_rounding && $rbw_step > 0 ) {
	$rbw_rounding_label = sprintf( $rbw_rounding_label, RateFormatter::number( (float) $rbw_step ) );
} elseif ( 'none' !== $rbw_rounding ) {
	// No step configured: fall back to the plain mode name.
	$rbw_rounding_label = __( 'Round to nearest N', 'ratebridge-woo' );
}
?>

<div class="rbw-toolbar">
	<button type="button" class="button button-primary" id="rbw-add-formula">
		<?php esc_html_e( 'Add formula', 'ratebridge-woo' ); ?>
	</button>
	<button type="button" class="button" id="rbw-add-formula-samples">
		<?php esc_html_e( 'Add samples', 'ratebridge-woo' ); ?>
	</button>
	<span class="rbw-muted rbw-info-line">
		<?php esc_html_e( 'A formula computes the product price from variables (currency rates, product weight/dimensions, custom values). Formulas are safe: they contain no code and cannot call anything outside the built-in function list.', 'ratebridge-woo' ); ?>
	</span>
</div>

<table class="widefat rbw-table rbw-table-list" id="rbw-formulas-table">
	<thead>
		<tr>
			<th class="rbw-col-enabled"><?php esc_html_e( 'Enabled', 'ratebridge-woo' ); ?></th>
			<th><?php esc_html_e( 'Name', 'ratebridge-woo' ); ?></th>
			<th><?php esc_html_e( 'Status', 'ratebridge-woo' ); ?></th>
			<th><?php esc_html_e( 'Expression', 'ratebridge-woo' ); ?></th>
			<th class="rbw-col-count"><?php esc_html_e( 'Variables', 'ratebridge-woo' ); ?></th>
			<th class="rbw-col-count"><?php esc_html_e( 'Products', 'ratebridge-woo' ); ?></th>
			<th class="rbw-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ratebridge-woo' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $rbw_formulas ) ) : ?>
			<tr class="rbw-empty-row">
				<td colspan="7"><?php esc_html_e( 'No formulas yet. Use “Add formula” or “Add samples”.', 'ratebridge-woo' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $rbw_formulas as $rbw_row ) : ?>
				<?php
				$rbw_formula  = $rbw_row['formula'] ?? null;
				$rbw_products = (int) ( $rbw_row['products'] ?? 0 );

				if ( ! $rbw_formula instanceof \RateBridge\Formula\FormulaModel ) {
					continue;
				}

				$rbw_expr = preg_replace( '/\s+/', ' ', (string) $rbw_formula->expression ) ?? '';
				$rbw_expr = mb_strimwidth( $rbw_expr, 0, 70, '…' );

				// Same status vocabulary as the currencies list.
				$rbw_state = ! $rbw_formula->is_enabled ? 'disabled' : 'enabled';
				$rbw_label = ! $rbw_formula->is_enabled
					? __( 'Disabled', 'ratebridge-woo' )
					: __( 'Enabled', 'ratebridge-woo' );
				?>
				<?php
				// The builder reads the full row from this attribute (no extra request).
				$rbw_payload = wp_json_encode( $rbw_formula->to_array() );
				?>
				<tr
					data-formula-id="<?php echo esc_attr( (string) $rbw_formula->id ); ?>"
					data-status="<?php echo esc_attr( $rbw_state ); ?>"
					data-search="<?php echo esc_attr( mb_strtolower( $rbw_formula->name . ' ' . $rbw_formula->slug . ' ' . (string) $rbw_formula->expression ) ); ?>"
					data-payload="<?php echo esc_attr( (string) $rbw_payload ); ?>"
				>
					<td class="rbw-col-enabled" data-label="<?php esc_attr_e( 'Enabled', 'ratebridge-woo' ); ?>">
						<label class="rbw-switch">
							<input
								type="checkbox"
								class="rbw-formula-toggle"
								data-id="<?php echo esc_attr( (string) $rbw_formula->id ); ?>"
								<?php checked( $rbw_formula->is_enabled ); ?>
							/>
							<span class="screen-reader-text"><?php esc_html_e( 'Enable formula', 'ratebridge-woo' ); ?></span>
						</label>
					</td>

					<td data-label="<?php esc_attr_e( 'Name', 'ratebridge-woo' ); ?>">
						<div class="rbw-c-name">
							<strong><?php echo esc_html( $rbw_formula->name ); ?></strong>
							<code dir="ltr"><?php echo esc_html( $rbw_formula->slug ); ?></code>
						</div>
					</td>

					<td data-label="<?php esc_attr_e( 'Status', 'ratebridge-woo' ); ?>">
						<span class="rbw-badge is-<?php echo esc_attr( $rbw_state ); ?> rbw-formula-badge">
							<?php echo esc_html( $rbw_label ); ?>
						</span>
						<?php if ( 0 === $rbw_products ) : ?>
							<div class="rbw-error-text rbw-formula-hint">
								<?php esc_html_e( 'Not used by any product yet.', 'ratebridge-woo' ); ?>
							</div>
						<?php endif; ?>
					</td>

					<td data-label="<?php esc_attr_e( 'Expression', 'ratebridge-woo' ); ?>"><code dir="ltr" class="rbw-formula-expr"><?php echo esc_html( $rbw_expr ); ?></code></td>
					<td data-label="<?php esc_attr_e( 'Variables', 'ratebridge-woo' ); ?>"><?php echo esc_html( (string) count( $rbw_formula->variables ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Products', 'ratebridge-woo' ); ?>">
						<?php if ( $rbw_products > 0 ) : ?>
							<span class="rbw-chip"><?php echo esc_html( (string) $rbw_products ); ?></span>
						<?php else : ?>
							<span class="rbw-muted">0</span>
						<?php endif; ?>
					</td>

					<td class="rbw-actions" data-label="<?php esc_attr_e( 'Actions', 'ratebridge-woo' ); ?>">
						<div class="rbw-row-actions">
							<button
								type="button"
								class="button button-small rbw-icon-button rbw-formula-edit"
								data-id="<?php echo esc_attr( (string) $rbw_formula->id ); ?>"
								title="<?php esc_attr_e( 'Edit', 'ratebridge-woo' ); ?>"
							>
								<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-edit"></use></svg>
								<span class="screen-reader-text"><?php esc_html_e( 'Edit', 'ratebridge-woo' ); ?></span>
							</button>

							<div class="rbw-kebab">
								<button
									type="button"
									class="button button-small rbw-icon-button rbw-kebab-toggle"
									aria-haspopup="menu"
									aria-expanded="false"
									aria-controls="rbw-formula-menu-<?php echo esc_attr( (string) $rbw_formula->id ); ?>"
									title="<?php esc_attr_e( 'More actions', 'ratebridge-woo' ); ?>"
								>
									<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-more"></use></svg>
									<span class="screen-reader-text"><?php esc_html_e( 'More actions', 'ratebridge-woo' ); ?></span>
								</button>
								<ul class="rbw-menu" role="menu" id="rbw-formula-menu-<?php echo esc_attr( (string) $rbw_formula->id ); ?>" hidden>
									<li role="none">
										<button type="button" role="menuitem" class="rbw-menu-item rbw-formula-edit" data-id="<?php echo esc_attr( (string) $rbw_formula->id ); ?>">
											<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-edit"></use></svg>
											<?php esc_html_e( 'Edit', 'ratebridge-woo' ); ?>
										</button>
									</li>
									<li role="none">
										<button type="button" role="menuitem" class="rbw-menu-item rbw-formula-duplicate" data-id="<?php echo esc_attr( (string) $rbw_formula->id ); ?>">
											<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-copy"></use></svg>
											<?php esc_html_e( 'Duplicate', 'ratebridge-woo' ); ?>
										</button>
									</li>
									<li role="none">
										<button type="button" role="menuitem" class="rbw-menu-item is-danger rbw-formula-delete" data-id="<?php echo esc_attr( (string) $rbw_formula->id ); ?>" data-products="<?php echo esc_attr( (string) $rbw_products ); ?>">
											<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-trash"></use></svg>
											<?php esc_html_e( 'Delete', 'ratebridge-woo' ); ?>
										</button>
									</li>
								</ul>
							</div>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>

<!-- Formula builder dialog -->
<div class="rbw-modal rbw-modal-wide" id="rbw-formula-modal" hidden>
	<div class="rbw-modal-backdrop" data-rbw-close="1"></div>
	<div class="rbw-modal-content" role="dialog" aria-modal="true" aria-labelledby="rbw-formula-title">
		<h2 id="rbw-formula-title"><?php esc_html_e( 'Add formula', 'ratebridge-woo' ); ?></h2>

		<form id="rbw-formula-form" onsubmit="return false;">
			<input type="hidden" name="id" value="0" />

			<div class="rbw-modal-error" id="rbw-formula-error" role="alert" hidden></div>

			<div class="rbw-formula-grid">
				<p>
					<label for="rbw-formula-name"><?php esc_html_e( 'Name', 'ratebridge-woo' ); ?> <span class="rbw-required">*</span></label>
					<input type="text" id="rbw-formula-name" name="name" class="regular-text" required />
				</p>
				<p>
					<label for="rbw-formula-slug"><?php esc_html_e( 'Slug', 'ratebridge-woo' ); ?></label>
					<input type="text" id="rbw-formula-slug" name="slug" class="regular-text" dir="ltr" maxlength="64" />
					<span class="description"><?php esc_html_e( 'Generated from the name; must stay unique.', 'ratebridge-woo' ); ?></span>
				</p>
			</div>

			<p>
				<label for="rbw-formula-description"><?php esc_html_e( 'Description', 'ratebridge-woo' ); ?></label>
				<textarea id="rbw-formula-description" name="description" rows="2" class="large-text"></textarea>
			</p>

			<h3 class="rbw-subsection-title"><?php esc_html_e( 'Expression', 'ratebridge-woo' ); ?></h3>

			<div class="rbw-formula-chips" id="rbw-formula-chips">
				<span class="rbw-chips-label"><?php esc_html_e( 'Variables:', 'ratebridge-woo' ); ?></span>
				<span class="rbw-chip-list" id="rbw-chip-vars"></span>
			</div>

			<div class="rbw-formula-chips">
				<span class="rbw-chips-label"><?php esc_html_e( 'Operators:', 'ratebridge-woo' ); ?></span>
				<span class="rbw-chip-list">
					<?php foreach ( array( '+', '-', '*', '/', '%', '^', '(', ')', '<', '>', '<=', '>=', '==', '!=', 'and', 'or', 'not' ) as $rbw_op ) : ?>
						<button type="button" class="button button-small rbw-chip rbw-insert" data-insert="<?php echo esc_attr( $rbw_op ); ?>"><?php echo esc_html( $rbw_op ); ?></button>
					<?php endforeach; ?>
				</span>
			</div>

			<div class="rbw-formula-chips">
				<span class="rbw-chips-label"><?php esc_html_e( 'Functions:', 'ratebridge-woo' ); ?></span>
				<span class="rbw-chip-list">
					<?php foreach ( array( 'min', 'max', 'round', 'ceil', 'floor', 'abs', 'pow', 'sqrt', 'clamp', 'if', 'percent' ) as $rbw_fn ) : ?>
						<button type="button" class="button button-small rbw-chip rbw-insert" data-insert="<?php echo esc_attr( $rbw_fn . '()' ); ?>" data-caret="-1"><?php echo esc_html( $rbw_fn ); ?></button>
					<?php endforeach; ?>
				</span>
			</div>

			<p class="rbw-formula-expression-wrap">
				<label for="rbw-formula-expression"><?php esc_html_e( 'Expression', 'ratebridge-woo' ); ?> <span class="rbw-required">*</span></label>
				<textarea
					id="rbw-formula-expression"
					name="expression"
					rows="4"
					class="large-text rbw-formula-expression"
					dir="ltr"
					spellcheck="false"
					autocomplete="off"
					required
					placeholder="weight * gold18 * (1 + wage / 100) * (1 + profit / 100)"
				></textarea>
				<span class="description">
					<?php esc_html_e( 'Allowed: numbers, variables, + - * / % ^, comparisons, and/or/not, and the functions min, max, round, ceil, floor, abs, pow, sqrt, clamp, if, percent.', 'ratebridge-woo' ); ?>
				</span>
				<pre class="rbw-formula-error-line" id="rbw-formula-error-line" hidden></pre>
				<p class="rbw-formula-status" id="rbw-formula-status" hidden></p>
			</p>

			<h3 class="rbw-subsection-title"><?php esc_html_e( 'Variables', 'ratebridge-woo' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Every identifier used in the expression must be declared here.', 'ratebridge-woo' ); ?>
			</p>

			<table class="widefat rbw-formula-vars" id="rbw-formula-vars">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'ratebridge-woo' ); ?></th>
						<th><?php esc_html_e( 'Label', 'ratebridge-woo' ); ?></th>
						<th><?php esc_html_e( 'Type', 'ratebridge-woo' ); ?></th>
						<th class="rbw-var-extra"><?php esc_html_e( 'Details', 'ratebridge-woo' ); ?></th>
						<th class="rbw-col-actions"></th>
					</tr>
				</thead>
				<tbody id="rbw-formula-vars-body"></tbody>
			</table>

			<p>
				<button type="button" class="button" id="rbw-add-variable">
					<?php esc_html_e( 'Add variable', 'ratebridge-woo' ); ?>
				</button>
				<button type="button" class="button" id="rbw-autocreate-vars" hidden>
					<?php esc_html_e( 'Create missing variables', 'ratebridge-woo' ); ?>
				</button>
			</p>

			<h3 class="rbw-subsection-title"><?php esc_html_e( 'Rounding override', 'ratebridge-woo' ); ?></h3>
			<p>
				<label for="rbw-formula-rounding-mode"><?php esc_html_e( 'Rounding mode', 'ratebridge-woo' ); ?></label>
				<select id="rbw-formula-rounding-mode" name="rounding_mode">
					<option value=""><?php esc_html_e( 'Use the global setting', 'ratebridge-woo' ); ?></option>
					<option value="none"><?php esc_html_e( 'None', 'ratebridge-woo' ); ?></option>
					<option value="nearest"><?php esc_html_e( 'Round to nearest N', 'ratebridge-woo' ); ?></option>
					<option value="ceil"><?php esc_html_e( 'Round up (ceil)', 'ratebridge-woo' ); ?></option>
					<option value="floor"><?php esc_html_e( 'Round down (floor)', 'ratebridge-woo' ); ?></option>
				</select>
				<label for="rbw-formula-rounding-step"><?php esc_html_e( 'Step', 'ratebridge-woo' ); ?></label>
				<input type="number" id="rbw-formula-rounding-step" name="rounding_step" class="small-text" min="0" step="1" value="0" />
				<span class="description"><?php esc_html_e( '0 = use the global step.', 'ratebridge-woo' ); ?></span>
			</p>

			<p>
				<label class="rbw-inline">
					<input type="checkbox" id="rbw-formula-enabled" name="is_enabled" value="1" checked />
					<?php esc_html_e( 'Enabled', 'ratebridge-woo' ); ?>
				</label>
			</p>

			<h3 class="rbw-subsection-title"><?php esc_html_e( 'Test the formula', 'ratebridge-woo' ); ?></h3>
			<p class="description">
				<?php
				printf(
					/* translators: %s: unit label */
					esc_html__( 'Enter sample values and see the raw result. Currency variables are prefilled with the live rate; nothing is saved. The final price then applies the minimum price, rounding and the fluctuation margin (%s).', 'ratebridge-woo' ),
					esc_html( $rbw_unit )
				);
				?>
			</p>
			<div class="rbw-formula-test" id="rbw-formula-test-vars"></div>
			<p>
				<button type="button" class="button" id="rbw-test-formula">
					<?php esc_html_e( 'Calculate', 'ratebridge-woo' ); ?>
				</button>
			</p>
			<div class="rbw-formula-test-result" id="rbw-formula-test-result" hidden></div>

			<p class="rbw-modal-actions">
				<button type="submit" class="button button-primary" id="rbw-save-formula">
					<?php esc_html_e( 'Save formula', 'ratebridge-woo' ); ?>
				</button>
				<button type="button" class="button" data-rbw-close="1"><?php esc_html_e( 'Cancel', 'ratebridge-woo' ); ?></button>
			</p>
		</form>
	</div>
</div>

<p class="rbw-muted">
	<?php
	printf(
		/* translators: 1: global rounding mode label, 2: unit label */
		esc_html__( 'Global rounding mode: %1$s. Formula prices are in %2$s. Formula products ignore the currency profit, commission and extra amount — put them in the formula.', 'ratebridge-woo' ),
		'<span class="rbw-chip">' . esc_html( $rbw_rounding_label ) . '</span>',
		esc_html( $rbw_unit )
	);
	?>
</p>
