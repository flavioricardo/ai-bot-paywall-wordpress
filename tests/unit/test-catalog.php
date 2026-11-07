<?php
use Portaria\Catalog;

$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
t_eq( null, Catalog::classify( $chrome ), 'browser is human' );
t_eq( 'googlebot', Catalog::classify( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' )['key'], 'googlebot' );
t_eq( 'google_inspection', Catalog::classify( 'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)' )['key'], 'specific google token wins' );
t_eq( 'gptbot', Catalog::classify( 'Mozilla/5.0 AppleWebKit/537.36; compatible; GPTBot/1.3; +https://openai.com/gptbot' )['key'], 'gptbot' );
t_eq( 'ai_agent', Catalog::classify( 'Mozilla/5.0; ChatGPT-User/1.0' )['class'], 'chatgpt-user is agent' );
t_eq( 'no_ua', Catalog::classify( '' )['key'], 'empty ua' );
t_eq( 'curl', Catalog::classify( 'curl/8.4.0' )['key'], 'curl' );
t_eq( 'script', Catalog::classify( 'Python/3.11 aiohttp/3.9' )['class'], 'aiohttp is script' );
t_eq( 'other_bot', Catalog::classify( 'Mozilla/5.0 (compatible; RandomBot/1.0)' )['key'], 'generic bot word' );
t_eq( 'unknown', Catalog::classify( 'MyApp/1.2' )['key'], 'unknown non-browser' );
t_eq( null, Catalog::classify( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1' ), 'iphone human' );
t_eq( 'service', Catalog::classify( 'WordPress/6.8; https://example.com' )['class'], 'wordpress loopback is service' );
t_eq( 'wp_rocket', Catalog::classify( 'WP Rocket/Preload' )['key'], 'cache preloader stays free' );
t_eq( 'chatgpt_user', Catalog::bot_for_signature_host( 'chatgpt.com' )['key'], 'signature host maps to bot' );
t_eq( null, Catalog::bot_for_signature_host( 'evil.example' ), 'unknown signature host' );

$tokens = Catalog::cache_bypass_tokens();
t_eq( true, in_array( 'GPTBot', $tokens, true ), 'ai bot bypasses cache' );
t_eq( false, in_array( 'Googlebot', $tokens, true ), 'search stays cached' );
t_eq( '(GPT\.Bot|Screaming\sFrog)', Catalog::ua_alternation( array( 'GPT.Bot', 'Screaming Frog' ) ), 'alternation escapes' );

foreach ( Catalog::bots() as $key => $bot ) {
	foreach ( array( 'token', 'name', 'class', 'operator' ) as $field ) {
		t_eq( true, isset( $bot[ $field ] ), "{$key} has {$field}" );
	}
	t_eq( true, strlen( $key ) <= 40, "{$key} fits the column" );
}
