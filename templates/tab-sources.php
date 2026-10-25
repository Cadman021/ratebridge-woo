<?php
/**
 * Price sources tab: provider cards with settings, budgets and test button.
 *
 * @var array<string, mixed> $rbw_view View data.
 *
 * @package RateBridge
 */

defined( 'ABSPATH' ) || exit;

$rbw_cards = isset( $rbw_view['cards'] ) && is_array( $rbw_view['cards'] ) ? $rbw_view['cards'] : array();
?>
<h2 class="rbw-section-title"><?php esc_html_e( 'Price sources', 'ratebridge-woo' ); ?></h2>

<p class="rbw-muted">
	<?php esc_html_e( 'Each source has its own request quota. The plugin never sends more requests than the limits you configure here; when a limit is reached the previous rate is kept.', 'ratebridge-woo' ); ?>
</p>

<?php if ( empty( $rbw_cards ) ) : ?>
	<p><?php esc_html_e( 'No source with settings is registered.', 'ratebridge-woo' ); ?></p>
<?php else : ?>
	<div class="rbw-cards">
		<?php foreach ( $rbw_cards as $rbw_key => $rbw_card ) : ?>
			<?php
			$rbw_budget   = isset( $rbw_card['budget'] ) && is_array( $rbw_card['budget'] ) ? $rbw_card['budget'] : array();
			$rbw_used     = (int) ( $rbw_budget['used'] ?? 0 );
			$rbw_limit    = (int) ( $rbw_budget['limit'] ?? 0 );
			$rbw_percent  = $rbw_limit > 0 ? min( 100, (int) round( ( $rbw_used / $rbw_limit ) * 100 ) ) : 0;
			$rbw_warn     = $rbw_limit > 0 && $rbw_percent >= 80;
			?>
			<section class="rbw-card" data-provider="<?php echo esc_attr( (string) $rbw_key ); ?>">
				<header class="rbw-card-header">
					<h3>
						<?php echo esc_html( (string) ( $rbw_card['label'] ?? $rbw_key ) ); ?>
						<?php if ( ! empty( $rbw_card['configured'] ) ) : ?>
							<span class="rbw-badge rbw-badge-success"><?php esc_html_e( 'Configured', 'ratebridge-woo' ); ?></span>
						<?php else : ?>
							<span class="rbw-badge rbw-badge-error"><?php esc_html_e( 'Not configured', 'ratebridge-woo' ); ?></span>
						<?php endif; ?>
					</h3>
					<p class="rbw-muted">
						<?php
						printf(
							/* translators: %d: number of known symbols */
							esc_html__( '%d preset symbols available.', 'ratebridge-woo' ),
							(int) ( $rbw_card['known_symbols_count'] ?? 0 )
						);
						?>
					</p>
				</header>

				<form class="rbw-provider-form" data-provider="<?php echo esc_attr( (string) $rbw_key ); ?>" onsubmit="return false;">
					<?php foreach ( (array) ( $rbw_card['fields'] ?? array() ) as $rbw_field ) : ?>
						<?php
						$rbw_field_key  = (string) ( $rbw_field['key'] ?? '' );
						$rbw_field_type = (string) ( $rbw_field['type'] ?? 'text' );
						$rbw_input_id   = 'rbw-' . sanitize_html_class( (string) $rbw_key ) . '-' . sanitize_html_class( $rbw_field_key );
						?>
						<p class="rbw-field" data-field="<?php echo esc_attr( $rbw_field_key ); ?>">
							<label for="<?php echo esc_attr( $rbw_input_id ); ?>">
								<?php echo esc_html( (string) ( $rbw_field['label'] ?? $rbw_field_key ) ); ?>
								<?php if ( ! empty( $rbw_field['required'] ) ) : ?>
									<span class="rbw-required">*</span>
								<?php endif; ?>
							</label>

							<?php if ( 'password' === $rbw_field_type ) : ?>
								<input
									type="password"
									id="<?php echo esc_attr( $rbw_input_id ); ?>"
									name="<?php echo esc_attr( $rbw_field_key ); ?>"
									class="regular-text"
									dir="ltr"
									autocomplete="new-password"
									value=""
									placeholder="<?php echo esc_attr( (string) ( $rbw_field['value'] ?? '' ) ); ?>"
									data-has-value="<?php echo ! empty( $rbw_field['has_value'] ) ? '1' : '0'; ?>"
								/>
								<span class="description">
									<?php
									if ( ! empty( $rbw_field['has_value'] ) ) {
										esc_html_e( 'A value is stored. Leave empty to keep it.', 'ratebridge-woo' );
									} else {
										esc_html_e( 'No value stored yet.', 'ratebridge-woo' );
									}
									?>
								</span>
							<?php elseif ( 'select' === $rbw_field_type ) : ?>
								<select id="<?php echo esc_attr( $rbw_input_id ); ?>" name="<?php echo esc_attr( $rbw_field_key ); ?>">
									<?php foreach ( (array) ( $rbw_field['options'] ?? array() ) as $rbw_opt_key => $rbw_opt_label ) : ?>
										<option
											value="<?php echo esc_attr( (string) $rbw_opt_key ); ?>"
											<?php selected( (string) ( $rbw_field['value'] ?? '' ), (string) $rbw_opt_key ); ?>
										>
											<?php echo esc_html( (string) $rbw_opt_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'number' === $rbw_field_type ) : ?>
								<input
									type="number"
									id="<?php echo esc_attr( $rbw_input_id ); ?>"
									name="<?php echo esc_attr( $rbw_field_key ); ?>"
									class="small-text"
									dir="ltr"
									value="<?php echo esc_attr( (string) ( $rbw_field['value'] ?? '' ) ); ?>"
									<?php echo isset( $rbw_field['min'] ) && null !== $rbw_field['min'] ? 'min="' . esc_attr( (string) $rbw_field['min'] ) . '"' : ''; ?>
									<?php echo isset( $rbw_field['step'] ) && null !== $rbw_field['step'] ? 'step="' . esc_attr( (string) $rbw_field['step'] ) . '"' : ''; ?>
								/>
							<?php elseif ( 'checkbox' === $rbw_field_type ) : ?>
								<label class="rbw-inline">
									<input
										type="checkbox"
										id="<?php echo esc_attr( $rbw_input_id ); ?>"
										name="<?php echo esc_attr( $rbw_field_key ); ?>"
										value="1"
										<?php checked( (string) ( $rbw_field['value'] ?? '' ), '1' ); ?>
									/>
									<?php echo esc_html( (string) ( $rbw_field['label'] ?? '' ) ); ?>
								</label>
							<?php else : ?>
								<input
									type="text"
									id="<?php echo esc_attr( $rbw_input_id ); ?>"
									name="<?php echo esc_attr( $rbw_field_key ); ?>"
									class="regular-text"
									dir="ltr"
									value="<?php echo esc_attr( (string) ( $rbw_field['value'] ?? '' ) ); ?>"
								/>
							<?php endif; ?>

							<?php if ( ! empty( $rbw_field['description'] ) ) : ?>
								<span class="description"><?php echo esc_html( (string) $rbw_field['description'] ); ?></span>
							<?php endif; ?>

							<?php if ( ! empty( $rbw_field['hint'] ) ) : ?>
								<span class="description rbw-hint"><?php echo esc_html( (string) $rbw_field['hint'] ); ?></span>
							<?php endif; ?>
						</p>
					<?php endforeach; ?>

					<p class="rbw-card-actions">
						<button type="submit" class="button button-primary rbw-save-provider" data-provider="<?php echo esc_attr( (string) $rbw_key ); ?>">
							<?php esc_html_e( 'Save', 'ratebridge-woo' ); ?>
						</button>
						<button
							type="button"
							class="button rbw-test-provider"
							data-provider="<?php echo esc_attr( (string) $rbw_key ); ?>"
							<?php disabled( empty( $rbw_card['uses_network'] ) ); ?>
						>
							<?php esc_html_e( 'Test connection', 'ratebridge-woo' ); ?>
						</button>
						<button type="button" class="button rbw-reset-budget" data-provider="<?php echo esc_attr( (string) $rbw_key ); ?>">
							<?php esc_html_e( 'Reset counters', 'ratebridge-woo' ); ?>
						</button>
					</p>
				</form>

				<div class="rbw-test-result" data-provider="<?php echo esc_attr( (string) $rbw_key ); ?>" hidden></div>

				<div class="rbw-budget">
					<h4><?php esc_html_e( 'Request quota', 'ratebridge-woo' ); ?></h4>
					<ul class="rbw-budget-list">
						<li>
							<span><?php esc_html_e( 'Requests this month', 'ratebridge-woo' ); ?>:</span>
							<strong>
								<?php
								echo esc_html(
									$rbw_limit > 0
										/* translators: 1: used requests, 2: monthly limit */
										? sprintf( __( '%1$d of %2$d', 'ratebridge-woo' ), $rbw_used, $rbw_limit )
										/* translators: %d: used requests */
										: sprintf( __( '%d (no limit set)', 'ratebridge-woo' ), $rbw_used )
								);
								?>
							</strong>
						</li>
						<li>
							<span><?php esc_html_e( 'Last request', 'ratebridge-woo' ); ?>:</span>
							<strong>
								<?php
								echo esc_html(
									! empty( $rbw_budget['last_request_fmt'] )
										? (string) $rbw_budget['last_request_fmt']
										: __( 'never', 'ratebridge-woo' )
								);
								?>
							</strong>
						</li>
						<li>
							<span><?php esc_html_e( 'Next allowed request', 'ratebridge-woo' ); ?>:</span>
							<strong>
								<?php
								echo esc_html(
									! empty( $rbw_budget['next_allowed_fmt'] )
										? (string) $rbw_budget['next_allowed_fmt']
										: __( 'now', 'ratebridge-woo' )
								);
								?>
							</strong>
						</li>
						<li>
							<span><?php esc_html_e( 'Minimum interval', 'ratebridge-woo' ); ?>:</span>
							<strong>
								<?php
								printf(
									/* translators: 1: configured minutes, 2: effective minutes */
									esc_html__( '%1$d minutes configured, %2$d minutes effective', 'ratebridge-woo' ),
									(int) ( $rbw_budget['min_interval'] ?? 0 ),
									(int) ( $rbw_budget['effective'] ?? 0 )
								);
								?>
							</strong>
						</li>
					</ul>

					<?php if ( $rbw_limit > 0 ) : ?>
						<div class="rbw-meter" role="img" aria-label="<?php esc_attr_e( 'Monthly request usage', 'ratebridge-woo' ); ?>">
							<span
								class="rbw-meter-fill <?php echo $rbw_warn ? 'is-warn' : ''; ?>"
								style="width: <?php echo esc_attr( (string) $rbw_percent ); ?>%"
							></span>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $rbw_budget['last_error'] ) ) : ?>
						<p class="rbw-error-text">
							<?php
							printf(
								/* translators: %s: last error message */
								esc_html__( 'Last error: %s', 'ratebridge-woo' ),
								esc_html( (string) $rbw_budget['last_error'] )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</section>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<p class="rbw-muted">
	<?php
	printf(
		/* translators: %d: backoff minutes */
		esc_html__( 'After a failed request the plugin waits at least %d minutes before trying that source again, so a broken key never burns the quota.', 'ratebridge-woo' ),
		\RateBridge\Providers\RequestBudget::FAILURE_BACKOFF_MINUTES
	);
	?>
</p>
