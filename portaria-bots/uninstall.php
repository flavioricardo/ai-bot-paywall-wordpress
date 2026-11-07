<?php
/**
 * Removes all plugin data when the owner deletes the plugin.
 *
 * @package Portaria
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}portaria_daily, {$wpdb->prefix}portaria_payments" );

foreach ( array( 'portaria_settings', 'portaria_db_version', 'portaria_ip_ranges', 'portaria_signature_keys', 'portaria_ranges_updated', 'portaria_usd_brl', 'portaria_cache_check' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_portaria\\_%' OR option_name LIKE '\\_transient\\_timeout\\_portaria\\_%'" );
// phpcs:enable

wp_clear_scheduled_hook( 'portaria_daily_event' );

// Normally already gone: deactivation removes it. Covers a plugin deleted through other means.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
$portaria_htaccess = get_home_path() . '.htaccess';
if ( file_exists( $portaria_htaccess ) && wp_is_writable( $portaria_htaccess ) ) {
	$portaria_current = (string) file_get_contents( $portaria_htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$portaria_updated = (string) preg_replace( '/^# BEGIN Portaria\R.*?^# END Portaria\R?/ms', '', $portaria_current );
	if ( $portaria_updated !== $portaria_current ) {
		file_put_contents( $portaria_htaccess, $portaria_updated, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
