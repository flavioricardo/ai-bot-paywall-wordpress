<?php
/**
 * Removes all plugin data when the owner deletes the plugin.
 *
 * @package AIBotPaywall
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aibotpaywall_daily, {$wpdb->prefix}aibotpaywall_payments" );

foreach ( array( 'aibotpaywall_settings', 'aibotpaywall_db_version', 'aibotpaywall_ip_ranges', 'aibotpaywall_signature_keys', 'aibotpaywall_ranges_updated', 'aibotpaywall_usd_brl', 'aibotpaywall_cache_check', 'aibotpaywall_proxy_seen' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aibotpaywall\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aibotpaywall\\_%'" );
// phpcs:enable

wp_clear_scheduled_hook( 'aibotpaywall_daily_event' );

// Normally already gone: deactivation removes it. Covers a plugin deleted through other means.
require_once __DIR__ . '/includes/class-cache-compat.php';
\AIBotPaywall\Cache_Compat::remove_litespeed_rules();
