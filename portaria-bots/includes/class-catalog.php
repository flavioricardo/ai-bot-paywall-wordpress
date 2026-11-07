<?php
/**
 * Known crawlers and User-Agent classification. No WordPress dependency.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || defined( 'PORTARIA_TESTING' ) || exit;

final class Catalog {

	/** Classes the owner can price or block. Everything else is free by default. */
	const POLICY_CLASSES = array( 'ai_training', 'ai_search', 'ai_agent', 'seo', 'script' );

	/** Identity of a verifiable bot that failed verification. */
	const FAKE_CLASS = 'fake';

	/**
	 * Catalog entries: UA token (matched case-insensitively), display name,
	 * class, operator, and how to verify it. Earlier entries win, so specific
	 * tokens must come before broader ones.
	 *
	 * rdns: allowed reverse DNS suffixes. ranges: published IP list URLs.
	 * wba: hosts allowed to sign as this bot through Web Bot Auth.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function bots(): array {
		$google = array( 'googlebot.com', 'google.com', 'googleusercontent.com' );
		$bing   = array( 'search.msn.com' );

		return array(
			// Search engines. Verified ones are never charged.
			'google_inspection' => array( 'token' => 'Google-InspectionTool', 'name' => 'Google-InspectionTool', 'class' => 'search', 'operator' => 'Google', 'rdns' => $google ),
			'adsbot_google'     => array( 'token' => 'AdsBot-Google', 'name' => 'AdsBot-Google', 'class' => 'search', 'operator' => 'Google', 'rdns' => $google ),
			'mediapartners'     => array( 'token' => 'Mediapartners-Google', 'name' => 'Mediapartners-Google', 'class' => 'search', 'operator' => 'Google', 'rdns' => $google ),
			'storebot_google'   => array( 'token' => 'Storebot-Google', 'name' => 'Storebot-Google', 'class' => 'search', 'operator' => 'Google', 'rdns' => $google ),
			'googleother'       => array( 'token' => 'GoogleOther', 'name' => 'GoogleOther', 'class' => 'search', 'operator' => 'Google', 'rdns' => $google ),
			'googlebot'         => array( 'token' => 'Googlebot', 'name' => 'Googlebot', 'class' => 'search', 'operator' => 'Google', 'rdns' => $google ),
			'bingbot'           => array( 'token' => 'bingbot', 'name' => 'Bingbot', 'class' => 'search', 'operator' => 'Microsoft', 'rdns' => $bing ),
			'adidxbot'          => array( 'token' => 'AdIdxBot', 'name' => 'AdIdxBot', 'class' => 'search', 'operator' => 'Microsoft', 'rdns' => $bing ),
			'applebot'          => array( 'token' => 'Applebot', 'name' => 'Applebot', 'class' => 'search', 'operator' => 'Apple', 'rdns' => array( 'applebot.apple.com' ) ),
			'duckduckbot'       => array( 'token' => 'DuckDuckBot', 'name' => 'DuckDuckBot', 'class' => 'search', 'operator' => 'DuckDuckGo' ),
			'yandexbot'         => array( 'token' => 'YandexBot', 'name' => 'YandexBot', 'class' => 'search', 'operator' => 'Yandex', 'rdns' => array( 'yandex.ru', 'yandex.net', 'yandex.com' ) ),
			'baiduspider'       => array( 'token' => 'Baiduspider', 'name' => 'Baiduspider', 'class' => 'search', 'operator' => 'Baidu', 'rdns' => array( 'baidu.com', 'baidu.jp' ) ),

			// AI crawlers collecting training data.
			'gptbot'            => array( 'token' => 'GPTBot', 'name' => 'GPTBot', 'class' => 'ai_training', 'operator' => 'OpenAI', 'ranges' => array( 'https://openai.com/gptbot.json' ) ),
			'claudebot'         => array( 'token' => 'ClaudeBot', 'name' => 'ClaudeBot', 'class' => 'ai_training', 'operator' => 'Anthropic' ),
			'anthropic_ai'      => array( 'token' => 'anthropic-ai', 'name' => 'anthropic-ai', 'class' => 'ai_training', 'operator' => 'Anthropic' ),
			'ccbot'             => array( 'token' => 'CCBot', 'name' => 'CCBot', 'class' => 'ai_training', 'operator' => 'Common Crawl' ),
			'bytespider'        => array( 'token' => 'Bytespider', 'name' => 'Bytespider', 'class' => 'ai_training', 'operator' => 'ByteDance' ),
			'amazonbot'         => array( 'token' => 'Amazonbot', 'name' => 'Amazonbot', 'class' => 'ai_training', 'operator' => 'Amazon', 'rdns' => array( 'crawl.amazonbot.amazon' ) ),
			'meta_external'     => array( 'token' => 'meta-externalagent', 'name' => 'Meta-ExternalAgent', 'class' => 'ai_training', 'operator' => 'Meta' ),
			'facebookbot'       => array( 'token' => 'FacebookBot', 'name' => 'FacebookBot', 'class' => 'ai_training', 'operator' => 'Meta' ),
			'cohere'            => array( 'token' => 'cohere-', 'name' => 'Cohere', 'class' => 'ai_training', 'operator' => 'Cohere' ),
			'diffbot'           => array( 'token' => 'Diffbot', 'name' => 'Diffbot', 'class' => 'ai_training', 'operator' => 'Diffbot' ),
			'imagesift'         => array( 'token' => 'ImagesiftBot', 'name' => 'ImagesiftBot', 'class' => 'ai_training', 'operator' => 'Hive' ),
			'omgili'            => array( 'token' => 'omgili', 'name' => 'Omgilibot', 'class' => 'ai_training', 'operator' => 'Webz.io' ),
			'pangubot'          => array( 'token' => 'PanguBot', 'name' => 'PanguBot', 'class' => 'ai_training', 'operator' => 'Huawei' ),
			'petalbot'          => array( 'token' => 'PetalBot', 'name' => 'PetalBot', 'class' => 'ai_training', 'operator' => 'Huawei' ),
			'timpibot'          => array( 'token' => 'Timpibot', 'name' => 'Timpibot', 'class' => 'ai_training', 'operator' => 'Timpi' ),
			'ai2bot'            => array( 'token' => 'AI2Bot', 'name' => 'AI2Bot', 'class' => 'ai_training', 'operator' => 'Ai2' ),
			'tiktokspider'      => array( 'token' => 'TikTokSpider', 'name' => 'TikTokSpider', 'class' => 'ai_training', 'operator' => 'ByteDance' ),

			// AI search indexes that can cite the site in answers.
			'oai_searchbot'     => array( 'token' => 'OAI-SearchBot', 'name' => 'OAI-SearchBot', 'class' => 'ai_search', 'operator' => 'OpenAI', 'ranges' => array( 'https://openai.com/searchbot.json' ) ),
			'perplexitybot'     => array( 'token' => 'PerplexityBot', 'name' => 'PerplexityBot', 'class' => 'ai_search', 'operator' => 'Perplexity', 'ranges' => array( 'https://www.perplexity.ai/perplexitybot.json' ) ),
			'claude_searchbot'  => array( 'token' => 'Claude-SearchBot', 'name' => 'Claude-SearchBot', 'class' => 'ai_search', 'operator' => 'Anthropic' ),
			'youbot'            => array( 'token' => 'YouBot', 'name' => 'YouBot', 'class' => 'ai_search', 'operator' => 'You.com' ),
			'duckassistbot'     => array( 'token' => 'DuckAssistBot', 'name' => 'DuckAssistBot', 'class' => 'ai_search', 'operator' => 'DuckDuckGo' ),

			// Agents fetching a page on behalf of a person.
			'chatgpt_user'      => array( 'token' => 'ChatGPT-User', 'name' => 'ChatGPT-User', 'class' => 'ai_agent', 'operator' => 'OpenAI', 'ranges' => array( 'https://openai.com/chatgpt-user.json' ), 'wba' => array( 'chatgpt.com' ) ),
			'perplexity_user'   => array( 'token' => 'Perplexity-User', 'name' => 'Perplexity-User', 'class' => 'ai_agent', 'operator' => 'Perplexity', 'ranges' => array( 'https://www.perplexity.ai/perplexity-user.json' ) ),
			'claude_user'       => array( 'token' => 'Claude-User', 'name' => 'Claude-User', 'class' => 'ai_agent', 'operator' => 'Anthropic' ),
			'mistral_user'      => array( 'token' => 'MistralAI-User', 'name' => 'MistralAI-User', 'class' => 'ai_agent', 'operator' => 'Mistral' ),
			'meta_fetcher'      => array( 'token' => 'meta-externalfetcher', 'name' => 'Meta-ExternalFetcher', 'class' => 'ai_agent', 'operator' => 'Meta' ),

			// SEO tools.
			'ahrefsbot'         => array( 'token' => 'AhrefsBot', 'name' => 'AhrefsBot', 'class' => 'seo', 'operator' => 'Ahrefs' ),
			'semrushbot'        => array( 'token' => 'SemrushBot', 'name' => 'SemrushBot', 'class' => 'seo', 'operator' => 'Semrush' ),
			'mj12bot'           => array( 'token' => 'MJ12bot', 'name' => 'MJ12bot', 'class' => 'seo', 'operator' => 'Majestic' ),
			'dotbot'            => array( 'token' => 'DotBot', 'name' => 'DotBot', 'class' => 'seo', 'operator' => 'Moz' ),
			'rogerbot'          => array( 'token' => 'rogerbot', 'name' => 'rogerbot', 'class' => 'seo', 'operator' => 'Moz' ),
			'dataforseo'        => array( 'token' => 'DataForSeoBot', 'name' => 'DataForSeoBot', 'class' => 'seo', 'operator' => 'DataForSEO' ),
			'blexbot'           => array( 'token' => 'BLEXBot', 'name' => 'BLEXBot', 'class' => 'seo', 'operator' => 'WebMeUp' ),
			'screaming_frog'    => array( 'token' => 'Screaming Frog', 'name' => 'Screaming Frog', 'class' => 'seo', 'operator' => 'Screaming Frog' ),
			'serpstatbot'       => array( 'token' => 'serpstatbot', 'name' => 'serpstatbot', 'class' => 'seo', 'operator' => 'Serpstat' ),

			// Link previews.
			'facebook_preview'  => array( 'token' => 'facebookexternalhit', 'name' => 'Facebook preview', 'class' => 'social', 'operator' => 'Meta' ),
			'twitterbot'        => array( 'token' => 'Twitterbot', 'name' => 'Twitterbot', 'class' => 'social', 'operator' => 'X' ),
			'linkedinbot'       => array( 'token' => 'LinkedInBot', 'name' => 'LinkedInBot', 'class' => 'social', 'operator' => 'LinkedIn' ),
			'slackbot'          => array( 'token' => 'Slackbot', 'name' => 'Slackbot', 'class' => 'social', 'operator' => 'Slack' ),
			'whatsapp'          => array( 'token' => 'WhatsApp', 'name' => 'WhatsApp', 'class' => 'social', 'operator' => 'Meta' ),
			'telegrambot'       => array( 'token' => 'TelegramBot', 'name' => 'TelegramBot', 'class' => 'social', 'operator' => 'Telegram' ),
			'discordbot'        => array( 'token' => 'Discordbot', 'name' => 'Discordbot', 'class' => 'social', 'operator' => 'Discord' ),
			'pinterestbot'      => array( 'token' => 'Pinterestbot', 'name' => 'Pinterestbot', 'class' => 'social', 'operator' => 'Pinterest' ),
			'redditbot'         => array( 'token' => 'redditbot', 'name' => 'redditbot', 'class' => 'social', 'operator' => 'Reddit' ),

			// Services a site depends on. Charging these breaks things.
			'wp_rocket'         => array( 'token' => 'WP Rocket', 'name' => 'WP Rocket preload', 'class' => 'service', 'operator' => 'WP Rocket' ),
			'lscache'           => array( 'token' => 'lscache_runner', 'name' => 'LiteSpeed crawler', 'class' => 'service', 'operator' => 'LiteSpeed' ),
			'wordpress'         => array( 'token' => 'WordPress/', 'name' => 'WordPress', 'class' => 'service', 'operator' => 'WordPress' ),
			'jetpack'           => array( 'token' => 'Jetpack', 'name' => 'Jetpack', 'class' => 'service', 'operator' => 'Automattic' ),
			'uptimerobot'       => array( 'token' => 'UptimeRobot', 'name' => 'UptimeRobot', 'class' => 'service', 'operator' => 'UptimeRobot' ),
			'pingdom'           => array( 'token' => 'Pingdom', 'name' => 'Pingdom', 'class' => 'service', 'operator' => 'SolarWinds' ),
			'statuscake'        => array( 'token' => 'StatusCake', 'name' => 'StatusCake', 'class' => 'service', 'operator' => 'StatusCake' ),
			'betterstack'       => array( 'token' => 'Better Stack', 'name' => 'Better Stack', 'class' => 'service', 'operator' => 'Better Stack' ),
			'lighthouse'        => array( 'token' => 'Chrome-Lighthouse', 'name' => 'Lighthouse', 'class' => 'service', 'operator' => 'Google' ),
			'gtmetrix'          => array( 'token' => 'GTmetrix', 'name' => 'GTmetrix', 'class' => 'service', 'operator' => 'GTmetrix' ),
			'feedly'            => array( 'token' => 'Feedly', 'name' => 'Feedly', 'class' => 'service', 'operator' => 'Feedly' ),
			'inoreader'         => array( 'token' => 'Inoreader', 'name' => 'Inoreader', 'class' => 'service', 'operator' => 'Inoreader' ),
			'newsblur'          => array( 'token' => 'NewsBlur', 'name' => 'NewsBlur', 'class' => 'service', 'operator' => 'NewsBlur' ),

			// HTTP libraries and headless browsers: most x402 agents land here.
			'curl'              => array( 'token' => 'curl/', 'name' => 'curl', 'class' => 'script', 'operator' => '' ),
			'wget'              => array( 'token' => 'Wget/', 'name' => 'Wget', 'class' => 'script', 'operator' => '' ),
			'python'            => array( 'token' => 'python-', 'name' => 'Python client', 'class' => 'script', 'operator' => '' ),
			'aiohttp'           => array( 'token' => 'aiohttp', 'name' => 'Python client', 'class' => 'script', 'operator' => '' ),
			'scrapy'            => array( 'token' => 'Scrapy', 'name' => 'Scrapy', 'class' => 'script', 'operator' => '' ),
			'go_http'           => array( 'token' => 'Go-http-client', 'name' => 'Go client', 'class' => 'script', 'operator' => '' ),
			'node_client'       => array( 'token' => 'node-fetch', 'name' => 'Node client', 'class' => 'script', 'operator' => '' ),
			'undici'            => array( 'token' => 'undici', 'name' => 'Node client', 'class' => 'script', 'operator' => '' ),
			'axios'             => array( 'token' => 'axios/', 'name' => 'axios', 'class' => 'script', 'operator' => '' ),
			'okhttp'            => array( 'token' => 'okhttp', 'name' => 'OkHttp', 'class' => 'script', 'operator' => '' ),
			'java'              => array( 'token' => 'Java/', 'name' => 'Java client', 'class' => 'script', 'operator' => '' ),
			'headless'          => array( 'token' => 'HeadlessChrome', 'name' => 'Headless Chrome', 'class' => 'script', 'operator' => '' ),
			'phantomjs'         => array( 'token' => 'PhantomJS', 'name' => 'PhantomJS', 'class' => 'script', 'operator' => '' ),
		);
	}

	/**
	 * Entries for traffic the catalog cannot name. Stored as a fixed key: never
	 * keep a raw User-Agent, which is untrusted and could identify a person.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function generic(): array {
		return array(
			'no_ua'     => array( 'name' => 'No User-Agent', 'class' => 'script', 'operator' => '' ),
			'other_bot' => array( 'name' => 'Other bot', 'class' => 'script', 'operator' => '' ),
			'unknown'   => array( 'name' => 'Unknown client', 'class' => 'script', 'operator' => '' ),
			'payer'     => array( 'name' => 'Paying client', 'class' => 'script', 'operator' => '' ),
		);
	}

	/**
	 * Classifies a User-Agent. Returns null for a regular browser.
	 *
	 * Scrapers that copy a browser User-Agent look human here. That is a known
	 * limit of any User-Agent based gate and is stated in the readme.
	 *
	 * @return array<string,mixed>|null Catalog entry plus its `key`.
	 */
	public static function classify( string $ua ): ?array {
		$ua = trim( $ua );
		if ( '' === $ua ) {
			return self::entry( 'no_ua' );
		}

		foreach ( self::bots() as $key => $bot ) {
			if ( false !== stripos( $ua, $bot['token'] ) ) {
				return array( 'key' => $key ) + $bot;
			}
		}

		if ( preg_match( '/bot\b|crawl|spider|scrape/i', $ua ) ) {
			return self::entry( 'other_bot' );
		}

		return preg_match( '#^(Mozilla|Opera)/#', $ua ) ? null : self::entry( 'unknown' );
	}

	/**
	 * Looks up a bot by key, including generic entries.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function entry( string $key ): ?array {
		$bots = self::bots() + self::generic();
		return isset( $bots[ $key ] ) ? array( 'key' => $key ) + $bots[ $key ] : null;
	}

	/** Bot allowed to sign as this Web Bot Auth host, if any. */
	public static function bot_for_signature_host( string $host ): ?array {
		foreach ( self::bots() as $key => $bot ) {
			if ( ! empty( $bot['wba'] ) && in_array( $host, $bot['wba'], true ) ) {
				return array( 'key' => $key ) + $bot;
			}
		}
		return null;
	}

	/**
	 * UA tokens that must skip a full-page cache so the gate can see them.
	 * Search engines stay out: bypassing cache for them would slow crawling and
	 * they are never charged anyway.
	 *
	 * @return string[]
	 */
	public static function cache_bypass_tokens(): array {
		$tokens = array();
		foreach ( self::bots() as $bot ) {
			if ( in_array( $bot['class'], self::POLICY_CLASSES, true ) ) {
				$tokens[] = $bot['token'];
			}
		}
		return $tokens;
	}

	/** Apache-compatible alternation of the given tokens. */
	public static function ua_alternation( array $tokens ): string {
		$quoted = array();
		foreach ( $tokens as $token ) {
			$quoted[] = str_replace( ' ', '\\s', preg_quote( $token, '#' ) );
		}
		return '(' . implode( '|', $quoted ) . ')';
	}
}
