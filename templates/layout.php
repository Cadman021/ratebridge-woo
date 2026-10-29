<?php
/**
 * Admin page layout.
 *
 * @var array<string, mixed> $rbw_view View data prepared by SettingsPage.
 *
 * @package RateBridge
 */

use RateBridge\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

$rbw_active_tab = isset( $rbw_view['active_tab'] ) ? (string) $rbw_view['active_tab'] : 'overview';
$rbw_health     = isset( $rbw_view['health'] ) && is_array( $rbw_view['health'] ) ? $rbw_view['health'] : array(
	'level'   => 'ok',
	'label'   => __( 'Everything is fine', 'ratebridge-woo' ),
	'details' => '',
);

/*
 * Tab icons: every tab needs its own recognisable icon. Sharing one glyph
 * between two tabs (the old `filter` on both General and the header button)
 * made the strip read as decoration rather than navigation.
 */
$rbw_tabs = array(
	'overview'      => array(
		'label' => __( 'Overview', 'ratebridge-woo' ),
		'icon'  => 'chart',
	),
	'currencies'    => array(
		'label' => __( 'Currencies', 'ratebridge-woo' ),
		'icon'  => 'coin',
	),
	'formulas'      => array(
		'label' => __( 'Formulas', 'ratebridge-woo' ),
		'icon'  => 'formula',
	),
	'sources'       => array(
		'label' => __( 'Price sources', 'ratebridge-woo' ),
		'icon'  => 'link',
	),
	'general'       => array(
		'label' => __( 'General settings', 'ratebridge-woo' ),
		'icon'  => 'gear',
	),
	'import-export' => array(
		'label' => __( 'Import / Export', 'ratebridge-woo' ),
		'icon'  => 'swap',
	),
);

$rbw_tab_url = static function ( string $tab ): string {
	return add_query_arg(
		array(
			'page' => SettingsPage::SLUG,
			'tab'  => $tab,
		),
		admin_url( 'admin.php' )
	);
};
?>
<?php require RBW_PLUGIN_DIR . 'templates/icon-sprite.php'; ?>

<div class="wrap rbw-wrap rbw-admin rbw-typography">
	<header class="rbw-header">
		<div class="rbw-header-main">
			<h1 class="rbw-title">
				<?php esc_html_e( 'RateBridge', 'ratebridge-woo' ); ?>
				<span class="rbw-version"><?php echo esc_html( 'v' . RBW_VERSION ); ?></span>
			</h1>

			<span class="rbw-health is-<?php echo esc_attr( (string) $rbw_health['level'] ); ?>"
				title="<?php echo esc_attr( (string) ( $rbw_health['details'] ?? '' ) ); ?>">
				<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-<?php echo esc_attr( 'ok' === $rbw_health['level'] ? 'check' : ( 'warn' === $rbw_health['level'] ? 'warning' : 'error' ) ); ?>"></use></svg>
				<?php echo esc_html( (string) $rbw_health['label'] ); ?>
			</span>
		</div>

		<div class="rbw-header-actions">
			<?php
			/*
			 * «به‌روزرسانی همه» lives here and ONLY here. It used to be duplicated in
			 * the currencies toolbar, which meant two buttons with one job and two
			 * separate JS bindings. The toolbar one was removed; #rbw-update-all is
			 * kept as the id so the existing handler and its AJAX contract are
			 * untouched.
			 */
			?>
			<button type="button" class="button" id="rbw-update-all">
				<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-refresh"></use></svg>
				<?php esc_html_e( 'Update all now', 'ratebridge-woo' ); ?>
			</button>
			<?php if ( 'general' !== $rbw_active_tab ) : ?>
				<a class="button" href="<?php echo esc_url( $rbw_tab_url( 'general' ) ); ?>">
					<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-gear"></use></svg>
					<?php esc_html_e( 'General settings', 'ratebridge-woo' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</header>

	<div id="rbw-notices" class="rbw-notices" role="status" aria-live="polite"></div>

	<nav class="rbw-tabs" role="tablist" aria-label="<?php esc_attr_e( 'RateBridge sections', 'ratebridge-woo' ); ?>">
		<?php foreach ( $rbw_tabs as $rbw_tab_key => $rbw_tab ) : ?>
			<?php $rbw_is_active = $rbw_active_tab === $rbw_tab_key; ?>
			<a
				class="rbw-tab<?php echo $rbw_is_active ? ' is-active' : ''; ?>"
				id="rbw-tab-<?php echo esc_attr( $rbw_tab_key ); ?>"
				href="<?php echo esc_url( $rbw_tab_url( $rbw_tab_key ) ); ?>"
				role="tab"
				aria-selected="<?php echo $rbw_is_active ? 'true' : 'false'; ?>"
				aria-controls="rbw-panel-<?php echo esc_attr( $rbw_tab_key ); ?>"
				tabindex="<?php echo $rbw_is_active ? '0' : '-1'; ?>"
				data-rbw-tab="<?php echo esc_attr( $rbw_tab_key ); ?>"
			>
				<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-<?php echo esc_attr( $rbw_tab['icon'] ); ?>"></use></svg>
				<span><?php echo esc_html( $rbw_tab['label'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	$rbw_panels = array(
		'overview'      => 'tab-overview.php',
		'currencies'    => 'tab-currencies.php',
		'formulas'      => 'tab-formulas.php',
		'import-export' => 'tab-import-export.php',
		'sources'       => 'tab-sources.php',
		'general'       => 'tab-general.php',
	);

	foreach ( $rbw_panels as $rbw_panel_key => $rbw_panel_file ) :
		$rbw_is_active = $rbw_active_tab === $rbw_panel_key;
		?>
		<div
			class="rbw-panel"
			id="rbw-panel-<?php echo esc_attr( $rbw_panel_key ); ?>"
			role="tabpanel"
			aria-labelledby="rbw-tab-<?php echo esc_attr( $rbw_panel_key ); ?>"
			<?php echo $rbw_is_active ? '' : 'hidden'; ?>
		>
			<?php if ( $rbw_is_active ) : ?>
				<?php require RBW_PLUGIN_DIR . 'templates/' . $rbw_panel_file; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<!-- Accessible confirm dialog (replaces window.confirm) -->
	<div class="rbw-modal" id="rbw-confirm" hidden>
		<div class="rbw-modal-backdrop"></div>
		<div class="rbw-modal-content" role="alertdialog" aria-modal="true" aria-labelledby="rbw-confirm-message">
			<h2 class="rbw-modal-head" id="rbw-confirm-title"><?php esc_html_e( 'Please confirm', 'ratebridge-woo' ); ?></h2>
			<div class="rbw-modal-body">
				<p id="rbw-confirm-message"></p>
			</div>
			<p class="rbw-modal-actions">
				<button type="button" class="button button-primary" id="rbw-confirm-ok"><?php esc_html_e( 'OK', 'ratebridge-woo' ); ?></button>
				<button type="button" class="button" id="rbw-confirm-cancel"><?php esc_html_e( 'Cancel', 'ratebridge-woo' ); ?></button>
			</p>
		</div>
	</div>
</div>
