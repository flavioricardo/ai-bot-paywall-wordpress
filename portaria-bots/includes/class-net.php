<?php
/**
 * IP helpers. No WordPress dependency.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || defined( 'PORTARIA_TESTING' ) || exit;

final class Net {

	/** Client IP headers the owner may trust. Anything else is ignored. */
	const CLIENT_IP_HEADERS = array(
		'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
		'X-Real-IP'        => 'HTTP_X_REAL_IP',
		'True-Client-IP'   => 'HTTP_TRUE_CLIENT_IP',
		'X-Forwarded-For'  => 'HTTP_X_FORWARDED_FOR',
	);

	/** Owner states there is no proxy, as opposed to not having chosen yet. */
	const DIRECT = 'direct';

	/**
	 * Resolves the client IP. Only the configured proxy header is trusted.
	 * For X-Forwarded-For the right-most address is the one the proxy appended;
	 * earlier entries are client-supplied and could impersonate a search engine.
	 *
	 * @param array<string,mixed> $server $_SERVER.
	 */
	public static function client_ip( array $server, string $trusted_header ): string {
		$ip = '';
		if ( isset( self::CLIENT_IP_HEADERS[ $trusted_header ], $server[ self::CLIENT_IP_HEADERS[ $trusted_header ] ] ) ) {
			$parts = explode( ',', (string) $server[ self::CLIENT_IP_HEADERS[ $trusted_header ] ] );
			$ip    = trim( (string) end( $parts ) );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = (string) ( $server['REMOTE_ADDR'] ?? '' );
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Whether an IP falls inside a CIDR prefix. Works for IPv4 and IPv6. */
	public static function ip_in_cidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		$addr  = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Invalid input is an expected false.
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $addr || false === $net || strlen( $addr ) !== strlen( $net ) ) {
			return false;
		}

		$max  = strlen( $addr ) * 8;
		$bits = isset( $parts[1] ) ? $parts[1] : (string) $max;
		if ( ! ctype_digit( $bits ) || (int) $bits > $max ) {
			return false;
		}
		$bits = (int) $bits;

		$bytes = (int) floor( $bits / 8 );
		if ( $bytes > 0 && substr( $addr, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = 0xFF & ( 0xFF << ( 8 - $rest ) );
		return ( ord( $addr[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
	}

	/** Whether a host equals a suffix or is a subdomain of it. */
	public static function host_matches_suffix( string $host, array $suffixes ): bool {
		$host = strtolower( rtrim( $host, '.' ) );
		foreach ( $suffixes as $suffix ) {
			$suffix = strtolower( $suffix );
			if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
				return true;
			}
		}
		return false;
	}
}
