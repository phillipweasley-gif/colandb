<?php
/**
 * Runs only when the plugin is deleted (never on deactivation). Member data
 * is removed only if an administrator explicitly enabled "Permanently delete
 * all member data" under Settings → Member Planning.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cmp_settings = get_option( 'cmp_settings', array() );
if ( empty( $cmp_settings['delete_data_on_delete'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'audit_log', 'notifications' ) as $cmp_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cmp_' . $cmp_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
}
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'cmp\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'cmp_settings' );
delete_option( 'cmp_db_version' );
