<?php
/**
 * The gate: runs on public GET requests, classifies the client and applies the rule.
 * Regular visitors pass through untouched.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Gate {

	public static function init(): void {
		// Priority 0: before canonical redirects and any template output.
		add_action( 'template_redirect', array( __CLASS__, 'on_template_redirect' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'on_rest_api_init' ), 0 );
	}

	public static function on_template_redirect(): void {
		if ( self::handle_test_endpoint() ) {
			return;
		}
		if ( is_404() || is_robots() || is_favicon() || is_trackback() || is_preview() || get_query_var( 'sitemap' ) ) {
			return;
		}

		$post_type  = '';
		$categories = array();
		if ( is_singular() ) {
			$post_type = (string) get_post_type();
			$terms     = get_the_terms( get_queried_object_id(), 'category' );
			if ( is_array( $terms ) ) {
				$categories = wp_list_pluck( $terms, 'slug' );
			}
		} elseif ( is_category() ) {
			$object     = get_queried_object();
			$categories = isset( $object->slug ) ? array( $object->slug ) : array();
		}

		self::handle( $post_type, $categories, 'text/html' );
	}

	/** The REST API serves the same content as the pages, so it must not be a way around. */
	public static function on_rest_api_init(): void {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return;
		}
		$route = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
		if ( 0 === strpos( ltrim( $route, '/' ), 'wp/v2/' ) ) {
			self::handle( '', array(), 'application/json' );
		}
	}

	/**
	 * @param string[] $categories Category slugs of the requested content.
	 */
	private static function handle( string $post_type, array $categories, string $mime ): void {
		$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_user_logged_in() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only matched against known tokens, never stored or printed.
		$ua = substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 );

		if ( Cache_Compat::answer_self_test( $ua ) ) {
			return;
		}

		$settings  = Settings::get();
		$headers   = self::signature_headers();
		$ip        = Net::client_ip( $_SERVER, (string) $settings['client_ip_header'] );
		$authority = self::authority();

		$bot      = Catalog::classify( $ua );
		$identity = null;

		if ( null === $bot ) {
			// Browser User-Agent. Agents that sign or pay still identify themselves.
			$signed = self::signed_bot( $headers, $authority );
			if ( null !== $signed ) {
				$bot      = $signed;
				$identity = Identity::VERIFIED;
			} elseif ( '' !== Payment::header_value() ) {
				$bot = Catalog::entry( 'payer' );
			} else {
				return;
			}
		}

		if ( null === $identity ) {
			$identity = Identity::resolve( $bot, $ip, $headers, $authority );
		}

		$content = Rules::parse_content_rules( (string) $settings['content_rules'] );
		$action  = Rules::decide(
			array(
				'bot_key'    => $bot['key'],
				'class'      => $bot['class'],
				'identity'   => $identity,
				'path'       => self::request_path(),
				'post_type'  => $post_type,
				'categories' => $categories,
			),
			$settings,
			$content['rules']
		);

		$amount = null;
		if ( 'charge' === $action['action'] ) {
			$amount = Rules::to_atomic( $action['price'], (string) $settings['currency'], Rates::usd_brl() );
		}

		$counts = array( 'hits' => 1 );
		if ( null !== $amount ) {
			$counts['priced']        = 1;
			$counts['priced_atomic'] = $amount;
		}
		Store::bump( $bot['key'], $bot['class'], $identity, $counts );

		if ( ! Settings::is_active() ) {
			return;
		}

		if ( 'block' === $action['action'] ) {
			nocache_headers();
			status_header( 403 );
			exit;
		}

		// Without a price (no exchange rate yet) the safe answer is to let it through.
		if ( null !== $amount ) {
			Payment::process(
				array(
					'kind'     => 'live',
					'bot_key'  => $bot['key'],
					'class'    => $bot['class'],
					'identity' => $identity,
					'amount'   => $amount,
					'mime'     => $mime,
				)
			);
		}
	}

	/**
	 * Identifies a browser-like agent through a valid Web Bot Auth signature.
	 *
	 * @param array<string,string> $headers Lower-case request headers.
	 * @return array<string,mixed>|null Catalog entry.
	 */
	private static function signed_bot( array $headers, string $authority ): ?array {
		if ( empty( $headers['signature-agent'] ) ) {
			return null;
		}
		$bot = Catalog::bot_for_signature_host( Web_Bot_Auth::agent_host( $headers['signature-agent'] ) );
		return null !== $bot && Identity::signature_is_valid( $bot, $headers, $authority ) ? $bot : null;
	}

	/**
	 * The private URL used to prove the entrance works: it charges in any mode and
	 * is recorded apart from real traffic.
	 *
	 * @return bool Whether this request was the test endpoint.
	 */
	private static function handle_test_endpoint(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification -- Public URL authorized by its own secret token.
		$given = isset( $_GET['portaria_test'] ) ? sanitize_text_field( wp_unslash( $_GET['portaria_test'] ) ) : '';
		if ( '' === $given ) {
			return false;
		}

		$settings = Settings::get();
		$amount   = Rules::to_atomic( (string) $settings['default_price'], (string) $settings['currency'], Rates::usd_brl() );
		$token    = (string) $settings['test_token'];
		if ( '' === $token || ! hash_equals( $token, $given ) || null === $amount || ! X402::is_address( (string) $settings['wallet'] ) ) {
			return false;
		}

		Payment::process(
			array(
				'kind'     => 'test',
				'bot_key'  => 'test',
				'class'    => 'script',
				'identity' => Identity::CLAIMED,
				'amount'   => $amount,
				'mime'     => 'application/json',
			)
		);

		// Reached only with a verified payment. Settled during shutdown.
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'ok'      => true,
				'message' => 'Portaria test payment received.',
			)
		);
		exit;
	}

	/** @return array<string,string> Web Bot Auth headers, with lower-case names. */
	private static function signature_headers(): array {
		$headers = array();
		foreach ( array( 'signature', 'signature-input', 'signature-agent' ) as $name ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			if ( isset( $_SERVER[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Signed bytes: sanitizing would change them. Parsed defensively and never printed.
				$headers[ $name ] = substr( (string) wp_unslash( $_SERVER[ $key ] ), 0, 4096 );
			}
		}
		return $headers;
	}

	/** Host the request was sent to, as RFC 9421 normalizes it. */
	private static function authority(): string {
		$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		return (string) preg_replace( '/:(80|443)$/', '', $host );
	}

	/** Path of the request, without the query string. */
	public static function request_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated by wp_parse_url and sanitize below.
		$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? sanitize_text_field( $path ) : '/';
	}

	/** Canonical URL of the request, built on the site's own host rather than the Host header. */
	public static function request_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- esc_url_raw below.
		$uri    = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
		$parts  = (array) wp_parse_url( home_url() );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		// The test token must not be echoed back in the challenge.
		return esc_url_raw( $origin . remove_query_arg( 'portaria_test', $uri ) );
	}
}
