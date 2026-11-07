<?php
// Minimal test runner: no framework, every assertion prints only on failure.
define( 'AI_BOT_PAYWALL_TESTING', true );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', '/tmp/' );
$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_eq( $expected, $actual, string $label ): void {
	if ( $expected === $actual ) {
		$GLOBALS['t_pass']++;
		return;
	}
	$GLOBALS['t_fail']++;
	echo "FAIL {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

$dir = dirname( __DIR__, 2 ) . '/ai-bot-paywall/includes/';
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
