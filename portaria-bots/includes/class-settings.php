<?php
/**
 * Plugin settings: one option, validated on the way in.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'portaria_settings';

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'mode'             => 'monitor',
			'ack_ai'           => false,
			'wallet'           => '',
			'network'          => 'base',
			'facilitator'      => 'https://facilitator.payai.network',
			'default_price'    => '0.01',
			'currency'         => 'USD',
			'policies'         => array(
				'ai_training' => array( 'action' => 'charge', 'price' => '' ),
				'ai_search'   => array( 'action' => 'allow', 'price' => '' ),
				'ai_agent'    => array( 'action' => 'charge', 'price' => '' ),
				'seo'         => array( 'action' => 'allow', 'price' => '' ),
				'script'      => array( 'action' => 'charge', 'price' => '' ),
				'fake'        => array( 'action' => 'charge', 'price' => '' ),
			),
			'bot_rules'        => array(),
			'content_rules'    => '',
			'contact'          => '',
			'client_ip_header' => '',
			'show_brl'         => 0 === strpos( (string) get_locale(), 'pt' ),
			'retention_days'   => 395,
			'cache_bypass'     => true,
			'test_token'       => '',
		);
	}

	/** @return array<string,mixed> */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_replace( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/** @param array<string,mixed> $settings Complete, already validated settings. */
	public static function save( array $settings ): void {
		update_option( self::OPTION, $settings, true );
		self::$cache = null;
	}

	/** Creates the option on activation without touching existing choices. */
	public static function ensure_defaults(): void {
		$settings = self::get();
		if ( '' === $settings['test_token'] ) {
			$settings['test_token'] = bin2hex( random_bytes( 16 ) );
		}
		self::save( $settings );
	}

	/** Networks offered to the owner. Add a token or chain through this filter. */
	public static function networks(): array {
		return (array) apply_filters( 'portaria_networks', X402::networks() );
	}

	/** @return array<string,string> The configured network. */
	public static function network(): array {
		$networks = self::networks();
		$key      = (string) self::get()['network'];
		return isset( $networks[ $key ] ) ? $networks[ $key ] : X402::networks()['base'];
	}

	public static function is_active(): bool {
		$settings = self::get();
		return 'active' === $settings['mode'] && X402::is_address( (string) $settings['wallet'] );
	}

	/** Whether a facilitator URL is safe to send payments to. */
	public static function valid_facilitator( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) ) {
			return false;
		}
		$scheme = $parts['scheme'] ?? '';
		// Plain HTTP only reaches a facilitator running on this same machine.
		return 'https' === $scheme || ( 'http' === $scheme && in_array( $parts['host'], array( 'localhost', '127.0.0.1' ), true ) );
	}
}
