<?php
/**
 * Removes all plugin data when the owner deletes the plugin.
 *
 * @package Botfare
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}botfare_daily, {$wpdb->prefix}botfare_payments" );

foreach ( array( 'botfare_settings', 'botfare_db_version', 'botfare_ip_ranges', 'botfare_signature_keys', 'botfare_ranges_updated', 'botfare_usd_brl', 'botfare_cache_check', 'botfare_proxy_seen' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_botfare\\_%' OR option_name LIKE '\\_transient\\_timeout\\_botfare\\_%'" );
// phpcs:enable

wp_clear_scheduled_hook( 'botfare_daily_event' );

// Normally already gone: deactivation removes it. Covers a plugin deleted through other means.
require_once __DIR__ . '/includes/class-cache-compat.php';
\Botfare\Cache_Compat::remove_litespeed_rules();
