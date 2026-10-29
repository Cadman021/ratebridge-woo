<?php
/**
 * Import / Export tab.
 *
 * @var array<string, mixed> $rbw_view View data.
 *
 * @package RateBridge
 */

use RateBridge\Core\Settings;
use RateBridge\ImportExport\AdminController;
use RateBridge\ImportExport\Importer;
use RateBridge\ImportExport\ImportPlan;
use RateBridge\ImportExport\Package;

defined( 'ABSPATH' ) || exit;

$rbw_settings  = isset( $rbw_view['settings'] ) && is_array( $rbw_view['settings'] ) ? $rbw_view['settings'] : array();
$rbw_unit      = 'rial' === Settings::store_unit() ? __( 'Rial', 'ratebridge-woo' ) : __( 'Toman', 'ratebridge-woo' );
$rbw_sections  = array(
	'settings'   => __( 'Settings', 'ratebridge-woo' ),
	'currencies' => __( 'Currencies', 'ratebridge-woo' ),
	'formulas'   => __( 'Formulas', 'ratebridge-woo' ),
);
$rbw_backups   = ( new Importer() )->backup_summaries();
$rbw_pkg_url   = AdminController::package_url();
$rbw_csv_url   = AdminController::products_url();
?>

<div id="rbw-notices" class="rbw-notices" role="status" aria-live="polite"></div>

<?php
/*
 * Shared stepper markup for both import cards. The implementation is driven by
 * admin.js: step 1 collects the file, step 2 shows the dry-run preview, step 3
 * executes. The native <input type="file"> stays in the DOM (visually hidden)
 * so keyboard and mobile users keep the OS picker — and so no English
 * «Choose File / No file chosen» text can ever appear in the Persian UI.
 *
 * @param string $id     Unique prefix for ids.
 * @param string $accept Accept attribute for the file input.
 * @param string $label  Visible drop-zone label.
 * @param string $hint   Small hint under the label.
 * @return void
 */
$rbw_stepper = static function ( string $id, string $accept, string $label, string $hint ): void {
	?>
	<ol class="rbw-steps" aria-label="<?php esc_attr_e( 'Import steps', 'ratebridge-woo' ); ?>">
		<li class="rbw-step is-active" data-step="1">
			<span class="rbw-step-num" aria-hidden="true">۱</span>
			<span class="rbw-step-label"><?php esc_html_e( 'Choose a file', 'ratebridge-woo' ); ?></span>
		</li>
		<li class="rbw-step" data-step="2">
			<span class="rbw-step-num" aria-hidden="true">۲</span>
			<span class="rbw-step-label"><?php esc_html_e( 'Preview', 'ratebridge-woo' ); ?></span>
		</li>
		<li class="rbw-step" data-step="3">
			<span class="rbw-step-num" aria-hidden="true">۳</span>
			<span class="rbw-step-label"><?php esc_html_e( 'Run', 'ratebridge-woo' ); ?></span>
		</li>
	</ol>

	<div class="rbw-dropzone" data-dropzone="<?php echo esc_attr( $id ); ?>" tabindex="0" role="button">
		<svg class="rbw-icon rbw-dropzone-icon" aria-hidden="true"><use href="#rbw-i-upload"></use></svg>
		<span class="rbw-dropzone-label"><?php echo esc_html( $label ); ?></span>
		<span class="rbw-dropzone-hint"><?php echo esc_html( $hint ); ?></span>

		<input
			type="file"
			id="<?php echo esc_attr( $id ); ?>-file"
			name="file"
			class="rbw-file-input"
			accept="<?php echo esc_attr( $accept ); ?>"
		/>
	</div>

	<p class="rbw-file-chosen" data-file-chosen="<?php echo esc_attr( $id ); ?>" hidden></p>
	<?php
};
?>

<!-- Card 1: package -->
<div class="rbw-card rbw-io-card">
	<h3><?php esc_html_e( 'Settings, currencies and formulas (JSON package)', 'ratebridge-woo' ); ?></h3>

	<p class="description">
		<?php esc_html_e( 'Configuration only — no API keys, rates, counters, logs or caches.', 'ratebridge-woo' ); ?>
	</p>

	<form id="rbw-package-export-form" onsubmit="return false;">
		<fieldset class="rbw-io-fieldset">
			<legend class="rbw-io-legend"><?php esc_html_e( 'Sections to export', 'ratebridge-woo' ); ?></legend>
			<?php foreach ( $rbw_sections as $rbw_key => $rbw_label ) : ?>
				<label class="rbw-inline">
					<input type="checkbox" name="sections[]" value="<?php echo esc_attr( $rbw_key ); ?>" checked />
					<?php echo esc_html( $rbw_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<p>
			<button
				type="button"
				class="button button-primary"
				id="rbw-export-package"
				data-base-url="<?php echo esc_url( $rbw_pkg_url ); ?>"
			>
				<?php esc_html_e( 'Download package', 'ratebridge-woo' ); ?>
			</button>
		</p>
	</form>

	<hr />

	<h4><?php esc_html_e( 'Import a package', 'ratebridge-woo' ); ?></h4>

	<form id="rbw-package-import-form" onsubmit="return false;" enctype="multipart/form-data">
		<?php $rbw_stepper( 'rbw-package', '.json,application/json', __( 'Drop the JSON package here, or choose a file', 'ratebridge-woo' ), __( 'JSON, up to 2 MB', 'ratebridge-woo' ) ); ?>

		<p>
			<label for="rbw-package-policy"><?php esc_html_e( 'If an item already exists', 'ratebridge-woo' ); ?></label>
			<select id="rbw-package-policy" name="policy">
				<option value="<?php echo esc_attr( ImportPlan::POLICY_SKIP ); ?>" selected>
					<?php esc_html_e( 'Skip (keep existing)', 'ratebridge-woo' ); ?>
				</option>
				<option value="<?php echo esc_attr( ImportPlan::POLICY_UPDATE ); ?>">
					<?php esc_html_e( 'Update the existing item', 'ratebridge-woo' ); ?>
				</option>
			</select>
		</p>

		<p>
			<label for="rbw-package-convert"><?php esc_html_e( 'Store unit', 'ratebridge-woo' ); ?></label>
			<select id="rbw-package-convert" name="convert">
				<option value="1" selected><?php esc_html_e( 'Convert to the site unit', 'ratebridge-woo' ); ?></option>
				<option value="0"><?php esc_html_e( 'Import as-is', 'ratebridge-woo' ); ?></option>
			</select>
			<span class="description">
				<?php
				printf(
					/* translators: %s: unit label */
					esc_html__( 'Percent values are never converted. The site unit is %s.', 'ratebridge-woo' ),
					esc_html( $rbw_unit )
				);
				?>
			</span>
		</p>

		<p class="rbw-step-actions">
			<button type="button" class="button" id="rbw-package-dry-run" disabled>
				<?php esc_html_e( 'Check the file (dry run)', 'ratebridge-woo' ); ?>
			</button>
			<button type="button" class="button button-primary" id="rbw-package-execute" disabled>
				<?php esc_html_e( 'Import now', 'ratebridge-woo' ); ?>
			</button>
		</p>
	</form>

	<div class="rbw-io-report" id="rbw-package-report" hidden></div>
</div>

<!-- Card 2: products CSV -->
<div class="rbw-card rbw-io-card">
	<h3><?php esc_html_e( 'Product pricing (CSV)', 'ratebridge-woo' ); ?></h3>

	<p class="description">
		<?php esc_html_e( 'One row per product priced by the plugin. Columns: id, parent_id, sku, name, type, mode, currency, base_price, sale_base_price, min_price, fluctuation, profit_mode, profit_type, profit_value, commission_type, commission_value, extra_amount, formula_slug, formula_vars.', 'ratebridge-woo' ); ?>
	</p>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( $rbw_csv_url ); ?>" id="rbw-export-products">
			<?php esc_html_e( 'Download products CSV', 'ratebridge-woo' ); ?>
		</a>
	</p>

	<hr />

	<h4><?php esc_html_e( 'Import a products CSV', 'ratebridge-woo' ); ?></h4>

	<p class="description">
		<?php esc_html_e( 'Rows are matched by id, then by SKU; products are never created. Only the fields present in the file are applied, in chunks, and prices are recalculated in the background.', 'ratebridge-woo' ); ?>
	</p>

	<form id="rbw-csv-import-form" onsubmit="return false;" enctype="multipart/form-data">
		<?php $rbw_stepper( 'rbw-csv', '.csv,text/csv', __( 'Drop the CSV here, or choose a file', 'ratebridge-woo' ), __( 'CSV, up to 5 MB / 20,000 rows', 'ratebridge-woo' ) ); ?>

		<p>
			<label for="rbw-csv-unit"><?php esc_html_e( 'Money unit in the file', 'ratebridge-woo' ); ?></label>
			<select id="rbw-csv-unit" name="file_unit">
				<option value="toman" <?php selected( Settings::store_unit(), 'toman' ); ?>><?php esc_html_e( 'Toman', 'ratebridge-woo' ); ?></option>
				<option value="rial" <?php selected( Settings::store_unit(), 'rial' ); ?>><?php esc_html_e( 'Rial', 'ratebridge-woo' ); ?></option>
			</select>
			<span class="description">
				<?php
				printf(
					/* translators: %s: unit label */
					esc_html__( 'Amounts are converted when this differs from the site unit (%s). Percentages are never converted.', 'ratebridge-woo' ),
					esc_html( $rbw_unit )
				);
				?>
			</span>
		</p>

		<p class="rbw-step-actions">
			<button type="button" class="button" id="rbw-csv-upload" disabled>
				<?php esc_html_e( 'Read the file (dry run)', 'ratebridge-woo' ); ?>
			</button>
			<button type="button" class="button button-primary" id="rbw-csv-execute" disabled>
				<?php esc_html_e( 'Start import', 'ratebridge-woo' ); ?>
			</button>
		</p>

		<div class="rbw-progress" id="rbw-csv-progress" hidden>
			<div class="rbw-progress-bar"><span class="rbw-progress-fill" id="rbw-csv-progress-fill"></span></div>
			<span class="rbw-progress-label" id="rbw-csv-progress-label">0%</span>
		</div>
	</form>

	<div class="rbw-io-report" id="rbw-csv-report" hidden></div>
</div>

<!-- Card 3: backups -->
<div class="rbw-card rbw-io-card" id="rbw-backups-card">
	<h3><?php esc_html_e( 'Backups', 'ratebridge-woo' ); ?></h3>

	<p class="description">
		<?php
		printf(
			/* translators: %d: number of backups kept */
			esc_html__( 'A backup is created automatically before every import or restore. The last %d are kept.', 'ratebridge-woo' ),
			(int) Importer::MAX_BACKUPS
		);
		?>
	</p>

	<table class="widefat striped" id="rbw-backups-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'ratebridge-woo' ); ?></th>
				<th><?php esc_html_e( 'Reason', 'ratebridge-woo' ); ?></th>
				<th><?php esc_html_e( 'Currencies', 'ratebridge-woo' ); ?></th>
				<th><?php esc_html_e( 'Formulas', 'ratebridge-woo' ); ?></th>
				<th class="rbw-col-actions"><?php esc_html_e( 'Actions', 'ratebridge-woo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rbw_backups ) ) : ?>
				<tr class="rbw-empty-row"><td colspan="5"><?php esc_html_e( 'No backups yet.', 'ratebridge-woo' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rbw_backups as $rbw_backup ) : ?>
					<tr data-backup-id="<?php echo esc_attr( (string) ( $rbw_backup['id'] ?? '' ) ); ?>">
						<td><?php echo esc_html( (string) ( $rbw_backup['date'] ?? '—' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $rbw_backup['reason'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $rbw_backup['counts']['currencies'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $rbw_backup['counts']['formulas'] ?? 0 ) ); ?></td>
						<td class="rbw-actions">
							<button type="button" class="button button-small rbw-restore-backup" data-id="<?php echo esc_attr( (string) ( $rbw_backup['id'] ?? '' ) ); ?>">
								<?php esc_html_e( 'Restore', 'ratebridge-woo' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<p class="rbw-muted">
		<?php esc_html_e( 'Restoring replaces the current settings, currencies and formulas with the backup, then recalculates prices. The current state is backed up first, so a restore can be undone.', 'ratebridge-woo' ); ?>
	</p>
</div>

<p class="rbw-muted">
	<?php
	printf(
		/* translators: %d: schema version */
		esc_html__( 'Package format: ratebridge-woo-package, schema %d. Downloads are UTF-8; the CSV starts with a BOM so Excel opens it correctly.', 'ratebridge-woo' ),
		(int) Package::SCHEMA
	);
	?>
</p>
