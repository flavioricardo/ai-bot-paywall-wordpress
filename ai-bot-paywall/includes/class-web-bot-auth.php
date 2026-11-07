<?php
/**
 * Web Bot Auth: verifies RFC 9421 HTTP Message Signatures from known bots.
 * No WordPress dependency; the Ed25519 check is injected.
 *
 * @package AIBotPaywall
 * @see https://datatracker.ietf.org/doc/draft-ietf-webbotauth-httpsig-protocol/
 */

namespace AIBotPaywall;

defined( 'ABSPATH' ) || defined( 'AI_BOT_PAYWALL_TESTING' ) || exit;

final class Web_Bot_Auth {

	/** Largest accepted signature lifetime and clock tolerance, in seconds. */
	const MAX_LIFETIME = 86400;
	const CLOCK_SKEW   = 60;

	/**
	 * Extracts the signing host from a Signature-Agent header. Accepts a bare host,
	 * a quoted URL, or a dictionary member such as sig1="https://chatgpt.com".
	 */
	public static function agent_host( string $header ): string {
		$value = trim( $header );
		if ( preg_match( '/"([^"]+)"/', $value, $m ) ) {
			$value = $m[1];
		}
		$host = false !== strpos( $value, '://' ) ? (string) parse_url( $value, PHP_URL_HOST ) : $value;
		return 1 === preg_match( '/^[a-z0-9.-]{1,253}$/i', $host ) ? strtolower( $host ) : '';
	}

	/** RFC 7638 thumbprint of an Ed25519 JWK, which is its keyid. */
	public static function thumbprint( string $x ): string {
		$canonical = '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}';
		return self::base64url( hash( 'sha256', $canonical, true ) );
	}

	/**
	 * Reads Ed25519 keys from a key directory document.
	 *
	 * @return array<string,string> Raw public key by keyid.
	 */
	public static function keys_from_directory( string $json ): array {
		$data = json_decode( $json, true );
		$keys = array();
		if ( ! is_array( $data ) || ! is_array( $data['keys'] ?? null ) ) {
			return $keys;
		}
		foreach ( $data['keys'] as $jwk ) {
			if ( ! is_array( $jwk ) || 'OKP' !== ( $jwk['kty'] ?? '' ) || 'Ed25519' !== ( $jwk['crv'] ?? '' ) || ! is_string( $jwk['x'] ?? null ) ) {
				continue;
			}
			$raw = self::base64url_decode( $jwk['x'] );
			if ( 32 === strlen( $raw ) ) {
				$keys[ self::thumbprint( $jwk['x'] ) ] = $raw;
			}
		}
		return $keys;
	}

	/**
	 * Verifies the request signature tagged web-bot-auth.
	 *
	 * @param array<string,string> $headers   Lower-case header names.
	 * @param string               $authority Host the request was sent to.
	 * @param array<string,string> $keys      Raw public keys by keyid for this agent.
	 * @param callable             $verify    fn( string $signature, string $message, string $key ): bool.
	 * @return bool True only when a signature covering the authority is valid now.
	 */
	public static function verify( array $headers, string $authority, array $keys, int $now, callable $verify ): bool {
		$parsed = self::find_signature_input( (string) ( $headers['signature-input'] ?? '' ) );
		if ( null === $parsed ) {
			return false;
		}
		$label  = $parsed['label'];
		$params = $parsed['params'];

		$created = isset( $params['created'] ) ? (int) $params['created'] : 0;
		$expires = isset( $params['expires'] ) ? (int) $params['expires'] : 0;
		if ( 0 === $created || 0 === $expires || $created > $now + self::CLOCK_SKEW || $expires <= $now || $expires - $created > self::MAX_LIFETIME ) {
			return false;
		}
		if ( isset( $params['alg'] ) && 'ed25519' !== $params['alg'] ) {
			return false;
		}
		$key = $keys[ (string) ( $params['keyid'] ?? '' ) ] ?? null;
		if ( null === $key ) {
			return false;
		}

		$signature = self::read_signature( (string) ( $headers['signature'] ?? '' ), $label );
		if ( null === $signature ) {
			return false;
		}

		$base = self::signature_base( $parsed['components'], $parsed['raw'], $headers, $authority );
		return null !== $base && (bool) $verify( $signature, $base, $key );
	}

	/**
	 * Finds the member tagged web-bot-auth in a Signature-Input header.
	 *
	 * @return array{label:string,raw:string,components:string[],params:array<string,string>}|null
	 */
	private static function find_signature_input( string $header ): ?array {
		$pattern = '/(?:^|,)\s*([a-zA-Z0-9_*.-]+)=(\(((?:\s*"[^"]+"(?:;[a-z]+="[^"]*")?)*\s*)\)((?:;[a-z]+=(?:"[^"]*"|\d+))*))/';
		if ( ! preg_match_all( $pattern, $header, $members, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $members as $member ) {
			preg_match_all( '/;([a-z]+)=(?:"([^"]*)"|(\d+))/', $member[4], $pairs, PREG_SET_ORDER );
			$params = array();
			foreach ( $pairs as $pair ) {
				$params[ $pair[1] ] = isset( $pair[3] ) && '' !== $pair[3] ? $pair[3] : $pair[2];
			}
			if ( 'web-bot-auth' !== ( $params['tag'] ?? '' ) ) {
				continue;
			}
			preg_match_all( '/"[^"]+"(?:;[a-z]+="[^"]*")?/', $member[3], $components );
			return array(
				'label'      => $member[1],
				'raw'        => $member[2],
				'components' => $components[0],
				'params'     => $params,
			);
		}
		return null;
	}

	/** Reads one member of a Signature header: label=:base64:. */
	private static function read_signature( string $header, string $label ): ?string {
		if ( ! preg_match( '/(?:^|,)\s*' . preg_quote( $label, '/' ) . '=:([A-Za-z0-9+\/=]+):/', $header, $m ) ) {
			return null;
		}
		$raw = base64_decode( $m[1], true );
		return false !== $raw && 64 === strlen( $raw ) ? $raw : null;
	}

	/**
	 * Builds the RFC 9421 signature base. Returns null when a covered component is
	 * missing or unsupported, or when the signature does not cover the authority
	 * (it could then be replayed against another site).
	 *
	 * @param string[]             $components Component identifiers, serialized.
	 * @param array<string,string> $headers    Lower-case header names.
	 */
	private static function signature_base( array $components, string $params_raw, array $headers, string $authority ): ?string {
		$lines       = array();
		$covers_host = false;

		foreach ( $components as $component ) {
			if ( ! preg_match( '/^"([^"]+)"(?:;key="([^"]*)")?$/', $component, $m ) ) {
				return null;
			}
			$name = $m[1];
			$dict = isset( $m[2] ) ? $m[2] : null;

			if ( '@authority' === $name ) {
				$covers_host = true;
				$value       = strtolower( $authority );
			} elseif ( '@' === $name[0] || ! isset( $headers[ $name ] ) ) {
				return null;
			} elseif ( null !== $dict ) {
				$value = self::dictionary_member( $headers[ $name ], $dict );
				if ( null === $value ) {
					return null;
				}
			} else {
				$value = trim( $headers[ $name ] );
			}
			$lines[] = $component . ': ' . $value;
		}

		if ( ! $covers_host ) {
			return null;
		}
		$lines[] = '"@signature-params": ' . $params_raw;
		return implode( "\n", $lines );
	}

	/** Serialized value of a dictionary member, for components with a key parameter. */
	private static function dictionary_member( string $header, string $key ): ?string {
		$pattern = '/(?:^|,)\s*' . preg_quote( $key, '/' ) . '=("[^"]*"|[^,]*)/';
		return preg_match( $pattern, $header, $m ) ? trim( $m[1] ) : null;
	}

	private static function base64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $value ): string {
		$raw = base64_decode( strtr( $value, '-_', '+/' ), true );
		return false === $raw ? '' : $raw;
	}
}
