<?php
/**
 * Admin screens: dashboard, rules, settings and diagnostics.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'portaria';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'on_admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function on_admin_init(): void {
		Store::maybe_upgrade();
		// Network activation runs only for the main site: finish setup on each site.
		if ( ! wp_next_scheduled( CRON_HOOK ) ) {
			activate();
		}
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Portaria',
				'<p>' . esc_html__( 'Portaria counts automated visits per day. It does not store IP addresses or User-Agent strings, and it does not record human visitors. When a client pays through x402, its public wallet address and transaction hash are stored as a payment record.', 'portaria-bots' ) . '</p>'
			);
		}
	}

	public static function menu(): void {
		add_menu_page(
			'Portaria',
			'Portaria',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-shield-alt',
			81
		);
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'portaria-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
		}
	}

	/**
	 * @param string[] $links Plugin action links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Dashboard', 'portaria-bots' ) . '</a>' );
		return $links;
	}

	public static function url( string $tab = 'dashboard' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab );
	}

	/** @return array<string,string> Tab key to label. */
	private static function tabs(): array {
		return array(
			'dashboard'   => __( 'Dashboard', 'portaria-bots' ),
			'rules'       => __( 'Rules', 'portaria-bots' ),
			'settings'    => __( 'Settings', 'portaria-bots' ),
			'diagnostics' => __( 'Diagnostics', 'portaria-bots' ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- Only selects which tab to show.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}

		echo '<div class="wrap portaria"><h1>Portaria</h1>';
		Admin_Actions::print_notices();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		call_user_func( array( __CLASS__, 'render_' . $tab ) );
		echo '</div>';
	}

	/** Formats atomic USDC, adding reais when the owner wants them. */
	public static function money( string $atomic ): string {
		$amount   = Rules::from_atomic( $atomic );
		$decimals = ( 0 !== (int) $atomic % 10000 ) ? 6 : 2;
		$text     = 'US$ ' . number_format_i18n( $amount, $decimals );

		$rate = Rates::usd_brl();
		if ( null !== $rate && ! empty( Settings::get()['show_brl'] ) ) {
			$text .= ' (R$ ' . number_format_i18n( $amount * $rate, 2 ) . ')';
		}
		return $text;
	}

	private static function class_label( string $class ): string {
		$labels = array(
			'search'      => __( 'Search engines', 'portaria-bots' ),
			'social'      => __( 'Link previews', 'portaria-bots' ),
			'service'     => __( 'Site services', 'portaria-bots' ),
			'ai_training' => __( 'AI training crawlers', 'portaria-bots' ),
			'ai_search'   => __( 'AI search crawlers', 'portaria-bots' ),
			'ai_agent'    => __( 'AI agents acting for a person', 'portaria-bots' ),
			'seo'         => __( 'SEO tools', 'portaria-bots' ),
			'script'      => __( 'Scripts and unknown clients', 'portaria-bots' ),
			'fake'        => __( 'Fake bots', 'portaria-bots' ),
		);
		return $labels[ $class ] ?? $class;
	}

	/** @param array<string,mixed>|null $entry Catalog entry. */
	private static function bot_name( ?array $entry, string $fallback ): string {
		if ( null === $entry ) {
			return $fallback;
		}
		$generic = array(
			'no_ua'     => __( 'No User-Agent', 'portaria-bots' ),
			'other_bot' => __( 'Other bot', 'portaria-bots' ),
			'unknown'   => __( 'Unknown client', 'portaria-bots' ),
			'payer'     => __( 'Paying client', 'portaria-bots' ),
		);
		return $generic[ $entry['key'] ] ?? (string) $entry['name'];
	}

	private static function network_label( string $key, string $label ): string {
		return 'base-sepolia' === $key ? __( 'Base Sepolia (test network)', 'portaria-bots' ) : $label;
	}

	/** Warns when bots arrive through a proxy the owner has not told Portaria about. */
	private static function render_proxy_warning(): void {
		if ( '' !== Settings::get()['client_ip_header'] || (int) get_option( 'portaria_proxy_seen', 0 ) < time() - 7 * DAY_IN_SECONDS ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p>';
		printf(
			/* translators: %s: link to settings. */
			esc_html__( 'Your site seems to be behind a proxy or CDN, so Portaria cannot check bot addresses. Fake bots show as Unknown, and fake search engines read for free, until you choose the client IP header. %s', 'portaria-bots' ),
			'<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Open settings', 'portaria-bots' ) . '</a>'
		);
		echo '</p></div>';
	}

	private static function identity_label( string $identity ): string {
		$labels = array(
			Identity::VERIFIED => __( 'Verified', 'portaria-bots' ),
			Identity::FAKE     => __( 'Fake', 'portaria-bots' ),
			Identity::UNKNOWN  => __( 'Unknown', 'portaria-bots' ),
			Identity::CLAIMED  => __( 'Unverifiable', 'portaria-bots' ),
		);
		return $labels[ $identity ] ?? $identity;
	}

	private static function action_label( string $action ): string {
		$labels = array(
			'allow'  => __( 'Allow', 'portaria-bots' ),
			'charge' => __( 'Charge', 'portaria-bots' ),
			'block'  => __( 'Block', 'portaria-bots' ),
		);
		return $labels[ $action ] ?? $action;
	}

	/* ---------------------------------------------------------------- Dashboard */

	private static function render_dashboard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- Only selects a period.
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;

		$settings = Settings::get();
		$active   = Settings::is_active();
		$totals   = Store::funnel( $days );
		$bots     = Store::bots( $days );

		if ( ! $active ) {
			echo '<div class="notice notice-info inline"><p>';
			printf(
				/* translators: %s: link to settings. */
				esc_html__( 'Monitor mode: bots are counted and nothing is charged or blocked. Numbers below show what would happen. %s', 'portaria-bots' ),
				'<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Change mode', 'portaria-bots' ) . '</a>'
			);
			echo '</p></div>';
		}

		self::render_proxy_warning();

		echo '<div class="portaria-top"><h2>' . esc_html__( 'Bots and payments', 'portaria-bots' ) . '</h2><div class="portaria-periods">';
		foreach ( array( 7, 30, 90 ) as $option ) {
			printf(
				'<a href="%s" class="button%s">%s</a> ',
				esc_url( add_query_arg( 'days', $option, self::url() ) ),
				$option === $days ? ' button-primary' : '',
				/* translators: %d: number of days. */
				esc_html( sprintf( __( '%d days', 'portaria-bots' ), $option ) )
			);
		}
		echo '</div></div>';

		echo '<div class="portaria-grid"><div class="portaria-box"><h3>' . esc_html__( 'From bot to payment', 'portaria-bots' ) . '</h3>';
		$max   = max( 1, $totals['hits'] );
		$steps = array(
			array( __( 'Bot visits', 'portaria-bots' ), $totals['hits'] ),
			$active
				? array( __( '402 responses', 'portaria-bots' ), $totals['challenged'] )
				: array( __( 'Would receive a 402', 'portaria-bots' ), $totals['priced'] ),
			array( __( 'Payment attempts', 'portaria-bots' ), $totals['attempts'] ),
			array( __( 'Payments completed', 'portaria-bots' ), $totals['paid'] ),
		);
		foreach ( $steps as $step ) {
			$width = 0 === $step[1] ? 0 : max( 1, (int) round( $step[1] / $max * 100 ) );
			printf(
				'<div class="portaria-step"><span>%s</span><span>%s</span></div><div class="portaria-bar" style="width:%d%%"></div>',
				esc_html( $step[0] ),
				esc_html( number_format_i18n( $step[1] ) ),
				(int) $width
			);
		}
		echo '</div><div class="portaria-box">';
		echo '<div class="portaria-big">' . esc_html( self::money( (string) $totals['paid_atomic'] ) ) . '</div>';
		echo '<div class="portaria-sub">' . esc_html__( 'received', 'portaria-bots' ) . '</div>';
		echo '<div class="portaria-big">' . esc_html( self::money( (string) $totals['priced_atomic'] ) ) . '</div>';
		echo '<div class="portaria-sub">' . esc_html( $active ? __( 'value of charged visits', 'portaria-bots' ) : __( 'value if charged', 'portaria-bots' ) ) . '</div>';
		self::render_test_summary();
		echo '</div></div>';

		echo '<div class="portaria-box"><h3>' . esc_html__( 'Who visited', 'portaria-bots' );
		if ( $totals['fake'] > 0 ) {
			/* translators: %s: number of visits from fake bots. */
			echo ' <span class="portaria-bad">· ' . esc_html( sprintf( __( '%s visits from fake bots', 'portaria-bots' ), number_format_i18n( $totals['fake'] ) ) ) . '</span>';
		}
		echo '</h3>';

		if ( array() === $bots ) {
			echo '<p>' . esc_html__( 'No bots counted in this period yet. If your site is behind a page cache, check the Diagnostics tab.', 'portaria-bots' ) . '</p>';
		} else {
			self::render_bot_table( $bots, $settings );
		}
		echo '<p class="description">' . esc_html__( 'The major AI crawlers do not pay per page yet. Each charge stays on record for when they do. Portaria does not promise income from bots.', 'portaria-bots' ) . '</p></div>';

		self::render_payments();
	}

	private static function render_test_summary(): void {
		$test = Store::last_test();
		if ( null === $test ) {
			echo '<p class="portaria-sub"><a href="' . esc_url( self::url( 'diagnostics' ) ) . '">' . esc_html__( 'No test payment yet', 'portaria-bots' ) . '</a></p>';
			return;
		}
		echo '<p class="portaria-ok">' . esc_html__( 'Test payment: paid', 'portaria-bots' ) . '</p>';
		$link = self::tx_link( $test['tx'], $test['network'], __( 'View transaction', 'portaria-bots' ) );
		if ( '' !== $link ) {
			echo '<p>' . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in tx_link().
		}
	}

	/** Explorer link for a transaction, or empty when the network has none. */
	private static function tx_link( string $tx, string $network, string $label ): string {
		foreach ( Settings::networks() as $candidate ) {
			if ( $candidate['caip2'] === $network && ! empty( $candidate['explorer'] ) && 1 === preg_match( '/^0x[0-9a-fA-F]+$/', $tx ) ) {
				return '<a href="' . esc_url( $candidate['explorer'] . $tx ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
			}
		}
		return '';
	}

	/**
	 * @param array<int,array<string,string>> $bots     Rows from Store::bots().
	 * @param array<string,mixed>             $settings Settings.
	 */
	private static function render_bot_table( array $bots, array $settings ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Bot', 'portaria-bots' ), __( 'Identity', 'portaria-bots' ), __( 'Visits', 'portaria-bots' ), __( 'Rule', 'portaria-bots' ), __( 'Paid', 'portaria-bots' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $bots as $row ) {
			$name = self::bot_name( Catalog::entry( $row['bot'] ), $row['bot'] );
			$fake = Identity::FAKE === $row['identity'];
			$rule  = Rules::decide(
				array(
					'bot_key'    => $row['bot'],
					'class'      => $row['class'],
					'identity'   => $row['identity'],
					'path'       => '',
					'post_type'  => '',
					'categories' => array(),
				),
				$settings,
				array()
			);
			$free = 'allow' === $rule['action'] && 0 === (int) $row['paid_atomic'];

			printf(
				'<tr><td title="%1$s">%2$s</td><td class="%3$s">%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_attr( self::class_label( $fake ? Catalog::FAKE_CLASS : $row['class'] ) ),
				esc_html( $fake ? '"' . $name . '"' : $name ),
				esc_attr( $fake ? 'portaria-bad' : ( Identity::VERIFIED === $row['identity'] ? 'portaria-ok' : '' ) ),
				esc_html( self::identity_label( $row['identity'] ) ),
				esc_html( number_format_i18n( (int) $row['hits'] ) ),
				esc_html( self::action_label( $rule['action'] ) ),
				esc_html( $free ? __( 'Free', 'portaria-bots' ) : self::money( $row['paid_atomic'] ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_payments(): void {
		$payments = Store::recent_payments( 10 );
		if ( array() === $payments ) {
			return;
		}

		$export = wp_nonce_url( admin_url( 'admin-post.php?action=portaria_export' ), 'portaria_export' );
		echo '<div class="portaria-box"><h3>' . esc_html__( 'Recent payments', 'portaria-bots' ) . '</h3><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When (UTC)', 'portaria-bots' ), __( 'Status', 'portaria-bots' ), __( 'Amount', 'portaria-bots' ), __( 'Page', 'portaria-bots' ), __( 'Transaction', 'portaria-bots' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$statuses = array(
			'paid'    => __( 'Paid', 'portaria-bots' ),
			'pending' => __( 'Pending', 'portaria-bots' ),
			'failed'  => __( 'Failed', 'portaria-bots' ),
		);
		foreach ( $payments as $payment ) {
			$status = $statuses[ $payment['status'] ] ?? $payment['status'];
			if ( 'test' === $payment['kind'] ) {
				$status .= ' · ' . __( 'test', 'portaria-bots' );
			}
			$detail = self::tx_link( $payment['tx'], $payment['network'], __( 'View', 'portaria-bots' ) );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
				esc_html( $payment['created_at'] ),
				esc_html( $status ),
				esc_html( self::money( $payment['amount_atomic'] ) ),
				esc_html( $payment['path'] ),
				'' !== $detail ? $detail : esc_html( $payment['reason'] ) // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in tx_link().
			);
		}
		echo '</tbody></table><p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export settled payments (CSV)', 'portaria-bots' ) . '</a></p></div>';
	}

	/* -------------------------------------------------------------------- Rules */

	private static function select_action( string $name, string $current, bool $inherit = false ): void {
		echo '<select name="' . esc_attr( $name ) . '">';
		if ( $inherit ) {
			echo '<option value="">' . esc_html__( 'Follow its group', 'portaria-bots' ) . '</option>';
		}
		foreach ( Rules::ACTIONS as $action ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $action ), selected( $current, $action, false ), esc_html( self::action_label( $action ) ) );
		}
		echo '</select>';
	}

	private static function price_input( string $name, string $value, string $placeholder ): void {
		printf(
			'<input type="text" inputmode="decimal" class="small-text" name="%s" value="%s" placeholder="%s" aria-label="%s">',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_attr__( 'Price', 'portaria-bots' )
		);
	}

	private static function render_rules(): void {
		$settings = Settings::get();
		$default  = (string) $settings['default_price'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'portaria_save_rules' );
		echo '<input type="hidden" name="action" value="portaria_save_rules">';

		echo '<h2>' . esc_html__( 'Price', 'portaria-bots' ) . '</h2><table class="form-table"><tr><th><label for="portaria-default-price">' . esc_html__( 'Default price per page', 'portaria-bots' ) . '</label></th><td>';
		printf( '<input id="portaria-default-price" type="text" inputmode="decimal" class="small-text" name="default_price" value="%s"> ', esc_attr( $default ) );
		echo '<select name="currency" aria-label="' . esc_attr__( 'Currency', 'portaria-bots' ) . '">';
		foreach ( array( 'USD' => 'US$', 'BRL' => 'R$' ) as $code => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $settings['currency'], $code, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Bots always pay in USDC. A price in reais is converted with the Banco Central PTAX rate of the day, rounded up.', 'portaria-bots' ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'What to do with each group', 'portaria-bots' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Search engines, link previews and site services always pass. Blocking or charging AI search crawlers can remove your site from AI assistants\' answers.', 'portaria-bots' ) . '</p>';
		echo '<table class="widefat striped portaria-rules"><thead><tr><th>' . esc_html__( 'Group', 'portaria-bots' ) . '</th><th>' . esc_html__( 'Action', 'portaria-bots' ) . '</th><th>' . esc_html__( 'Price', 'portaria-bots' ) . '</th></tr></thead><tbody>';
		foreach ( array_merge( Catalog::POLICY_CLASSES, array( Catalog::FAKE_CLASS ) ) as $class ) {
			$policy = (array) ( $settings['policies'][ $class ] ?? array() );
			echo '<tr><td>' . esc_html( self::class_label( $class ) ) . '</td><td>';
			self::select_action( "policies[{$class}][action]", (string) ( $policy['action'] ?? 'allow' ) );
			echo '</td><td>';
			self::price_input( "policies[{$class}][price]", (string) ( $policy['price'] ?? '' ), $default );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Rules by content', 'portaria-bots' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One rule per line, first match wins. Examples:', 'portaria-bots' ) . ' <code>path:/premium/ charge 0.05</code> <code>category:news allow</code> <code>type:page block</code></p>';
		echo '<p class="description">' . esc_html__( 'Category and type rules apply to single posts and category pages. The home page, feeds and the REST API can show the same content and follow the group rules, so protect sensitive content with group rules too.', 'portaria-bots' ) . '</p>';
		echo '<textarea name="content_rules" rows="6" class="large-text code" aria-label="' . esc_attr__( 'Rules by content', 'portaria-bots' ) . '">' . esc_textarea( (string) $settings['content_rules'] ) . '</textarea>';

		echo '<h2>' . esc_html__( 'Rules by bot', 'portaria-bots' ) . '</h2><details><summary>' . esc_html__( 'Set a rule for one bot. It overrides the group and content rules.', 'portaria-bots' ) . '</summary>';
		echo '<table class="widefat striped portaria-rules"><thead><tr><th>' . esc_html__( 'Bot', 'portaria-bots' ) . '</th><th>' . esc_html__( 'Operator', 'portaria-bots' ) . '</th><th>' . esc_html__( 'Group', 'portaria-bots' ) . '</th><th>' . esc_html__( 'Action', 'portaria-bots' ) . '</th><th>' . esc_html__( 'Price', 'portaria-bots' ) . '</th></tr></thead><tbody>';
		foreach ( Catalog::bots() as $key => $bot ) {
			if ( 'search' === $bot['class'] ) {
				continue;
			}
			$rule = (array) ( $settings['bot_rules'][ $key ] ?? array() );
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>', esc_html( $bot['name'] ), esc_html( $bot['operator'] ), esc_html( self::class_label( $bot['class'] ) ) );
			self::select_action( "bot_rules[{$key}][action]", (string) ( $rule['action'] ?? '' ), true );
			echo '</td><td>';
			self::price_input( "bot_rules[{$key}][price]", (string) ( $rule['price'] ?? '' ), $default );
			echo '</td></tr>';
		}
		echo '</tbody></table></details>';

		submit_button( __( 'Save rules', 'portaria-bots' ) );
		echo '</form>';
	}

	/* ----------------------------------------------------------------- Settings */

	private static function render_settings(): void {
		$settings = Settings::get();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'portaria_save_settings' );
		echo '<input type="hidden" name="action" value="portaria_save_settings"><table class="form-table">';

		echo '<tr><th>' . esc_html__( 'Mode', 'portaria-bots' ) . '</th><td><fieldset>';
		foreach (
			array(
				'monitor' => __( 'Monitor: count bots, change nothing for anyone.', 'portaria-bots' ),
				'active'  => __( 'Active: apply the rules, charging and blocking bots.', 'portaria-bots' ),
			) as $mode => $label
		) {
			printf( '<label><input type="radio" name="mode" value="%s"%s> %s</label><br>', esc_attr( $mode ), checked( $settings['mode'], $mode, false ), esc_html( $label ) );
		}
		printf(
			'<p><label><input type="checkbox" name="ack_ai" value="1"%s> %s</label></p>',
			checked( ! empty( $settings['ack_ai'] ), true, false ),
			esc_html__( 'I understand that crawlers that do not pay stop receiving my content. Major AI crawlers do not pay yet, so charging them can reduce how often AI assistants cite my site. Active mode needs this confirmation.', 'portaria-bots' )
		);
		echo '</fieldset></td></tr>';

		echo '<tr><th><label for="portaria-wallet">' . esc_html__( 'Your wallet', 'portaria-bots' ) . '</label></th><td>';
		printf( '<input id="portaria-wallet" type="text" class="regular-text code" name="wallet" value="%s" placeholder="0x..." autocomplete="off" spellcheck="false">', esc_attr( $settings['wallet'] ) );
		echo '<p class="description">' . esc_html__( 'Public address that receives USDC directly. Portaria never holds funds. Never paste a private key or seed phrase here.', 'portaria-bots' ) . '</p></td></tr>';

		echo '<tr><th><label for="portaria-network">' . esc_html__( 'Network', 'portaria-bots' ) . '</label></th><td><select id="portaria-network" name="network">';
		foreach ( Settings::networks() as $key => $network ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $settings['network'], $key, false ), esc_html( self::network_label( $key, $network['label'] ) ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="portaria-facilitator">' . esc_html__( 'Facilitator', 'portaria-bots' ) . '</label></th><td>';
		printf( '<input id="portaria-facilitator" type="url" class="regular-text code" name="facilitator" value="%s">', esc_attr( $settings['facilitator'] ) );
		echo '<p class="description">' . esc_html__( 'Service that verifies payments and sends them to the blockchain. It cannot change the amount or the recipient. Contacted only when a client presents a payment.', 'portaria-bots' ) . '</p></td></tr>';

		echo '<tr><th><label for="portaria-contact">' . esc_html__( 'Licensing contact', 'portaria-bots' ) . '</label></th><td>';
		printf( '<input id="portaria-contact" type="text" class="regular-text" name="contact" value="%s">', esc_attr( $settings['contact'] ) );
		echo '<p class="description">' . esc_html__( 'Optional. Email or URL included in 402 responses, so companies that prefer a license can reach you.', 'portaria-bots' ) . '</p></td></tr>';

		echo '<tr><th><label for="portaria-ip">' . esc_html__( 'Client IP header', 'portaria-bots' ) . '</label></th><td><select id="portaria-ip" name="client_ip_header"><option value="">' . esc_html__( 'Not chosen yet', 'portaria-bots' ) . '</option>';
		printf( '<option value="%s"%s>%s</option>', esc_attr( Net::DIRECT ), selected( $settings['client_ip_header'], Net::DIRECT, false ), esc_html__( 'None: no proxy or CDN in front of this site', 'portaria-bots' ) );
		foreach ( array_keys( Net::CLIENT_IP_HEADERS ) as $header ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $header ), selected( $settings['client_ip_header'], $header, false ), esc_html( $header ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Choose one only if your site is behind a proxy or CDN that sets this header. Otherwise bots could fake their address.', 'portaria-bots' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Page cache', 'portaria-bots' ) . '</th><td>';
		printf( '<label><input type="checkbox" name="cache_bypass" value="1"%s> %s</label>', checked( ! empty( $settings['cache_bypass'] ), true, false ), esc_html__( 'Send known bots past the page cache (WP Rocket and LiteSpeed Cache) so they can be counted and charged.', 'portaria-bots' ) );
		echo '<p class="description">' . esc_html__( 'Bots then reach PHP, which uses more server resources on sites with heavy crawling. Search engines stay cached.', 'portaria-bots' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Reais', 'portaria-bots' ) . '</th><td>';
		printf( '<label><input type="checkbox" name="show_brl" value="1"%s> %s</label>', checked( ! empty( $settings['show_brl'] ), true, false ), esc_html__( 'Also show amounts in reais, using the Banco Central PTAX rate.', 'portaria-bots' ) );
		echo '</td></tr>';

		echo '<tr><th><label for="portaria-retention">' . esc_html__( 'Keep bot statistics for', 'portaria-bots' ) . '</label></th><td>';
		printf( '<input id="portaria-retention" type="number" min="30" max="3650" class="small-text" name="retention_days" value="%d"> %s', (int) $settings['retention_days'], esc_html__( 'days', 'portaria-bots' ) );
		echo '</td></tr></table>';

		submit_button( __( 'Save settings', 'portaria-bots' ) );
		echo '</form>';
	}

	/* -------------------------------------------------------------- Diagnostics */

	private static function action_button( string $action, string $label, bool $primary = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="portaria-inline">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function render_diagnostics(): void {
		$settings = Settings::get();

		echo '<div class="portaria-box"><h3>' . esc_html__( 'Page cache', 'portaria-bots' ) . '</h3>';
		self::render_proxy_warning();
		$detected = Cache_Compat::detected();
		echo '<p>' . esc_html__( 'Detected:', 'portaria-bots' ) . ' ' . esc_html( array() === $detected ? __( 'none', 'portaria-bots' ) : implode( ', ', $detected ) ) . '</p>';

		$result = Cache_Compat::last_result();
		if ( null === $result ) {
			echo '<p>' . esc_html__( 'Not tested yet.', 'portaria-bots' ) . '</p>';
		} elseif ( ! empty( $result['ok'] ) ) {
			echo '<p class="portaria-ok">' . esc_html__( 'Bots are reaching Portaria. They can be counted and charged.', 'portaria-bots' ) . '</p>';
		} elseif ( empty( $result['reachable'] ) ) {
			echo '<p class="portaria-bad">' . esc_html__( 'The test could not reach this site from the server. Some hosts block these loopback requests, so this says nothing about your cache.', 'portaria-bots' ) . '</p>';
		} else {
			echo '<p class="portaria-bad">' . esc_html__( 'A cache answered before Portaria ran: bots on cached pages are not counted and do not pay.', 'portaria-bots' ) . '</p>';
			echo '<p>' . esc_html__( 'Exclude AI bot User-Agents from your cache or CDN, or turn on the page cache option in Settings if you use WP Rocket or LiteSpeed Cache, then test again.', 'portaria-bots' ) . '</p>';
		}
		if ( null !== $result ) {
			/* translators: 1: URL, 2: date and time. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Tested %1$s on %2$s.', 'portaria-bots' ), (string) $result['url'], wp_date( 'Y-m-d H:i', (int) $result['time'] ) ) ) . '</p>';
		}
		self::action_button( 'portaria_self_test', __( 'Test now', 'portaria-bots' ), true );
		echo '</div>';

		echo '<div class="portaria-box"><h3>' . esc_html__( 'Test payment', 'portaria-bots' ) . '</h3>';
		if ( ! X402::is_address( (string) $settings['wallet'] ) ) {
			echo '<p>' . esc_html__( 'Set your wallet in Settings first.', 'portaria-bots' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Proves the entrance works, in any mode. Pay this private URL with any x402 client, using a wallet of your own. It charges the default price and is recorded apart from real traffic.', 'portaria-bots' ) . '</p>';
			$url = add_query_arg( 'portaria_test', (string) $settings['test_token'], home_url( '/' ) );
			echo '<p><code>' . esc_html( $url ) . '</code></p>';
			self::action_button( 'portaria_regenerate_token', __( 'Create a new private URL', 'portaria-bots' ) );
		}
		$test = Store::last_test();
		if ( null !== $test ) {
			echo '<p class="portaria-ok">' . esc_html__( 'Test payment: paid', 'portaria-bots' ) . ' · ' . esc_html( $test['created_at'] ) . ' UTC</p>';
		}
		echo '</div>';

		echo '<div class="portaria-box"><h3>' . esc_html__( 'Facilitator', 'portaria-bots' ) . '</h3><p><code>' . esc_html( (string) $settings['facilitator'] ) . '</code></p>';
		$check = get_transient( 'portaria_facilitator_check' );
		if ( is_array( $check ) ) {
			if ( empty( $check['reachable'] ) ) {
				echo '<p class="portaria-bad">' . esc_html__( 'Could not reach the facilitator.', 'portaria-bots' ) . '</p>';
			} elseif ( ! empty( $check['v2'] ) || ! empty( $check['v1'] ) ) {
				echo '<p class="portaria-ok">' . esc_html__( 'The facilitator supports the selected network.', 'portaria-bots' ) . '</p>';
			} else {
				echo '<p class="portaria-bad">' . esc_html__( 'The facilitator does not support the selected network.', 'portaria-bots' ) . '</p>';
			}
		}
		self::action_button( 'portaria_check_facilitator', __( 'Check facilitator', 'portaria-bots' ) );
		echo '</div>';

		echo '<div class="portaria-box"><h3>' . esc_html__( 'Verification data', 'portaria-bots' ) . '</h3>';
		$updated = (int) get_option( 'portaria_ranges_updated', 0 );
		$ranges  = get_option( Identity::RANGES_OPTION, array() );
		$lists   = is_array( $ranges ) ? count( $ranges ) : 0;
		$keys    = get_option( Identity::KEYS_OPTION, array() );
		$signers = is_array( $keys ) ? count( $keys ) : 0;
		echo '<ul>';
		/* translators: 1: number of bots, 2: date or "never". */
		echo '<li>' . esc_html( sprintf( __( 'Published IP lists: %1$d bots, updated %2$s', 'portaria-bots' ), $lists, $updated > 0 ? wp_date( 'Y-m-d H:i', $updated ) : __( 'never', 'portaria-bots' ) ) ) . '</li>';
		/* translators: %d: number of hosts. */
		echo '<li>' . esc_html( sprintf( __( 'Web Bot Auth keys: %d hosts', 'portaria-bots' ), $signers ) ) . '</li>';
		$rate = Rates::usd_brl();
		if ( null !== $rate ) {
			/* translators: 1: exchange rate, 2: date. */
			echo '<li>' . esc_html( sprintf( __( 'PTAX: R$ %1$s per US$, from %2$s', 'portaria-bots' ), number_format_i18n( $rate, 4 ), Rates::date() ) ) . '</li>';
		} elseif ( Rates::needed() ) {
			echo '<li class="portaria-bad">' . esc_html__( 'PTAX rate not available yet. Prices in reais are not charged until it is.', 'portaria-bots' ) . '</li>';
		}
		echo '</ul>';
		self::action_button( 'portaria_refresh_data', __( 'Update now', 'portaria-bots' ) );
		echo '</div>';
	}
}
