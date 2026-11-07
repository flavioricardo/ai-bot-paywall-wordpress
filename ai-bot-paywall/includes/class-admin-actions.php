<?php
/**
 * Handles admin form posts. Each handler checks capability and nonce first.
 *
 * @package AIBotPaywall
 */

namespace AIBotPaywall;

defined( 'ABSPATH' ) || exit;

final class Admin_Actions {

	public static function init(): void {
		foreach ( array( 'save_rules', 'save_settings', 'self_test', 'check_facilitator', 'refresh_data', 'regenerate_token', 'export' ) as $action ) {
			add_action( 'admin_post_aibotpaywall_' . $action, array( __CLASS__, $action ) );
		}
	}

	private static function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-bot-paywall' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function notice( string $type, string $text ): void {
		$key       = 'aibotpaywall_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array( $type, $text );
		set_transient( $key, $notices, MINUTE_IN_SECONDS * 5 );
	}

	public static function print_notices(): void {
		$key     = 'aibotpaywall_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}

	private static function back( string $tab ): void {
		wp_safe_redirect( Admin::url( $tab ) );
		exit;
	}

	/** Field from the current form post, unslashed. */
	private static function field( string $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- Nonce checked in authorize(); every caller sanitizes.
		return isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : null;
	}

	public static function save_rules(): void {
		self::authorize( 'aibotpaywall_save_rules' );
		$settings = Settings::get();

		$price = trim( sanitize_text_field( (string) self::field( 'default_price' ) ) );
		if ( Rules::valid_price( $price ) ) {
			$settings['default_price'] = $price;
		} else {
			self::notice( 'error', __( 'The default price was not changed: use a positive number with up to six decimals, like 0.01.', 'ai-bot-paywall' ) );
		}
		$settings['currency'] = 'BRL' === self::field( 'currency' ) ? 'BRL' : 'USD';

		$invalid_price = false;
		$policies      = (array) self::field( 'policies' );
		foreach ( array_merge( Catalog::POLICY_CLASSES, array( Catalog::FAKE_CLASS ) ) as $class ) {
			$settings['policies'][ $class ] = self::read_rule( (array) ( $policies[ $class ] ?? array() ), 'allow', $invalid_price );
		}

		$bot_rules = array();
		$posted    = (array) self::field( 'bot_rules' );
		foreach ( array_keys( Catalog::bots() ) as $key ) {
			$rule = self::read_rule( (array) ( $posted[ $key ] ?? array() ), '', $invalid_price );
			if ( '' !== $rule['action'] ) {
				$bot_rules[ $key ] = $rule;
			}
		}
		$settings['bot_rules'] = $bot_rules;
		if ( $invalid_price ) {
			self::notice( 'warning', __( 'Some invalid prices were cleared. Those rules now use the default price.', 'ai-bot-paywall' ) );
		}

		$text                      = sanitize_textarea_field( (string) self::field( 'content_rules' ) );
		$settings['content_rules'] = $text;
		$parsed                    = Rules::parse_content_rules( $text );
		if ( array() !== $parsed['errors'] ) {
			/* translators: %s: rule lines that could not be read. */
			self::notice( 'warning', sprintf( __( 'These content rules were not understood and are being ignored: %s', 'ai-bot-paywall' ), implode( ' | ', $parsed['errors'] ) ) );
		}

		Settings::save( $settings );
		self::notice( 'success', __( 'Rules saved.', 'ai-bot-paywall' ) );
		self::back( 'rules' );
	}

	/**
	 * @param array<string,mixed> $posted   action and price fields.
	 * @param string              $fallback Action when the posted one is not valid.
	 * @return array{action:string,price:string}
	 */
	private static function read_rule( array $posted, string $fallback, bool &$invalid_price ): array {
		$action = sanitize_key( (string) ( $posted['action'] ?? '' ) );
		$price  = trim( sanitize_text_field( (string) ( $posted['price'] ?? '' ) ) );
		if ( '' !== $price && ! Rules::valid_price( $price ) ) {
			$invalid_price = true;
			$price         = '';
		}
		return array(
			'action' => in_array( $action, Rules::ACTIONS, true ) ? $action : $fallback,
			'price'  => $price,
		);
	}

	public static function save_settings(): void {
		self::authorize( 'aibotpaywall_save_settings' );
		$settings = Settings::get();
		$previous = ! empty( $settings['cache_bypass'] );

		$settings['ack_ai']       = ! empty( self::field( 'ack_ai' ) );
		$settings['show_brl']     = ! empty( self::field( 'show_brl' ) );
		$settings['cache_bypass'] = ! empty( self::field( 'cache_bypass' ) );
		$settings['contact']      = substr( sanitize_text_field( (string) self::field( 'contact' ) ), 0, 200 );

		$retention                  = absint( self::field( 'retention_days' ) );
		$settings['retention_days'] = min( 3650, max( 30, $retention ) );

		$header                       = (string) self::field( 'client_ip_header' );
		$settings['client_ip_header'] = isset( Net::CLIENT_IP_HEADERS[ $header ] ) || Net::DIRECT === $header ? $header : '';

		$network = (string) self::field( 'network' );
		if ( isset( Settings::networks()[ $network ] ) ) {
			$settings['network'] = $network;
		}

		$wallet = trim( sanitize_text_field( (string) self::field( 'wallet' ) ) );
		if ( '' === $wallet || X402::is_address( $wallet ) ) {
			$settings['wallet'] = $wallet;
		} else {
			self::notice( 'error', __( 'That wallet was not saved: an address starts with 0x and has 40 more letters and numbers.', 'ai-bot-paywall' ) );
		}

		$facilitator = esc_url_raw( trim( (string) self::field( 'facilitator' ) ) );
		if ( Settings::valid_facilitator( $facilitator ) ) {
			$settings['facilitator'] = $facilitator;
		} else {
			self::notice( 'error', __( 'That facilitator was not saved: it needs an https address.', 'ai-bot-paywall' ) );
		}

		$mode = 'active' === self::field( 'mode' ) ? 'active' : 'monitor';
		// The IP choice is required because an unchosen setting makes proxy headers soften
		// failed identity checks, which a fake search engine could exploit.
		if ( 'active' === $mode && ( ! X402::is_address( (string) $settings['wallet'] ) || ! $settings['ack_ai'] || '' === $settings['client_ip_header'] ) ) {
			$mode = 'monitor';
			self::notice( 'error', __( 'Still in Monitor mode: Active mode needs a valid wallet, the confirmation checkbox and a choice for the client IP header.', 'ai-bot-paywall' ) );
		}
		$settings['mode'] = $mode;

		Settings::save( $settings );
		if ( $previous !== $settings['cache_bypass'] ) {
			Cache_Compat::sync();
		}
		// Only the first time: after that the daily job keeps it current.
		if ( null === Rates::usd_brl() ) {
			Rates::refresh();
		}
		self::notice( 'success', __( 'Settings saved.', 'ai-bot-paywall' ) );
		self::back( 'settings' );
	}

	public static function self_test(): void {
		self::authorize( 'aibotpaywall_self_test' );
		Cache_Compat::run_self_test();
		self::back( 'diagnostics' );
	}

	public static function check_facilitator(): void {
		self::authorize( 'aibotpaywall_check_facilitator' );
		set_transient( 'aibotpaywall_facilitator_check', Facilitator::check( Settings::network() ), 5 * MINUTE_IN_SECONDS );
		self::back( 'diagnostics' );
	}

	public static function refresh_data(): void {
		self::authorize( 'aibotpaywall_refresh_data' );
		Identity::refresh_ranges();
		Identity::refresh_signature_keys();
		Rates::refresh();
		self::notice( 'success', __( 'Verification data updated.', 'ai-bot-paywall' ) );
		self::back( 'diagnostics' );
	}

	public static function regenerate_token(): void {
		self::authorize( 'aibotpaywall_regenerate_token' );
		$settings               = Settings::get();
		$settings['test_token'] = bin2hex( random_bytes( 16 ) );
		Settings::save( $settings );
		self::back( 'diagnostics' );
	}

	public static function export(): void {
		self::authorize( 'aibotpaywall_export' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="aibotpaywall-payments.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'created_at_utc', 'kind', 'status', 'bot', 'path', 'amount_usdc', 'network', 'payer', 'transaction' ) );
		foreach ( Store::recent_payments( 100000, true ) as $payment ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
						$payment['created_at'],
						$payment['kind'],
						$payment['status'],
						$payment['bot'],
						$payment['path'],
						number_format( Rules::from_atomic( $payment['amount_atomic'] ), 6, '.', '' ),
						$payment['network'],
						$payment['payer'],
						$payment['tx'],
					)
				)
			);
		}
		fclose( $out );
		exit;
	}

	/** Page paths come from visitors: keep spreadsheets from running them as formulas. */
	public static function csv_safe( string $value ): string {
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}
}
