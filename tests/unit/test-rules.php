<?php
use AIBotPaywall\Rules;

$settings = array(
	'default_price' => '0.01',
	'policies'      => array(
		'ai_training' => array( 'action' => 'charge', 'price' => '' ),
		'ai_search'   => array( 'action' => 'allow', 'price' => '' ),
		'fake'        => array( 'action' => 'charge', 'price' => '0.50' ),
		'seo'         => array( 'action' => 'block', 'price' => '' ),
		'script'      => array( 'action' => 'charge', 'price' => '0.02' ),
	),
	'bot_rules'     => array( 'bytespider' => array( 'action' => 'block', 'price' => '' ) ),
);
$req = function ( $class, $key, $identity = 'claimed', $extra = array() ) {
	return $extra + array(
		'class'      => $class,
		'bot_key'    => $key,
		'identity'   => $identity,
		'path'       => '/hello/',
		'post_type'  => 'post',
		'categories' => array( 'news' ),
	);
};
$none = array();

$d = Rules::decide( $req( 'search', 'googlebot', 'verified' ), $settings, $none );
t_eq( 'allow', $d['action'], 'verified search free' );
$d = Rules::decide( $req( 'search', 'googlebot', 'unknown' ), $settings, $none );
t_eq( 'allow', $d['action'], 'unverifiable search free, SEO first' );
$d = Rules::decide( $req( 'search', 'googlebot', 'fake' ), $settings, $none );
t_eq( array( 'charge', '0.50' ), array( $d['action'], $d['price'] ), 'fake googlebot uses fake policy' );
$d = Rules::decide( $req( 'search', 'googlebot', 'verified' ), $settings, Rules::parse_content_rules( 'path:/ block' )['rules'] );
t_eq( 'allow', $d['action'], 'content rule cannot touch real search' );

$d = Rules::decide( $req( 'ai_training', 'gptbot' ), $settings, $none );
t_eq( array( 'charge', '0.01' ), array( $d['action'], $d['price'] ), 'class policy uses default price' );
$d = Rules::decide( $req( 'ai_search', 'oai_searchbot' ), $settings, $none );
t_eq( 'allow', $d['action'], 'ai search allowed by policy' );
$d = Rules::decide( $req( 'script', 'curl' ), $settings, $none );
t_eq( '0.02', $d['price'], 'class price' );
$d = Rules::decide( $req( 'ai_training', 'bytespider' ), $settings, Rules::parse_content_rules( 'path:/ allow' )['rules'] );
t_eq( array( 'block', 'bot' ), array( $d['action'], $d['source'] ), 'bot rule beats content rule' );
$d = Rules::decide( $req( 'social', 'twitterbot' ), $settings, Rules::parse_content_rules( 'path:/ charge' )['rules'] );
t_eq( 'allow', $d['action'], 'social stays free against content rule' );
$custom = array( 'bot_rules' => array( 'twitterbot' => array( 'action' => 'charge', 'price' => '0.03' ) ) ) + $settings;
$d = Rules::decide( $req( 'social', 'twitterbot' ), $custom, $none );
t_eq( array( 'charge', '0.03' ), array( $d['action'], $d['price'] ), 'owner can charge a social bot explicitly' );
$d = Rules::decide( $req( 'social', 'twitterbot', 'fake' ), $settings, $none );
t_eq( 'charge', $d['action'], 'fake social not free' );
$d = Rules::decide( $req( 'ai_training', 'unlisted' ), array(), $none );
t_eq( 'allow', $d['action'], 'no policy allows' );

$text   = "# note\npath:/premium/ charge 0.05\ncategory:news allow\ntype:page block\n\nbad line\npath:/x charge abc\nrule:/x allow\npath:relative allow\npath:/y allow 0.10\npath:/z charge 0.1 extra";
$parsed = Rules::parse_content_rules( $text );
t_eq( 3, count( $parsed['rules'] ), 'three valid rules' );
t_eq(
	array( 'bad line', 'path:/x charge abc', 'rule:/x allow', 'path:relative allow', 'path:/y allow 0.10', 'path:/z charge 0.1 extra' ),
	$parsed['errors'],
	'bad rules reported'
);

$rules = $parsed['rules'];
$d = Rules::decide( $req( 'ai_search', 'x', 'claimed', array( 'path' => '/premium/report/' ) ), $settings, $rules );
t_eq( array( 'charge', '0.05', 'content' ), array( $d['action'], $d['price'], $d['source'] ), 'path rule' );
$d = Rules::decide( $req( 'ai_search', 'x', 'claimed', array( 'path' => '/premium-other/', 'categories' => array() ) ), $settings, $rules );
t_eq( 'class', $d['source'], 'prefix respects segment boundary' );
$d = Rules::decide( $req( 'ai_training', 'x', 'claimed', array( 'path' => '/a/' ) ), $settings, $rules );
t_eq( array( 'allow', 'content' ), array( $d['action'], $d['source'] ), 'category rule' );
$d = Rules::decide( $req( 'ai_training', 'x', 'claimed', array( 'path' => '/a/', 'categories' => array(), 'post_type' => 'page' ) ), $settings, $rules );
t_eq( 'block', $d['action'], 'type rule' );

$fake_rule = array( 'bot_rules' => array( 'gptbot' => array( 'action' => 'allow', 'price' => '' ) ) ) + $settings;
$d = Rules::decide( $req( 'ai_training', 'gptbot', 'verified' ), $fake_rule, $none );
t_eq( 'allow', $d['action'], 'bot rule applies to the real bot' );
$d = Rules::decide( $req( 'ai_training', 'gptbot', 'fake' ), $fake_rule, $none );
t_eq( array( 'charge', '0.50' ), array( $d['action'], $d['price'] ), 'bot rule does not apply to an impostor' );

foreach ( array( '/premium/a/', '/%70remium/a/', '//premium//a/', '/./premium/a/', '/x/../premium/a/', '/PREMIUM/a/', '/index.php/premium/a/', '\\premium\\a/' ) as $dodge ) {
	t_eq( '/premium/a/', Rules::normalize_path( $dodge ), "normalize {$dodge}" );
}
t_eq( '/', Rules::normalize_path( '/' ), 'normalize root' );
t_eq( '/', Rules::normalize_path( '' ), 'normalize empty' );
t_eq( '/', Rules::normalize_path( '/index.php' ), 'normalize index' );
t_eq( '/a', Rules::normalize_path( '/a' ), 'normalize keeps no trailing slash' );
$d = Rules::decide( $req( 'ai_search', 'x', 'claimed', array( 'path' => '/%70remium/a/' ) ), $settings, $rules );
t_eq( 'content', $d['source'], 'encoded path still matches' );

t_eq( '10000', Rules::to_atomic( '0.01', 'USD', null ), 'usd cents' );
t_eq( '1', Rules::to_atomic( '0.000001', 'USD', null ), 'smallest unit' );
t_eq( '1500000', Rules::to_atomic( '1.5', 'USD', null ), 'whole and fraction' );
t_eq( '1000000', Rules::to_atomic( '1', 'USD', null ), 'whole only' );
t_eq( '100000000', Rules::to_atomic( '100', 'USD', null ), 'no float drift' );
t_eq( null, Rules::to_atomic( '0', 'USD', null ), 'zero invalid' );
t_eq( null, Rules::to_atomic( '0.0000001', 'USD', null ), 'too precise' );
t_eq( null, Rules::to_atomic( '-1', 'USD', null ), 'negative' );
t_eq( null, Rules::to_atomic( '1e3', 'USD', null ), 'exponent' );
t_eq( null, Rules::to_atomic( '0.05', 'BRL', null ), 'brl without rate cannot price' );
t_eq( '10000', Rules::to_atomic( '0.05', 'BRL', 5.0 ), 'brl converted' );
t_eq( '9154', Rules::to_atomic( '0.05', 'BRL', 5.4621 ), 'brl rounds up' );
t_eq( '2', Rules::to_atomic( '0.00001', 'BRL', 5.0 ), 'brl exact conversion stays exact' );
t_eq( '4', Rules::to_atomic( '0.00001', 'BRL', 3.0 ), 'brl inexact conversion rounds up' );
t_eq( '1', Rules::to_atomic( '0.000001', 'BRL', 5.0 ), 'brl floor one unit' );
t_eq( 0.01, Rules::from_atomic( '10000' ), 'display' );
