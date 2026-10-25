<?php
/**
 * Uninstall routine for RateBridge Woo.
 *
 * Data is only removed when the "delete data on uninstall" setting is enabled.
 *
 * @package RateBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$rbw_settings = get_option( 'rbw_settings', array() );

if ( ! is_array( $rbw_settings ) || empty( $rbw_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Remove the currencies table.
$rbw_table = $wpdb->prefix . 'rbw_currencies';
$wpdb->query( "DROP TABLE IF EXISTS `{$rbw_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

// Remove plugin options.
delete_option( 'rbw_settings' );
delete_option( 'rbw_db_version' );
delete_option( 'rbw_update_log' );

// Remove transients created by providers (rate cache).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_rbw_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_rbw_' ) . '%'
	)
);
