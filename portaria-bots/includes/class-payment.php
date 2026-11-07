<?php
/**
 * The x402 payment flow for a request that must pay.
 *
 * Order follows the spec's `authorization` flow: check, verify, build the response
 * in a buffer, settle, deliver. Nobody is charged for a page that failed to render,
 * and nobody receives content when settlement fails.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Payment {

	/** Facilitator calls allowed per minute, so invalid payments cannot flood it. */
	const FACILITATOR_BUDGET = 60;

	/** @var array<string,mixed>|null State of the payment waiting for shutdown. */
	private static $pending = null;

	/** Raw payment header, v2 name first. */
	public static function header_value(): string {
		foreach ( array( 'HTTP_PAYMENT_SIGNATURE', 'HTTP_X_PAYMENT' ) as $name ) {
			if ( ! empty( $_SERVER[ $name ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Base64 decoded and validated by X402::decode_payment().
				return trim( (string) wp_unslash( $_SERVER[ $name ] ) );
			}
		}
		return '';
	}

	/**
	 * Handles a request that must pay. Exits unless a verified payment lets the
	 * page render, in which case delivery happens in finish().
	 *
	 * @param array<string,mixed> $request bot_key, class, identity, kind, amount, mime.
	 */
	public static function process( array $request ): void {
		$settings = Settings::get();
		$network  = Settings::network();
		$amount   = (string) $request['amount'];
		$pay_to   = (string) $settings['wallet'];
		$url      = Gate::request_url();
		$mime     = (string) $request['mime'];
		/* translators: %s: site name. */
		$description = sprintf( __( 'Automated access to %s', 'portaria-bots' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$is_live     = 'live' === $request['kind'];

		self::prevent_caching();

		$raw = self::header_value();
		if ( '' === $raw ) {
			if ( $is_live ) {
				Store::bump( $request['bot_key'], $request['class'], $request['identity'], array( 'challenged' => 1 ) );
			}
			self::reject( 402, 'PAYMENT-SIGNATURE header is required', $network, $amount, $pay_to, $url, $description, $mime );
		}

		$payment = X402::decode_payment( $raw );
		if ( null === $payment ) {
			self::reject( 400, 'invalid_payload', $network, $amount, $pay_to, $url, $description, $mime );
		}

		if ( ! Budget::take( 'facilitator', self::FACILITATOR_BUDGET ) ) {
			status_header( 429 );
			header( 'Retry-After: 60' );
			exit;
		}

		if ( $is_live ) {
			Store::bump( $request['bot_key'], $request['class'], $request['identity'], array( 'attempts' => 1 ) );
		}

		$record = array(
			'kind'          => (string) $request['kind'],
			'bot'           => (string) $request['bot_key'],
			'path'          => Gate::request_path(),
			'amount_atomic' => $amount,
			'network'       => $network['caip2'],
			'payer'         => (string) ( $payment['payload']['authorization']['from'] ?? '' ),
		);

		$mismatch = X402::check_payment( $payment, $network, $amount, $pay_to );
		if ( null !== $mismatch ) {
			self::fail( $record, $mismatch, $network, $amount, $pay_to, $url, $description, $mime, (int) $payment['x402Version'] );
		}

		$body     = X402::facilitator_body( $payment, $network, $amount, $pay_to, $url, $description, $mime );
		$verified = Facilitator::verify( $body );
		if ( ! $verified['valid'] ) {
			self::fail( $record, $verified['reason'], $network, $amount, $pay_to, $url, $description, $mime, (int) $payment['x402Version'] );
		}

		// Valid so far: let WordPress build the page, then settle in finish().
		ob_start();
		self::$pending = array(
			'level'       => ob_get_level(),
			'request'     => $request,
			'record'      => $record,
			'body'        => $body,
			'version'     => (int) $payment['x402Version'],
			'network'     => $network,
			'amount'      => $amount,
			'pay_to'      => $pay_to,
			'url'         => $url,
			'description' => $description,
			'mime'        => $mime,
		);
		// Before core's own buffer flush at priority 1.
		add_action( 'shutdown', array( __CLASS__, 'finish' ), 0 );
	}

	/** Settles a verified payment once the response is ready, then delivers it. */
	public static function finish(): void {
		$state = self::$pending;
		if ( null === $state ) {
			return;
		}
		self::$pending = null;

		// A theme that flushed or closed our buffer has already sent content.
		if ( ob_get_level() < $state['level'] || headers_sent() ) {
			Store::record_payment( $state['record'] + self::outcome( 'failed', 'buffer_lost' ) );
			return;
		}

		while ( ob_get_level() > $state['level'] ) {
			ob_end_flush();
		}
		$content = (string) ob_get_clean();

		$status = (int) http_response_code();
		$error  = error_get_last();
		$fatal  = is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true );
		if ( $status >= 300 || $fatal ) {
			// Redirect or error: no resource was delivered, so nothing is charged.
			Store::record_payment( $state['record'] + self::outcome( 'failed', 'resource_not_delivered' ) );
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- Response WordPress already built.
			return;
		}

		$settlement = Facilitator::settle( $state['body'] );
		if ( '' !== $settlement['payer'] ) {
			$state['record']['payer'] = $settlement['payer'];
		}

		Store::record_payment(
			$state['record'] + self::outcome( $settlement['status'], $settlement['reason'], $settlement['transaction'] )
		);

		if ( 'failed' === $settlement['status'] ) {
			self::send_payment_response( $state['version'], $state['network'], $settlement );
			self::reject( 402, $settlement['reason'], $state['network'], $state['amount'], $state['pay_to'], $state['url'], $state['description'], $state['mime'] );
		}

		// Pending means the money may have moved, so the payer gets the page.
		if ( 'live' === $state['request']['kind'] ) {
			Store::bump(
				$state['request']['bot_key'],
				$state['request']['class'],
				$state['request']['identity'],
				array(
					'paid'        => 1,
					'paid_atomic' => $state['amount'],
				)
			);
		}

		header( 'Cache-Control: private, no-store' );
		self::send_payment_response( $state['version'], $state['network'], $settlement );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- Response WordPress already built.
	}

	/** @param array<string,string> $settlement Result of Facilitator::settle(). */
	private static function send_payment_response( int $version, array $network, array $settlement ): void {
		$data = array(
			'success'     => 'failed' !== $settlement['status'],
			'transaction' => $settlement['transaction'],
			'network'     => 2 === $version ? $network['caip2'] : $network['v1'],
			'payer'       => $settlement['payer'],
		);
		if ( '' !== $settlement['reason'] ) {
			$data['errorReason'] = $settlement['reason'];
		}
		header( ( 2 === $version ? 'PAYMENT-RESPONSE: ' : 'X-PAYMENT-RESPONSE: ' ) . X402::encode( $data ) );
	}

	/** @return array{status:string,reason:string,tx:string} */
	private static function outcome( string $status, string $reason, string $tx = '' ): array {
		return array(
			'status' => $status,
			'reason' => $reason,
			'tx'     => $tx,
		);
	}

	/** Records a refused payment and answers 402 with the reason. Does not return. */
	private static function fail( array $record, string $reason, array $network, string $amount, string $pay_to, string $url, string $description, string $mime, int $version ): void {
		Store::record_payment( $record + self::outcome( 'failed', $reason ) );
		self::send_payment_response(
			$version,
			$network,
			array(
				'status'      => 'failed',
				'transaction' => '',
				'payer'       => (string) $record['payer'],
				'reason'      => $reason,
			)
		);
		self::reject( 402, $reason, $network, $amount, $pay_to, $url, $description, $mime );
	}

	/** Sends the x402 challenge. Does not return. */
	private static function reject( int $status, string $error, array $network, string $amount, string $pay_to, string $url, string $description, string $mime ): void {
		$challenge = X402::challenge( $network, $amount, $pay_to, $url, $description, $error, (string) Settings::get()['contact'], $mime );

		status_header( $status );
		header_remove( 'Content-Length' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'PAYMENT-REQUIRED: ' . $challenge['header'] );
		echo wp_json_encode( $challenge['body'], JSON_UNESCAPED_SLASHES );
		exit;
	}

	/** Keeps every charged response out of page caches. */
	private static function prevent_caching(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'portaria: paid response' );
	}
}
