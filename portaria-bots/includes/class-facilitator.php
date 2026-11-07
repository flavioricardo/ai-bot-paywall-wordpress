<?php
/**
 * HTTP client for the configured x402 facilitator. Only called when a payment is presented.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Facilitator {

	/** Largest facilitator response read, in bytes. */
	const MAX_RESPONSE = 65536;

	/**
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed>|null Decoded response, null on network failure.
	 */
	private static function post( string $path, array $body, int $timeout ): ?array {
		$response = wp_remote_post(
			self::url( $path ),
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE,
				/** Lets a facilitator that needs authentication receive it. */
				'headers'             => (array) apply_filters( 'portaria_facilitator_headers', array( 'Content-Type' => 'application/json' ), $path ),
				'body'                => (string) wp_json_encode( $body ),
			)
		);
		return self::decode( $response );
	}

	private static function url( string $path ): string {
		return untrailingslashit( (string) Settings::get()['facilitator'] ) . $path;
	}

	/**
	 * @param array<string,mixed>|\WP_Error $response HTTP response.
	 * @return array<string,mixed>|null
	 */
	private static function decode( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param array<string,mixed> $body Verify request.
	 * @return array{valid:bool,reason:string}
	 */
	public static function verify( array $body ): array {
		$response = self::post( '/verify', $body, 8 );
		if ( null === $response ) {
			return array(
				'valid'  => false,
				'reason' => 'unexpected_verify_error',
			);
		}
		$valid = true === ( $response['isValid'] ?? null );
		return array(
			'valid'  => $valid,
			'reason' => $valid ? '' : (string) ( is_string( $response['invalidReason'] ?? null ) ? $response['invalidReason'] : 'unexpected_verify_error' ),
		);
	}

	/**
	 * @param array<string,mixed> $body Settle request.
	 * @return array{status:string,transaction:string,payer:string,reason:string}
	 */
	public static function settle( array $body ): array {
		return X402::read_settlement( self::post( '/settle', $body, 20 ) );
	}

	/**
	 * Which of the configured network's x402 versions the facilitator supports.
	 *
	 * @param array<string,string> $network Entry from networks().
	 * @return array{reachable:bool,v2:bool,v1:bool}
	 */
	public static function check( array $network ): array {
		$data   = self::decode(
			wp_remote_get(
				self::url( '/supported' ),
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => 1048576,
					'headers'             => (array) apply_filters( 'portaria_facilitator_headers', array(), '/supported' ),
				)
			)
		);
		$result = array(
			'reachable' => is_array( $data ) && is_array( $data['kinds'] ?? null ),
			'v2'        => false,
			'v1'        => false,
		);
		foreach ( (array) ( $data['kinds'] ?? array() ) as $kind ) {
			if ( ! is_array( $kind ) || 'exact' !== ( $kind['scheme'] ?? '' ) ) {
				continue;
			}
			$result['v2'] = $result['v2'] || ( 2 === ( $kind['x402Version'] ?? 0 ) && ( $kind['network'] ?? '' ) === $network['caip2'] );
			$result['v1'] = $result['v1'] || ( 1 === ( $kind['x402Version'] ?? 0 ) && ( $kind['network'] ?? '' ) === $network['v1'] );
		}
		return $result;
	}
}
