<?php
/**
 * General settings tab.
 *
 * @var array<string, mixed> $rbw_view View data.
 *
 * @package RateBridge
 */

use RateBridge\Admin\SettingsPage;
use RateBridge\Frontend\ProductNotice;

defined( 'ABSPATH' ) || exit;

$rbw_settings = isset( $rbw_view['settings'] ) && is_array( $rbw_view['settings'] ) ? $rbw_view['settings'] : array();
$rbw_min      = isset( $rbw_view['min_interval'] ) ? (int) $rbw_view['min_interval'] : 5;

$rbw_unit        = (string) ( $rbw_settings['store_currency_unit'] ?? 'toman' );
$rbw_rounding    = (string) ( $rbw_settings['rounding_mode'] ?? 'nearest' );
$rbw_step        = (int) ( $rbw_settings['rounding_step'] ?? 1000 );
$rbw_interval    = (int) ( $rbw_settings['default_update_interval'] ?? 60 );
$rbw_delete_data = ! empty( $rbw_settings['delete_data_on_uninstall'] );
$rbw_max_age     = (int) ( $rbw_settings['max_rate_age_hours'] ?? 48 );
$rbw_show_orders = ! empty( $rbw_settings['show_rate_in_orders'] );
$rbw_block_on    = ! empty( $rbw_settings['product_block_enabled'] );
$rbw_block_pos   = (string) ( $rbw_settings['product_block_position'] ?? 'after_price' );
$rbw_block_tpl   = (string) ( $rbw_settings['product_block_template'] ?? '' );
$rbw_date_format = (string) ( $rbw_settings['date_format'] ?? 'Y-m-d H:i' );
$rbw_persian     = ! empty( $rbw_settings['use_persian_digits'] );

$rbw_block_positions = array(
	'after_price'       => __( 'After the price', 'ratebridge-woo' ),
	'after_excerpt'     => __( 'After the short description', 'ratebridge-woo' ),
	'after_add_to_cart' => __( 'After the add-to-cart button', 'ratebridge-woo' ),
);

$rbw_placeholders = array( '%name%', '%code%', '%symbol%', '%rate%', '%unit%', '%updated%', '%updated_date%', '%source%' );
$rbw_default_tpl  = ProductNotice::DEFAULT_TEMPLATE;
?>
<form id="rbw-general-form" onsubmit="return false;">
	<table class="form-table rbw-form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="rbw-store-unit"><?php esc_html_e( 'Store currency unit', 'ratebridge-woo' ); ?></label>
				</th>
				<td>
					<fieldset>
						<label class="rbw-inline">
							<input type="radio" name="store_currency_unit" value="toman" <?php checked( $rbw_unit, 'toman' ); ?> />
							<?php esc_html_e( 'Toman', 'ratebridge-woo' ); ?>
						</label>
						<br />
						<label class="rbw-inline">
							<input type="radio" name="store_currency_unit" value="rial" <?php checked( $rbw_unit, 'rial' ); ?> />
							<?php esc_html_e( 'Rial', 'ratebridge-woo' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Rates and fixed amounts (profit, commission, extra amount, minimum price, fluctuation margin) are stored in this unit. Changing it converts every stored amount and recalculates product prices.', 'ratebridge-woo' ); ?></p>
						<p class="rbw-warning rbw-unit-warning" id="rbw-unit-warning" hidden></p>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rbw-rounding-mode"><?php esc_html_e( 'Default rounding mode', 'ratebridge-woo' ); ?></label>
				</th>
				<td>
					<select id="rbw-rounding-mode" name="rounding_mode">
						<option value="none" <?php selected( $rbw_rounding, 'none' ); ?>><?php esc_html_e( 'None', 'ratebridge-woo' ); ?></option>
						<option value="nearest" <?php selected( $rbw_rounding, 'nearest' ); ?>><?php esc_html_e( 'Round to nearest N', 'ratebridge-woo' ); ?></option>
						<option value="ceil" <?php selected( $rbw_rounding, 'ceil' ); ?>><?php esc_html_e( 'Round up (ceil)', 'ratebridge-woo' ); ?></option>
						<option value="floor" <?php selected( $rbw_rounding, 'floor' ); ?>><?php esc_html_e( 'Round down (floor)', 'ratebridge-woo' ); ?></option>
					</select>

					<p>
						<label for="rbw-rounding-step"><?php esc_html_e( 'Rounding step (N)', 'ratebridge-woo' ); ?></label>
						<input
							type="number"
							id="rbw-rounding-step"
							name="rounding_step"
							class="small-text"
							min="1"
							step="1"
							value="<?php echo esc_attr( (string) $rbw_step ); ?>"
						/>
						<span class="description"><?php esc_html_e( 'Examples: 1000, 10000, 100000. Ignored when the mode is "None".', 'ratebridge-woo' ); ?></span>
					</p>

					<p class="description rbw-rounding-preview">
						<?php
						printf(
							/* translators: %s: formatted example number */
							esc_html__( 'Preview (1,234,567): %s', 'ratebridge-woo' ),
							'<code id="rbw-rounding-preview-value">—</code>'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rbw-default-interval"><?php esc_html_e( 'Default update interval (minutes)', 'ratebridge-woo' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="rbw-default-interval"
						name="default_update_interval"
						class="small-text"
						min="<?php echo esc_attr( (string) $rbw_min ); ?>"
						step="1"
						value="<?php echo esc_attr( (string) $rbw_interval ); ?>"
					/>
					<p class="description">
						<?php
						printf(
							/* translators: %d: minimum interval in minutes */
							esc_html__( 'Applied to newly added currencies. Minimum %d minutes because WordPress cron cannot run more often.', 'ratebridge-woo' ),
							(int) $rbw_min
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rbw-max-rate-age"><?php esc_html_e( 'Maximum rate age (hours)', 'ratebridge-woo' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="rbw-max-rate-age"
						name="max_rate_age_hours"
						class="small-text"
						min="0"
						step="1"
						value="<?php echo esc_attr( (string) $rbw_max_age ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'If a rate is older than this, product prices are left unchanged and a warning is shown. Enter 0 to disable the limit. Manual currencies without a timestamp are never considered stale.', 'ratebridge-woo' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rbw-date-format"><?php esc_html_e( 'Date format', 'ratebridge-woo' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="rbw-date-format"
						name="date_format"
						class="regular-text"
						dir="ltr"
						value="<?php echo esc_attr( $rbw_date_format ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Used for absolute dates in the plugin output and in %updated_date%. Letters: Y (year), m (month), d (day), j (day without leading zero), H (hour), i (minute), s (second). Example: Y-m-d H:i', 'ratebridge-woo' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Order display', 'ratebridge-woo' ); ?></th>
				<td>
					<label class="rbw-inline">
						<input type="checkbox" name="show_rate_in_orders" value="1" <?php checked( $rbw_show_orders ); ?> />
						<?php esc_html_e( 'Show the exchange rate to the customer in orders', 'ratebridge-woo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'The rate line is always visible to shop managers on the order screen. Turn this on to also show it on the order-received page, in emails and in My Account.', 'ratebridge-woo' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Display on the site', 'ratebridge-woo' ); ?></th>
				<td>
					<label class="rbw-inline">
						<input type="checkbox" name="product_block_enabled" value="1" <?php checked( $rbw_block_on ); ?> />
						<?php esc_html_e( 'Show a currency info block on product pages', 'ratebridge-woo' ); ?>
					</label>

					<p>
						<label for="rbw-block-position"><?php esc_html_e( 'Position', 'ratebridge-woo' ); ?></label>
						<select id="rbw-block-position" name="product_block_position">
							<?php foreach ( $rbw_block_positions as $rbw_pos_key => $rbw_pos_label ) : ?>
								<option value="<?php echo esc_attr( $rbw_pos_key ); ?>" <?php selected( $rbw_block_pos, $rbw_pos_key ); ?>>
									<?php echo esc_html( $rbw_pos_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>

					<p>
						<label for="rbw-block-template"><?php esc_html_e( 'Template', 'ratebridge-woo' ); ?></label>
						<input
							type="text"
							id="rbw-block-template"
							name="product_block_template"
							class="large-text"
							value="<?php echo esc_attr( $rbw_block_tpl ); ?>"
							placeholder="<?php echo esc_attr( $rbw_default_tpl ); ?>"
						/>
						<span class="description">
							<?php
							printf(
								/* translators: %s: comma separated placeholder list */
								esc_html__( 'Leave empty for the default template. Placeholders: %s', 'ratebridge-woo' ),
								esc_html( implode( ' ', $rbw_placeholders ) )
							);
							?>
						</span>
					</p>

					<p class="description">
						<?php esc_html_e( 'Only products priced by a currency show the block. When the variations of a variable product use different currencies the block is hidden, because one rate would be misleading.', 'ratebridge-woo' ); ?>
					</p>

					<label class="rbw-inline">
						<input type="checkbox" name="use_persian_digits" value="1" <?php checked( $rbw_persian ); ?> />
						<?php esc_html_e( 'Persian digits in the plugin output', 'ratebridge-woo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Applies to the plugin shortcodes and the product block only. WooCommerce prices keep their own formatting.', 'ratebridge-woo' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'ratebridge-woo' ); ?></th>
				<td>
					<label class="rbw-inline">
						<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $rbw_delete_data ); ?> />
						<?php esc_html_e( 'Delete all plugin data (currencies, settings, log) when the plugin is uninstalled', 'ratebridge-woo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Keep this off unless you are sure. Deactivating the plugin never deletes data.', 'ratebridge-woo' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary" id="rbw-save-settings"><?php esc_html_e( 'Save settings', 'ratebridge-woo' ); ?></button>
	</p>
</form>

<p class="rbw-muted">
	<?php
	printf(
		/* translators: 1: currency unit, 2: cron hook name */
		esc_html__( 'Rates are currently stored in %1$s. The cron hook is %2$s.', 'ratebridge-woo' ),
		esc_html( SettingsPage::unit_label() ),
		'<code>rbw_update_rates</code>'
	);
	?>
</p>
