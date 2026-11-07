<?php
/**
 * Plugin Name:       Portaria: AI Bot Ledger and x402 Paywall
 * Plugin URI:        https://github.com/flavioricardo/portaria-bots
 * Description:       See every bot that reads your site, tell real crawlers from fake ones, and charge the ones that can pay through the x402 protocol. Starts in Monitor mode: nothing changes until you decide.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Flávio Ricardo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       portaria-bots
 * Domain Path:       /languages
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

foreach ( array( 'catalog', 'net', 'rules', 'x402', 'web-bot-auth', 'settings', 'store', 'identity', 'facilitator', 'rates', 'payment', 'gate', 'cache-compat', 'admin', 'admin-actions' ) as $portaria_class ) {
	require_once __DIR__ . '/includes/class-' . $portaria_class . '.php';
}
unset( $portaria_class );

const CRON_HOOK = 'portaria_daily_event';

/** Sets up tables, defaults and scheduled work. Never changes how the site responds. */
function activate(): void {
	Store::maybe_upgrade();
	Settings::ensure_defaults();
	if ( ! wp_next_scheduled( CRON_HOOK ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', CRON_HOOK );
	}
	// Fetch verification data soon, without slowing activation down.
	wp_schedule_single_event( time() + 10, CRON_HOOK );
	Cache_Compat::sync();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/** Stops scheduled work and removes cache rules. Data stays until uninstall. */
function deactivate(): void {
	wp_clear_scheduled_hook( CRON_HOOK );
	Cache_Compat::remove_all();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/** Daily upkeep: verification data, exchange rate, retention, cache check. */
function daily(): void {
	Identity::refresh_ranges();
	Identity::refresh_signature_keys();
	Rates::refresh();
	Store::prune( (int) Settings::get()['retention_days'] );
	Cache_Compat::run_self_test();
}
add_action( CRON_HOOK, __NAMESPACE__ . '\\daily' );

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'portaria-bots', false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}
);

Gate::init();
Cache_Compat::init();

if ( is_admin() ) {
	Admin::init();
	Admin_Actions::init();
}
