<?php
/**
 * x402 protocol messages (v2 and v1, exact scheme, EIP-3009). No WordPress dependency.
 *
 * @package AIBotPaywall
 * @see https://github.com/x402-foundation/x402/tree/main/specs
 */

namespace AIBotPaywall;

defined( 'ABSPATH' ) || defined( 'AI_BOT_PAYWALL_TESTING' ) || exit;

final class X402 {

	/** Payment headers are small. Anything larger is rejected before parsing. */
	const MAX_HEADER_BYTES = 16384;

	/** Seconds a client has to complete payment. */
	const MAX_TIMEOUT = 60;

	/**
	 * Supported networks. USDC domain name differs between mainnet and testnet,
	 * and clients sign over it, so it must be exact.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function networks(): array {
		return array(
			'base'         => array(
				'label'    => 'Base',
				'caip2'    => 'eip155:8453',
				'v1'       => 'base',
				'asset'    => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
				'name'     => 'USD Coin',
				'version'  => '2',
				'explorer' => 'https://basescan.org/tx/',
			),
			'base-sepolia' => array(
				'label'    => 'Base Sepolia (test network)',
				'caip2'    => 'eip155:84532',
				'v1'       => 'base-sepolia',
				'asset'    => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
				'name'     => 'USDC',
				'version'  => '2',
				'explorer' => 'https://sepolia.basescan.org/tx/',
			),
		);
	}

	public static function is_address( string $address ): bool {
		return 1 === preg_match( '/^0x[0-9a-fA-F]{40}$/', $address );
	}

	/** Same token, same recipient: addresses compare case-insensitively. */
	private static function same_address( $a, $b ): bool {
		return is_string( $a ) && is_string( $b ) && 0 === strcasecmp( $a, $b );
	}

	/**
	 * @param array<string,string> $network Entry from networks().
	 * @return array<string,mixed> v2 PaymentRequirements.
	 */
	public static function requirements_v2( array $network, string $amount, string $pay_to ): array {
		return array(
			'scheme'            => 'exact',
			'network'           => $network['caip2'],
			'amount'            => $amount,
			'asset'             => $network['asset'],
			'payTo'             => $pay_to,
			'maxTimeoutSeconds' => self::MAX_TIMEOUT,
			'extra'             => array(
				'name'    => $network['name'],
				'version' => $network['version'],
			),
		);
	}

	/**
	 * @param array<string,string> $network Entry from networks().
	 * @return array<string,mixed> v1 PaymentRequirements.
	 */
	public static function requirements_v1( array $network, string $amount, string $pay_to, string $url, string $description, string $mime = 'text/html' ): array {
		return array(
			'scheme'            => 'exact',
			'network'           => $network['v1'],
			'maxAmountRequired' => $amount,
			'resource'          => $url,
			'description'       => $description,
			'mimeType'          => $mime,
			'payTo'             => $pay_to,
			'maxTimeoutSeconds' => self::MAX_TIMEOUT,
			'asset'             => $network['asset'],
			'extra'             => array(
				'name'    => $network['name'],
				'version' => $network['version'],
			),
		);
	}

	/**
	 * Builds a 402 challenge for both protocol versions. v2 clients read the
	 * header, v1 clients read the body, and both coexist on one response.
	 *
	 * @param array<string,string> $network Entry from networks().
	 * @return array{header:string,body:array<string,mixed>}
	 */
	public static function challenge( array $network, string $amount, string $pay_to, string $url, string $description, string $error, string $contact = '', string $mime = 'text/html' ): array {
		$required = array(
			'x402Version' => 2,
			'error'       => $error,
			'resource'    => array(
				'url'         => $url,
				'description' => $description,
				'mimeType'    => $mime,
			),
			'accepts'     => array( self::requirements_v2( $network, $amount, $pay_to ) ),
		);

		$body = array(
			'x402Version' => 1,
			'error'       => $error,
			'accepts'     => array( self::requirements_v1( $network, $amount, $pay_to, $url, $description, $mime ) ),
		);
		if ( '' !== $contact ) {
			$body['licensingContact'] = $contact;
		}

		return array(
			'header' => self::encode( $required ),
			'body'   => $body,
		);
	}

	/** @param array<string,mixed> $data Payload. */
	public static function encode( array $data ): string {
		return base64_encode( (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) );
	}

	/**
	 * Decodes a PAYMENT-SIGNATURE or X-PAYMENT header. Returns the payload array,
	 * or null when it is not a well-formed payment of a supported version.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function decode_payment( string $raw ): ?array {
		if ( '' === $raw || strlen( $raw ) > self::MAX_HEADER_BYTES ) {
			return null;
		}
		$json = base64_decode( $raw, true );
		if ( false === $json ) {
			return null;
		}
		$payment = json_decode( $json, true, 16 );
		if ( ! is_array( $payment ) || ! in_array( $payment['x402Version'] ?? null, array( 1, 2 ), true ) ) {
			return null;
		}

		$auth = $payment['payload']['authorization'] ?? null;
		if ( ! is_array( $auth ) || ! is_string( $payment['payload']['signature'] ?? null ) ) {
			return null;
		}
		foreach ( array( 'from', 'to', 'value', 'validAfter', 'validBefore', 'nonce' ) as $field ) {
			if ( ! is_string( $auth[ $field ] ?? null ) ) {
				return null;
			}
		}
		return $payment;
	}

	/**
	 * Checks a decoded payment against what this server asks for, before spending
	 * an outbound call. Returns null when it matches, or an x402 error code.
	 *
	 * The facilitator repeats these checks; doing them here keeps a misbehaving
	 * facilitator from accepting a payment to the wrong wallet or for less money.
	 *
	 * @param array<string,mixed>  $payment Decoded payment.
	 * @param array<string,string> $network Entry from networks().
	 */
	public static function check_payment( array $payment, array $network, string $amount, string $pay_to ): ?string {
		if ( 2 === $payment['x402Version'] ) {
			$accepted = $payment['accepted'] ?? null;
			if ( ! is_array( $accepted ) ) {
				return 'invalid_payload';
			}
			if ( 'exact' !== ( $accepted['scheme'] ?? '' ) ) {
				return 'invalid_scheme';
			}
			if ( ( $accepted['network'] ?? '' ) !== $network['caip2'] ) {
				return 'invalid_network';
			}
			if ( ! self::same_address( $accepted['asset'] ?? '', $network['asset'] ) ) {
				return 'invalid_payment_requirements';
			}
			if ( ! self::same_address( $accepted['payTo'] ?? '', $pay_to ) ) {
				return 'invalid_exact_evm_payload_recipient_mismatch';
			}
			if ( (string) ( $accepted['amount'] ?? '' ) !== $amount ) {
				return 'invalid_exact_evm_payload_authorization_value_mismatch';
			}
		} else {
			if ( 'exact' !== ( $payment['scheme'] ?? '' ) ) {
				return 'invalid_scheme';
			}
			if ( ( $payment['network'] ?? '' ) !== $network['v1'] ) {
				return 'invalid_network';
			}
		}

		$auth = $payment['payload']['authorization'];
		if ( ! self::same_address( $auth['to'], $pay_to ) ) {
			return 'invalid_exact_evm_payload_recipient_mismatch';
		}
		if ( $auth['value'] !== $amount ) {
			return 'invalid_exact_evm_payload_authorization_value_mismatch';
		}
		return self::is_address( $auth['from'] ) ? null : 'invalid_payload';
	}

	/**
	 * Body for the facilitator's /verify and /settle. Requirements are always this
	 * server's, never what the client echoed back.
	 *
	 * @param array<string,mixed>  $payment Decoded payment.
	 * @param array<string,string> $network Entry from networks().
	 * @return array<string,mixed>
	 */
	public static function facilitator_body( array $payment, array $network, string $amount, string $pay_to, string $url, string $description, string $mime = 'text/html' ): array {
		$version = $payment['x402Version'];
		return array(
			'x402Version'         => $version,
			'paymentPayload'      => $payment,
			'paymentRequirements' => 2 === $version
				? self::requirements_v2( $network, $amount, $pay_to )
				: self::requirements_v1( $network, $amount, $pay_to, $url, $description, $mime ),
		);
	}

	/**
	 * Reads a /settle response into one of three outcomes:
	 * paid (funds moved), pending (broadcast, confirmation unknown) or failed.
	 *
	 * @param array<string,mixed>|null $response Decoded response, null on HTTP failure.
	 * @return array{status:string,transaction:string,payer:string,reason:string}
	 */
	public static function read_settlement( ?array $response ): array {
		$tx    = is_string( $response['transaction'] ?? null ) ? $response['transaction'] : '';
		$payer = is_string( $response['payer'] ?? null ) ? $response['payer'] : '';
		$why   = is_string( $response['errorReason'] ?? null ) ? $response['errorReason'] : '';

		if ( true === ( $response['success'] ?? null ) && '' !== $tx ) {
			$status = 'paid';
		} elseif ( 'settlement_pending' === $why && '' !== $tx ) {
			$status = 'pending';
		} else {
			$status = 'failed';
			$why    = '' !== $why ? $why : 'unexpected_settle_error';
		}

		return array(
			'status'      => $status,
			'transaction' => $tx,
			'payer'       => $payer,
			'reason'      => $why,
		);
	}
}
