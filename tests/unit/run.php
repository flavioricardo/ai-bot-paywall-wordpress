<?php
// Minimal test runner: no framework, every assertion prints only on failure.
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', '/tmp/' );
$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

// WordPress stand-ins for the few core helpers the pure classes call.
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function t_eq( $expected, $actual, string $label ): void {
	if ( $expected === $actual ) {
		$GLOBALS['t_pass']++;
		return;
	}
	$GLOBALS['t_fail']++;
	echo "FAIL {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

$dir = dirname( __DIR__, 2 ) . '/botfare-ai-bot-paywall/includes/';
foreach ( glob( $dir . 'class-*.php' ) as $file ) {
	if ( preg_match( '/class-(catalog|net|rules|x402|web-bot-auth|cache-compat|admin-actions)\.php$/', $file ) ) {
		require_once $file;
	}
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $test ) {
	require $test;
}

echo "PHP " . PHP_VERSION . ": {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
exit( $GLOBALS['t_fail'] > 0 ? 1 : 0 );
