<?php
/**
 * Decides what happens to a bot request. No WordPress dependency.
 *
 * @package AIBotPaywall
 */

namespace AIBotPaywall;

defined( 'ABSPATH' ) || defined( 'AI_BOT_PAYWALL_TESTING' ) || exit;

final class Rules {

	const ACTIONS = array( 'allow', 'charge', 'block' );

	/** Atomic units per whole token. USDC has six decimals. */
	const ATOMIC = 1000000;

	/**
	 * Parses content rules, one per line: `path:/premium/ charge 0.05`,
	 * `category:news allow`, `type:page block`. Lines starting with # are notes.
	 *
	 * @return array{rules:array<int,array<string,string>>,errors:string[]}
	 */
	public static function parse_content_rules( string $text ): array {
		$rules  = array();
		$errors = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $line );
			$match = explode( ':', $parts[0], 2 );
			$kind  = $match[0];
			$value = isset( $match[1] ) ? $match[1] : '';
			$act   = isset( $parts[1] ) ? $parts[1] : '';
			$price = isset( $parts[2] ) ? $parts[2] : '';

			$valid = in_array( $kind, array( 'path', 'category', 'type' ), true )
				&& '' !== $value
				&& in_array( $act, self::ACTIONS, true )
				&& count( $parts ) <= 3
				&& ( '' === $price || ( 'charge' === $act && self::valid_price( $price ) ) )
				&& ( 'path' !== $kind || '/' === $value[0] );

			if ( ! $valid ) {
				$errors[] = $line;
				continue;
			}
			$rules[] = array(
				'kind'   => $kind,
				'value'  => $value,
				'action' => $act,
				'price'  => $price,
			);
		}

		return array(
			'rules'  => $rules,
			'errors' => $errors,
		);
	}

	/** Whether a string is a positive decimal price with at most six decimals. */
	public static function valid_price( string $price ): bool {
		return 1 === preg_match( '/^\d{1,9}(\.\d{1,6})?$/', $price ) && (float) $price > 0;
	}

	/**
	 * Decides the action for a bot. Priority:
	 * 1. Search engines not proven fake are always free: SEO beats revenue.
	 * 2. The owner's rule for that exact bot.
	 * 3. Social and service bots stay free.
	 * 4. First matching content rule.
	 * 5. The policy for the bot's class.
	 *
	 * @param array<string,mixed> $request  bot_key, class, identity, path, post_type, categories.
	 * @param array<string,mixed> $settings policies, bot_rules, default_price.
	 * @param array<int,array<string,string>> $content_rules Parsed rules.
	 * @return array{action:string,price:string,source:string}
	 */
	public static function decide( array $request, array $settings, array $content_rules ): array {
		$class    = (string) $request['class'];
		$is_fake  = 'fake' === ( $request['identity'] ?? '' );
		$default  = (string) ( $settings['default_price'] ?? '0.01' );

		if ( 'search' === $class && ! $is_fake ) {
			return self::result( 'allow', '', 'search' );
		}

		// A rule for GPTBot is about the real GPTBot, not whoever borrows its name.
		$bot_rules = (array) ( $settings['bot_rules'] ?? array() );
		$bot_key   = (string) ( $request['bot_key'] ?? '' );
		if ( ! $is_fake && isset( $bot_rules[ $bot_key ]['action'] ) && in_array( $bot_rules[ $bot_key ]['action'], self::ACTIONS, true ) ) {
			return self::result( $bot_rules[ $bot_key ]['action'], (string) ( $bot_rules[ $bot_key ]['price'] ?? '' ), 'bot', $default );
		}

		if ( ! $is_fake && in_array( $class, array( 'social', 'service' ), true ) ) {
			return self::result( 'allow', '', 'class' );
		}

		foreach ( $content_rules as $rule ) {
			if ( self::content_rule_matches( $rule, $request ) ) {
				return self::result( $rule['action'], $rule['price'], 'content', $default );
			}
		}

		$policy_key = $is_fake ? Catalog::FAKE_CLASS : $class;
		$policy     = (array) ( $settings['policies'][ $policy_key ] ?? array() );
		$action     = (string) ( $policy['action'] ?? 'allow' );
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			$action = 'allow';
		}
		return self::result( $action, (string) ( $policy['price'] ?? '' ), 'class', $default );
	}

	/** @param array<string,string> $rule Parsed rule. @param array<string,mixed> $request Request. */
	private static function content_rule_matches( array $rule, array $request ): bool {
		switch ( $rule['kind'] ) {
			case 'path':
				return self::path_matches( (string) ( $request['path'] ?? '' ), $rule['value'] );
			case 'category':
				return in_array( $rule['value'], (array) ( $request['categories'] ?? array() ), true );
			case 'type':
				return $rule['value'] === ( $request['post_type'] ?? '' );
		}
		return false;
	}

	/** Prefix match on whole path segments, so /pricing does not match /pricing-secret. */
	private static function path_matches( string $path, string $prefix ): bool {
		$path    = self::normalize_path( $path );
		$trimmed = rtrim( self::normalize_path( $prefix ), '/' );
		return '' === $trimmed || $path === $trimmed || 0 === strpos( $path, $trimmed . '/' );
	}

	/**
	 * Reduces a URL path to the form WordPress resolves, so a rule cannot be dodged with
	 * %70remium, //premium, /./premium or /PREMIUM, which all reach the same content.
	 */
	public static function normalize_path( string $path ): string {
		$decoded = strtolower( rawurldecode( $path ) );
		$parts   = array();
		foreach ( explode( '/', str_replace( '\\', '/', $decoded ) ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $parts );
			} elseif ( '' !== $segment && '.' !== $segment ) {
				$parts[] = $segment;
			}
		}
		if ( isset( $parts[0] ) && 'index.php' === $parts[0] ) {
			array_shift( $parts );
		}
		return '/' . implode( '/', $parts ) . ( '/' === substr( $decoded, -1 ) && array() !== $parts ? '/' : '' );
	}

	/** @return array{action:string,price:string,source:string} */
	private static function result( string $action, string $price, string $source, string $default = '' ): array {
		return array(
			'action' => $action,
			'price'  => 'charge' === $action ? ( '' !== $price ? $price : $default ) : '',
			'source' => $source,
		);
	}

	/**
	 * Converts a price to atomic USDC. Returns null when it cannot be priced:
	 * BRL without a known exchange rate, or an invalid amount. USD prices avoid
	 * floats entirely. BRL rounds up so the owner never receives less than asked.
	 */
	public static function to_atomic( string $price, string $currency, ?float $usd_brl ): ?string {
		if ( ! self::valid_price( $price ) ) {
			return null;
		}

		if ( 'BRL' === $currency ) {
			if ( null === $usd_brl || $usd_brl <= 0 ) {
				return null;
			}
			return (string) max( 1, (int) ceil( round( (float) $price / $usd_brl * self::ATOMIC, 6 ) ) );
		}

		$parts = explode( '.', $price );
		$whole = ltrim( $parts[0], '0' );
		$frac  = str_pad( isset( $parts[1] ) ? $parts[1] : '', 6, '0' );
		return ltrim( $whole . $frac, '0' );
	}

	/** Atomic USDC as a display number. */
	public static function from_atomic( string $atomic ): float {
		return (float) $atomic / self::ATOMIC;
	}
}
