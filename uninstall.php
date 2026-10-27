<?php
/**
 * Uninstall routine for RateBridge Woo.
 *
 * Data is only removed when the "delete data on uninstall" setting is enabled.
 *
 * The final WooCommerce prices are intentionally left untouched: a product
 * keeps whatever price the plugin last calculated, so the shop never ends up
 * with empty prices after the plugin is removed.
 *
 * @package RateBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$rbw_settings = get_option( 'rbw_settings', array() );

if ( ! is_array( $rbw_settings ) || empty( $rbw_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Remove the currencies and formulas tables.
$rbw_table = $wpdb->prefix . 'rbw_currencies';
$wpdb->query( "DROP TABLE IF EXISTS `{$rbw_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

$rbw_formulas_table = $wpdb->prefix . 'rbw_formulas';
$wpdb->query( "DROP TABLE IF EXISTS `{$rbw_formulas_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

// Remove plugin options.
delete_option( 'rbw_settings' );
delete_option( 'rbw_db_version' );
delete_option( 'rbw_update_log' );
delete_option( 'rbw_provider_settings' );
delete_option( 'rbw_request_budget' );
delete_option( 'rbw_reprice_queue' );
delete_option( 'rbw_unit_migration' );

// Remove transients created by providers (rate cache) and the Phase-4
// rendered frontend fragments (prefix `rbw_html_`).
//
// The new settings (order notice, product block, date format, Persian digits)
// live inside the `rbw_settings` option, which is deleted above.
//
// Order item meta (`_rbw_*` on order items) is intentionally NOT removed: an
// order is financial history and must stay auditable after the plugin is gone.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_rbw_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_rbw_' ) . '%'
	)
);

/*
 * Remove every `_rbw_*` product/variation meta row.
 *
 * This is a direct query on purpose: the list lives in ProductData::all_meta_keys(),
 * but that class may not be loadable during uninstall (no autoloader is
 * registered), and deleting thousands of rows one by one would time out on a
 * large catalogue. Product prices in `_regular_price` / `_sale_price` are NOT
 * touched.
 */
$rbw_meta_keys = array(
	'_rbw_enabled',
	'_rbw_currency',
	'_rbw_base_price',
	'_rbw_sale_base_price',
	'_rbw_min_price',
	'_rbw_fluctuation',
	'_rbw_profit_mode',
	'_rbw_profit_type',
	'_rbw_profit_value',
	'_rbw_commission_type',
	'_rbw_commission_value',
	'_rbw_extra_amount',
	'_rbw_last_price',
	'_rbw_last_calc',
	'_rbw_last_rate',
	'_rbw_last_error',
	'_rbw_mode',
	'_rbw_formula_id',
	'_rbw_formula_vars',
);

$rbw_placeholders = implode( ', ', array_fill( 0, count( $rbw_meta_keys ), '%s' ) );

$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$rbw_placeholders})",
		$rbw_meta_keys
	)
);

// Per-user notice dismissals.
$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
		'rbw_dismiss_stale_notice'
	)
);
