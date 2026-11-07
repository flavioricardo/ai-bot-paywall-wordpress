<?php
/**
 * Decides whether a bot is who it claims to be.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Identity {

	const VERIFIED = 'verified';
	const FAKE     = 'fake';
	const UNKNOWN  = 'unknown';
	const CLAIMED  = 'claimed';

	const RANGES_OPTION = 'portaria_ip_ranges';
	const KEYS_OPTION   = 'portaria_signature_keys';

	/** New reverse DNS lookups allowed per minute, so fake bots cannot stall PHP workers. */
	const DNS_BUDGET = 30;

	/**
	 * Verification order: signature, published IP list, reverse DNS.
	 *
	 * @param array<string,mixed>  $bot     Catalog entry.
	 * @param array<string,string> $headers Lower-case request headers.
	 */
	public static function resolve( array $bot, string $ip, array $headers, string $authority ): string {
		$has_method = false;

		if ( ! empty( $bot['wba'] ) ) {
			$has_method = true;
			if ( self::signature_is_valid( $bot, $headers, $authority ) ) {
				return self::VERIFIED;
			}
		}

		if ( ! empty( $bot['ranges'] ) ) {
			$has_method = true;
			$prefixes   = self::prefixes_for( (string) $bot['key'] );
			if ( array() === $prefixes ) {
				return self::UNKNOWN;
			}
			foreach ( $prefixes as $prefix ) {
				if ( Net::ip_in_cidr( $ip, $prefix ) ) {
					return self::VERIFIED;
				}
			}
			return self::FAKE;
		}

		if ( ! empty( $bot['rdns'] ) ) {
			return self::check_reverse_dns( $ip, (array) $bot['rdns'] );
		}

		return $has_method ? self::FAKE : self::CLAIMED;
	}

	/**
	 * Verifies a Web Bot Auth signature. The signing host must be listed for this
	 * bot, and keys come only from data already fetched for catalog hosts: a client
	 * can never make this server fetch a URL of its choosing.
	 *
	 * @param array<string,mixed>  $bot     Catalog entry.
	 * @param array<string,string> $headers Lower-case request headers.
	 */
	public static function signature_is_valid( array $bot, array $headers, string $authority ): bool {
		if ( empty( $headers['signature-input'] ) || empty( $headers['signature-agent'] ) ) {
			return false;
		}
		$host = Web_Bot_Auth::agent_host( $headers['signature-agent'] );
		if ( ! in_array( $host, (array) ( $bot['wba'] ?? array() ), true ) ) {
			return false;
		}

		$stored = get_option( self::KEYS_OPTION, array() );
		$keys   = array();
		foreach ( (array) ( is_array( $stored ) ? ( $stored[ $host ] ?? array() ) : array() ) as $keyid => $encoded ) {
			$keys[ $keyid ] = (string) base64_decode( (string) $encoded, true );
		}
		if ( array() === $keys ) {
			return false;
		}

		return Web_Bot_Auth::verify( $headers, $authority, $keys, time(), array( __CLASS__, 'ed25519_verify' ) );
	}

	/** Ed25519 check through libsodium, or the copy WordPress ships. */
	public static function ed25519_verify( string $signature, string $message, string $key ): bool {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
		try {
			return sodium_crypto_sign_verify_detached( $signature, $message, $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** @param string[] $suffixes Allowed reverse DNS suffixes. */
	private static function check_reverse_dns( string $ip, array $suffixes ): string {
		if ( '' === $ip ) {
			return self::UNKNOWN;
		}

		// Daily-rotated key: this cache cannot be used to recover visitor IPs.
		$cache_key = 'portaria_rdns_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) . gmdate( 'Ymd' ) ), 0, 32 );
		$cached    = get_transient( $cache_key );
		if ( self::VERIFIED === $cached || self::FAKE === $cached ) {
			return $cached;
		}

		if ( ! Budget::take( 'dns', self::DNS_BUDGET ) ) {
			return self::UNKNOWN;
		}

		$verified = self::reverse_dns_matches( $ip, $suffixes );
		$result   = $verified ? self::VERIFIED : self::FAKE;
		// A wrong negative costs more than a wrong positive: re-check it sooner.
		set_transient( $cache_key, $result, $verified ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Reverse lookup, suffix check, then forward lookup back to the same IP, as
	 * Google and Bing document. Anyone can set a PTR record; only the domain owner
	 * can make the forward lookup agree.
	 *
	 * @param string[] $suffixes Allowed reverse DNS suffixes.
	 */
	private static function reverse_dns_matches( string $ip, array $suffixes ): bool {
		/** Lets tests and unusual hosts supply DNS answers. Return a host or false. */
		$host = apply_filters( 'portaria_dns_ptr', null, $ip );
		if ( null === $host ) {
			$host = gethostbyaddr( $ip );
		}
		if ( ! is_string( $host ) || $host === $ip || ! Net::host_matches_suffix( $host, $suffixes ) ) {
			return false;
		}

		/** Return the list of IPs a host resolves to. */
		$addresses = apply_filters( 'portaria_dns_forward', null, $host );
		if ( null === $addresses ) {
			$addresses = (array) gethostbynamel( $host );
			if ( false !== strpos( $ip, ':' ) ) {
				foreach ( (array) dns_get_record( $host, DNS_AAAA ) as $record ) {
					$addresses[] = $record['ipv6'] ?? '';
				}
			}
		}

		$packed = inet_pton( $ip );
		foreach ( (array) $addresses as $address ) {
			if ( filter_var( $address, FILTER_VALIDATE_IP ) && inet_pton( $address ) === $packed ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] Cached CIDR prefixes for a bot. */
	private static function prefixes_for( string $bot_key ): array {
		$ranges = get_option( self::RANGES_OPTION, array() );
		return is_array( $ranges ) && isset( $ranges[ $bot_key ] ) ? (array) $ranges[ $bot_key ] : array();
	}

	/** Downloads published IP lists. A failed download keeps the last good list. */
	public static function refresh_ranges(): void {
		$ranges = get_option( self::RANGES_OPTION, array() );
		$ranges = is_array( $ranges ) ? $ranges : array();

		foreach ( Catalog::bots() as $key => $bot ) {
			if ( empty( $bot['ranges'] ) ) {
				continue;
			}
			$prefixes = array();
			$complete = true;
			foreach ( $bot['ranges'] as $url ) {
				$list = self::fetch_prefixes( $url );
				if ( null === $list ) {
					$complete = false;
					break;
				}
				$prefixes = array_merge( $prefixes, $list );
			}
			if ( $complete && array() !== $prefixes ) {
				$ranges[ $key ] = $prefixes;
			}
		}

		update_option( self::RANGES_OPTION, $ranges, false );
		update_option( 'portaria_ranges_updated', time(), false );
	}

	/** @return string[]|null Prefixes, or null when the download failed. */
	private static function fetch_prefixes( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 1048576,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! is_array( $data['prefixes'] ?? null ) ) {
			return null;
		}

		$prefixes = array();
		foreach ( $data['prefixes'] as $entry ) {
			$prefix = $entry['ipv4Prefix'] ?? ( $entry['ipv6Prefix'] ?? '' );
			if ( is_string( $prefix ) && 1 === preg_match( '#^[0-9a-fA-F:.]+/\d{1,3}$#', $prefix ) ) {
				$prefixes[] = $prefix;
			}
		}
		return $prefixes;
	}

	/** Downloads Web Bot Auth key directories, only for hosts in the catalog. */
	public static function refresh_signature_keys(): void {
		$stored = get_option( self::KEYS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( Catalog::bots() as $bot ) {
			foreach ( (array) ( $bot['wba'] ?? array() ) as $host ) {
				$response = wp_remote_get(
					'https://' . $host . '/.well-known/http-message-signatures-directory',
					array(
						'timeout'             => 10,
						'redirection'         => 0,
						'limit_response_size' => 65536,
					)
				);
				if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
					continue;
				}
				$keys = Web_Bot_Auth::keys_from_directory( wp_remote_retrieve_body( $response ) );
				if ( array() !== $keys ) {
					$stored[ $host ] = array_map( 'base64_encode', $keys );
				}
			}
		}
		update_option( self::KEYS_OPTION, $stored, false );
	}
}

/** Per-minute budget for work a request can trigger, so bots cannot exhaust the server. */
final class Budget {

	public static function take( string $name, int $limit ): bool {
		$key  = 'portaria_budget_' . $name . '_' . gmdate( 'YmdHi' );
		$used = (int) get_transient( $key );
		if ( $used >= $limit ) {
			return false;
		}
		set_transient( $key, $used + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}
}
