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

<!-- Card 1: package -->
<div class="rbw-card rbw-io-card">
	<h3><?php esc_html_e( 'Settings, currencies and formulas (JSON package)', 'ratebridge-woo' ); ?></h3>

	<p class="description">
		<?php esc_html_e( 'Exports the plugin configuration only. API keys, tokens, request counters, logs and caches are never included. Rates are not exported: imported currencies wait for their first update.', 'ratebridge-woo' ); ?>
	</p>

	<form id="rbw-package-export-form" onsubmit="return false;">
		<fieldset class="rbw-io-fieldset">
			<legend><?php esc_html_e( 'Sections to export', 'ratebridge-woo' ); ?></legend>
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
		<p>
			<label for="rbw-package-file"><?php esc_html_e( 'JSON file (max 2 MB)', 'ratebridge-woo' ); ?></label>
			<input type="file" id="rbw-package-file" name="file" accept=".json,application/json" />
		</p>

		<p>
			<label for="rbw-package-policy"><?php esc_html_e( 'If an item already exists', 'ratebridge-woo' ); ?></label>
			<select id="rbw-package-policy" name="policy">
				<option value="<?php echo esc_attr( ImportPlan::POLICY_SKIP ); ?>" selected>
					<?php esc_html_e( 'Keep the existing item (skip)', 'ratebridge-woo' ); ?>
				</option>
				<option value="<?php echo esc_attr( ImportPlan::POLICY_UPDATE ); ?>">
					<?php esc_html_e( 'Update the existing item', 'ratebridge-woo' ); ?>
				</option>
			</select>
		</p>

		<p>
			<label for="rbw-package-convert"><?php esc_html_e( 'Store unit', 'ratebridge-woo' ); ?></label>
			<select id="rbw-package-convert" name="convert">
				<option value="1" selected><?php esc_html_e( 'Convert money amounts to the site unit', 'ratebridge-woo' ); ?></option>
				<option value="0"><?php esc_html_e( 'Do not convert (import as is)', 'ratebridge-woo' ); ?></option>
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

		<p>
			<button type="button" class="button" id="rbw-package-dry-run">
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
		<?php esc_html_e( 'One row per product that is priced by the plugin. Columns: id, parent_id, sku, name, type, mode, currency, base_price, sale_base_price, min_price, fluctuation, profit_mode, profit_type, profit_value, commission_type, commission_value, extra_amount, formula_slug, formula_vars.', 'ratebridge-woo' ); ?>
	</p>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( $rbw_csv_url ); ?>" id="rbw-export-products">
			<?php esc_html_e( 'Download products CSV', 'ratebridge-woo' ); ?>
		</a>
	</p>

	<hr />

	<h4><?php esc_html_e( 'Import a products CSV', 'ratebridge-woo' ); ?></h4>

	<p class="description">
		<?php esc_html_e( 'The importer matches rows by id, then by SKU. It never creates products. Only the fields present in the file are applied, in chunks, and prices are recalculated in the background.', 'ratebridge-woo' ); ?>
	</p>

	<form id="rbw-csv-import-form" onsubmit="return false;" enctype="multipart/form-data">
		<p>
			<label for="rbw-csv-file"><?php esc_html_e( 'CSV file (max 5 MB, 20,000 rows)', 'ratebridge-woo' ); ?></label>
			<input type="file" id="rbw-csv-file" name="file" accept=".csv,text/csv" />
		</p>

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

		<p>
			<button type="button" class="button" id="rbw-csv-upload">
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
