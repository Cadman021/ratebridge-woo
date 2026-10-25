<?php
/**
 * Admin page layout.
 *
 * @var array<string, mixed> $rbw_view View data prepared by SettingsPage.
 *
 * @package RateBridge
 */

defined( 'ABSPATH' ) || exit;

$rbw_active_tab = isset( $rbw_view['active_tab'] ) ? (string) $rbw_view['active_tab'] : 'currencies';
$rbw_tabs       = array(
	'currencies' => __( 'Currencies', 'ratebridge-woo' ),
	'sources'    => __( 'Price sources', 'ratebridge-woo' ),
	'general'    => __( 'General settings', 'ratebridge-woo' ),
);
?>
<div class="wrap rbw-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Currency Prices', 'ratebridge-woo' ); ?></h1>
	<hr class="wp-header-end" />

	<div id="rbw-notices" class="rbw-notices" role="status" aria-live="polite"></div>

	<nav class="nav-tab-wrapper rbw-tabs">
		<?php foreach ( $rbw_tabs as $rbw_tab_key => $rbw_tab_label ) : ?>
			<a
				href="<?php echo esc_url( add_query_arg( array( 'page' => 'ratebridge-woo', 'tab' => $rbw_tab_key ), admin_url( 'admin.php' ) ) ); ?>"
				class="nav-tab <?php echo $rbw_active_tab === $rbw_tab_key ? 'nav-tab-active' : ''; ?>"
				data-rbw-tab="<?php echo esc_attr( $rbw_tab_key ); ?>"
			>
				<?php echo esc_html( $rbw_tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="rbw-tab-panel" id="rbw-panel-currencies">
		<?php if ( 'currencies' === $rbw_active_tab ) : ?>
			<?php require RBW_PLUGIN_DIR . 'templates/tab-currencies.php'; ?>
		<?php endif; ?>
	</div>

	<div class="rbw-tab-panel" id="rbw-panel-sources">
		<?php if ( 'sources' === $rbw_active_tab ) : ?>
			<?php require RBW_PLUGIN_DIR . 'templates/tab-sources.php'; ?>
		<?php endif; ?>
	</div>

	<div class="rbw-tab-panel" id="rbw-panel-general">
		<?php if ( 'general' === $rbw_active_tab ) : ?>
			<?php require RBW_PLUGIN_DIR . 'templates/tab-general.php'; ?>
		<?php endif; ?>
	</div>
</div>
