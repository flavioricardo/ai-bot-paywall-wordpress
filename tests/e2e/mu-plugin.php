<?php
/**
 * Test-only control panel for the E2E suite. Never shipped with the plugin.
 */

add_filter(
	'locale',
	static function ( $locale ) {
		return get_option( 'e2e_locale' ) ?: $locale;
	}
);

// Answers DNS questions without touching the network.
add_filter(
	'aibotpaywall_dns_ptr',
	static function ( $host, $ip ) {
		$map = array(
			'66.249.66.1' => 'crawl-66-249-66-1.googlebot.com',
			'6.6.6.6'     => 'evil.example.com',
			'6.6.6.7'     => 'crawl-fake.googlebot.com',
		);
		return $map[ $ip ] ?? false;
	},
	10,
	2
);
add_filter(
	'aibotpaywall_dns_forward',
	static function ( $addresses, $host ) {
		return 'crawl-66-249-66-1.googlebot.com' === $host ? array( '66.249.66.1' ) : array( '9.9.9.9' );
	},
	10,
	2
);

// A stand-in for a full-page cache that runs before the theme, like advanced-cache.php.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! get_option( 'e2e_fake_cache' ) || isset( $_GET['e2e'] ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$rejected = (array) apply_filters( 'rocket_cache_reject_ua', array() );
		$ua       = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		foreach ( $rejected as $pattern ) {
			if ( preg_match( '#' . $pattern . '#i', $ua ) ) {
				return;
			}
		}
		header( 'X-E2E-Cache: hit' );
		echo '<html>cached page</html>';
		exit;
	},
	999
);

add_action(
	'wp',
	static function () {
		if ( get_option( 'e2e_fatal' ) && ! isset( $_GET['e2e'] ) ) {
			add_action(
				'wp_head',
				static function () {
					throw new RuntimeException( 'e2e fatal' );
				}
			);
		}
	}
);

add_action(
	'init',
	static function () {
		if ( empty( $_GET['e2e'] ) ) {
			return;
		}
		global $wpdb;
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		$out  = array( 'ok' => true );

		switch ( $_GET['e2e'] ) {
			case 'settings':
				update_option( 'aibotpaywall_settings', array_replace( \AIBotPaywall\Settings::get(), (array) $body ), true );
				break;
			case 'option':
				foreach ( (array) $body as $name => $value ) {
					null === $value ? delete_option( $name ) : update_option( $name, $value, false );
				}
				break;
			case 'reset':
				$wpdb->query( "DELETE FROM {$wpdb->prefix}aibotpaywall_daily" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}aibotpaywall_payments" );
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aibotpaywall%' OR option_name LIKE '\\_transient\\_timeout\\_aibotpaywall%'" );
				break;
			case 'dump':
				$out = array(
					'funnel'   => \AIBotPaywall\Store::funnel( 30 ),
					'bots'     => \AIBotPaywall\Store::bots( 30 ),
					'payments' => \AIBotPaywall\Store::recent_payments( 50 ),
					'settings' => \AIBotPaywall\Settings::get(),
					'wp'       => get_bloginfo( 'version' ),
					'php'      => PHP_VERSION,
				);
				break;
			case 'seed':
				wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) );
				$id  = wp_insert_post(
					array(
						'post_title'    => 'Seeded post',
						'post_name'     => 'seeded-post',
						'post_content'  => 'Secret article body',
						'post_status'   => 'publish',
						'post_category' => array( (int) get_cat_ID( 'News' ) ),
					)
				);
				$out = array(
					'id'        => $id,
					'permalink' => get_permalink( $id ),
				);
				break;
			case 'uninstall':
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$path = get_home_path() . '.htaccess';
				file_put_contents( $path, \AIBotPaywall\Cache_Compat::litespeed_block( array( 'GPTBot' ) ) . "# BEGIN WordPress\nkeep\n# END WordPress\n" );
				// LiteSpeed is absent here, so sync removes the block just as deactivation must.
				deactivate_plugins( 'ai-bot-paywall/ai-bot-paywall.php' );
				$after = (string) file_get_contents( $path );
				$out   = array(
					'htaccess_block_after_deactivate' => false !== strpos( $after, 'BEGIN AI Bot Paywall' ),
					'user_rules_kept'                 => false !== strpos( $after, 'keep' ),
				);
				if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
					define( 'WP_UNINSTALL_PLUGIN', 'ai-bot-paywall/ai-bot-paywall.php' );
				}
				include WP_PLUGIN_DIR . '/ai-bot-paywall/uninstall.php';
				wp_cache_flush();
				$out['tables']   = array_values( array_filter( array_map( static function ( $t ) { return $t; }, (array) $wpdb->get_col( "SHOW TABLES LIKE '%aibotpaywall%'" ) ) ) );
				$out['settings'] = get_option( 'aibotpaywall_settings' );
				$out['cron']     = wp_next_scheduled( 'aibotpaywall_daily_event' );
				break;
			case 'selftest':
				$out = \AIBotPaywall\Cache_Compat::run_self_test();
				break;
			case 'rocket_ua':
				$out = array( 'agents' => apply_filters( 'rocket_cache_reject_ua', array() ) );
				break;
			case 'refresh':
				\AIBotPaywall\Identity::refresh_ranges();
				\AIBotPaywall\Identity::refresh_signature_keys();
				\AIBotPaywall\Rates::refresh();
				$out = array(
					'ranges' => array_map( 'count', (array) get_option( 'aibotpaywall_ip_ranges', array() ) ),
					'keys'   => array_map( 'count', (array) get_option( 'aibotpaywall_signature_keys', array() ) ),
					'rate'   => \AIBotPaywall\Rates::usd_brl(),
				);
				break;
			case 'facilitator':
				$out = \AIBotPaywall\Facilitator::check( \AIBotPaywall\Settings::network() );
				break;
		}

		header( 'Content-Type: application/json' );
		echo wp_json_encode( $out );
		exit;
	},
	1
);
