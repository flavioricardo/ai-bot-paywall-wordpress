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

foreach ( array( 'portaria_settings', 'portaria_db_version', 'portaria_ip_ranges', 'portaria_signature_keys', 'portaria_ranges_updated', 'portaria_usd_brl', 'portaria_cache_check', 'portaria_proxy_seen' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_portaria\\_%' OR option_name LIKE '\\_transient\\_timeout\\_portaria\\_%'" );
// phpcs:enable

wp_clear_scheduled_hook( 'portaria_daily_event' );

// Normally already gone: deactivation removes it. Covers a plugin deleted through other means.
require_once __DIR__ . '/includes/class-cache-compat.php';
\Portaria\Cache_Compat::remove_litespeed_rules();
