<?php
/**
 * Full-page cache compatibility. A cache that serves HTML before PHP runs hides bots
 * from the gate: they are neither counted nor charged.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Cache_Compat {

	const MARKER        = 'Portaria';
	const RESULT_OPTION = 'portaria_cache_check';
	const TOKEN_KEY     = 'portaria_selftest_token';
	const CHECK_HEADER  = 'X-Portaria-Check';

	public static function init(): void {
		add_filter( 'rocket_cache_reject_ua', array( __CLASS__, 'add_rocket_user_agents' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'sync' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'sync' ) );
	}

	/**
	 * @param mixed $agents User agents WP Rocket refuses to serve from cache.
	 * @return array<int,string>
	 */
	public static function add_rocket_user_agents( $agents ): array {
		$agents = is_array( $agents ) ? $agents : array();
		if ( empty( Settings::get()['cache_bypass'] ) ) {
			return $agents;
		}
		foreach ( Catalog::cache_bypass_tokens() as $token ) {
			$agents[] = str_replace( ' ', '\\s', preg_quote( $token, '#' ) );
		}
		return $agents;
	}

	/** Applies the current choice to whichever cache is installed. */
	public static function sync(): void {
		self::refresh_rocket();
		if ( ! empty( Settings::get()['cache_bypass'] ) && defined( 'LSCWP_V' ) ) {
			self::write_litespeed_rules();
		} else {
			self::remove_litespeed_rules();
		}
	}

	/** Undoes every change made outside the plugin's own data. */
	public static function remove_all(): void {
		remove_filter( 'rocket_cache_reject_ua', array( __CLASS__, 'add_rocket_user_agents' ) );
		self::refresh_rocket();
		self::remove_litespeed_rules();
	}

	/** WP Rocket copies the filter's result into its config file and .htaccess. */
	private static function refresh_rocket(): void {
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
		if ( function_exists( 'flush_rocket_htaccess' ) ) {
			flush_rocket_htaccess();
		}
	}

	/** @param string[] $tokens User-Agent tokens. */
	public static function litespeed_block( array $tokens ): string {
		return '# BEGIN ' . self::MARKER . "\n"
			. "<IfModule LiteSpeed>\n"
			. "RewriteEngine on\n"
			. 'RewriteCond %{HTTP_USER_AGENT} ' . Catalog::ua_alternation( $tokens ) . " [NC]\n"
			. "RewriteRule .* - [E=Cache-Control:no-cache]\n"
			. "</IfModule>\n"
			. '# END ' . self::MARKER . "\n";
	}

	/** Removes this plugin's block from .htaccess content. */
	public static function strip_block( string $content ): string {
		$pattern = '/^# BEGIN ' . self::MARKER . '\R.*?^# END ' . self::MARKER . '\R?/ms';
		return (string) preg_replace( $pattern, '', $content );
	}

	private static function htaccess_path(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	/** The block goes first: it must run before LiteSpeed Cache's own rules. */
	private static function write_litespeed_rules(): void {
		$path = self::htaccess_path();
		if ( ! file_exists( $path ) || ! wp_is_writable( $path ) ) {
			return;
		}
		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$updated = self::litespeed_block( Catalog::cache_bypass_tokens() ) . self::strip_block( $current );
		if ( $updated !== $current ) {
			file_put_contents( $path, $updated, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	private static function remove_litespeed_rules(): void {
		$path = self::htaccess_path();
		if ( ! file_exists( $path ) || ! wp_is_writable( $path ) ) {
			return;
		}
		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$updated = self::strip_block( $current );
		if ( $updated !== $current ) {
			file_put_contents( $path, $updated, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/** @return string[] Names of page caches found on this site. */
	public static function detected(): array {
		$found = array();
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$found[] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			$found[] = 'LiteSpeed Cache';
		}
		if ( defined( 'W3TC' ) ) {
			$found[] = 'W3 Total Cache';
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			$found[] = 'WP Super Cache';
		}
		if ( array() === $found && defined( 'WP_CACHE' ) && WP_CACHE ) {
			$found[] = 'WP_CACHE';
		}
		return $found;
	}

	/**
	 * Answers the self-test: confirms that a bot request reached PHP.
	 *
	 * @return bool Whether this request was the self-test.
	 */
	public static function answer_self_test( string $ua ): bool {
		if ( ! preg_match( '#PortariaSelfTest/([a-f0-9]{32})#', $ua, $m ) ) {
			return false;
		}
		$token = get_transient( self::TOKEN_KEY );
		if ( ! is_string( $token ) || ! hash_equals( $token, $m[1] ) ) {
			return false;
		}
		header( self::CHECK_HEADER . ': ' . $token );
		return true;
	}

	/**
	 * Requests a public page as a browser, to warm any cache, then as a bot. If the
	 * bot's answer lacks the token, something served it before PHP ran. This works
	 * for every plugin, server cache and CDN, not only the ones integrated above.
	 *
	 * @return array<string,mixed> ok, unknown, url, time.
	 */
	public static function run_self_test(): array {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::TOKEN_KEY, $token, 5 * MINUTE_IN_SECONDS );

		$url     = self::test_url();
		$browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
		$args    = array(
			'timeout'     => 15,
			'redirection' => 3,
			'sslverify'   => false, // Loopback to this same site, often behind a local certificate.
		);

		wp_remote_get( $url, $args + array( 'user-agent' => $browser ) );
		wp_remote_get( $url, $args + array( 'user-agent' => $browser ) );
		$response = wp_remote_get(
			$url,
			$args + array( 'user-agent' => 'Mozilla/5.0 (compatible; GPTBot/1.3; +https://openai.com/gptbot) PortariaSelfTest/' . $token )
		);

		delete_transient( self::TOKEN_KEY );

		$reachable = ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) > 0;
		$result    = array(
			'time'      => time(),
			'url'       => $url,
			'reachable' => $reachable,
			'ok'        => $reachable && wp_remote_retrieve_header( $response, strtolower( self::CHECK_HEADER ) ) === $token,
		);
		update_option( self::RESULT_OPTION, $result, false );
		return $result;
	}

	/** Newest post, which page caches are likely to hold, or the home page. */
	private static function test_url(): string {
		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);
		$url   = array() !== $posts ? get_permalink( $posts[0] ) : false;
		return is_string( $url ) ? $url : home_url( '/' );
	}

	/** @return array<string,mixed>|null The last self-test result. */
	public static function last_result(): ?array {
		$result = get_option( self::RESULT_OPTION );
		return is_array( $result ) ? $result : null;
	}
}
