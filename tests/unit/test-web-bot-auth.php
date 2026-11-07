<?php
use Portaria\Web_Bot_Auth as W;

t_eq( 'chatgpt.com', W::agent_host( '"https://chatgpt.com"' ), 'quoted url' );
t_eq( 'chatgpt.com', W::agent_host( 'sig1="https://ChatGPT.com/"' ), 'dictionary member' );
t_eq( 'signer.example.com', W::agent_host( 'signer.example.com' ), 'bare host' );
t_eq( '', W::agent_host( '"https://bad host/"' ), 'invalid host' );
t_eq( '', W::agent_host( '' ), 'empty' );

// RFC 8037 appendix A.3 thumbprint vector.
t_eq( 'kPrK_qmxVWaYVA9wwBF6Iuo3vVzz7TxHCTwXBygrS4k', W::thumbprint( '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo' ), 'jwk thumbprint' );
$keys = W::keys_from_directory( '{"keys":[{"kty":"OKP","crv":"Ed25519","x":"11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo"},{"kty":"RSA","n":"x"},{"kty":"OKP","crv":"Ed25519","x":"short"}]}' );
t_eq( array( 'kPrK_qmxVWaYVA9wwBF6Iuo3vVzz7TxHCTwXBygrS4k' ), array_keys( $keys ), 'directory keeps valid ed25519' );
t_eq( array(), W::keys_from_directory( 'not json' ), 'bad directory' );

// The crypto is injected: real Ed25519 runs in the E2E test through WordPress's
// sodium_compat. Here we capture the exact bytes that get signed.
$now      = 1800000000;
$captured = null;
$verify   = function ( $sig, $msg, $key ) use ( &$captured ) {
	$captured = array( $sig, $msg, $key );
	return true;
};
$sigbytes = str_repeat( 'x', 64 );
$params   = '("@authority" "signature-agent");created=' . ( $now - 10 ) . ';expires=' . ( $now + 300 ) . ';keyid="k1";alg="ed25519";tag="web-bot-auth"';
$headers  = array(
	'signature-agent' => '"https://chatgpt.com"',
	'signature-input' => 'sig1=' . $params,
	'signature'       => 'sig1=:' . base64_encode( $sigbytes ) . ':',
);
$k = array( 'k1' => 'KEY' );

t_eq( true, W::verify( $headers, 'Example.COM', $k, $now, $verify ), 'valid signature' );
t_eq( "\"@authority\": example.com\n\"signature-agent\": \"https://chatgpt.com\"\n\"@signature-params\": " . $params, $captured[1], 'signature base bytes' );
t_eq( array( $sigbytes, 'KEY' ), array( $captured[0], $captured[2] ), 'signature and key passed through' );

$dict = '("@authority" "signature-agent";key="sig1");created=' . ( $now - 10 ) . ';expires=' . ( $now + 300 ) . ';keyid="k1";tag="web-bot-auth"';
$h    = array(
	'signature-agent' => 'sig1="https://chatgpt.com"',
	'signature-input' => 'sig1=' . $dict,
	'signature'       => $headers['signature'],
);
t_eq( true, W::verify( $h, 'example.com', $k, $now, $verify ), 'dictionary signature-agent' );
t_eq( "\"@authority\": example.com\n\"signature-agent\";key=\"sig1\": \"https://chatgpt.com\"\n\"@signature-params\": " . $dict, $captured[1], 'dictionary base bytes' );

$h = array( 'signature-input' => 'other=("@authority");created=1;expires=2;keyid="z";tag="x", sig1=' . $params ) + $headers;
t_eq( true, W::verify( $h, 'example.com', $k, $now, $verify ), 'picks the web-bot-auth member' );

$reject = function ( $mutate, $label ) use ( $headers, $k, $now, $verify, $params ) {
	t_eq( false, W::verify( $mutate( $headers, $params ), 'example.com', $k, $now, $verify ), $label );
};
$input = function ( $h, $value ) {
	$h['signature-input'] = 'sig1=' . $value;
	return $h;
};
$reject( function ( $h ) {
	unset( $h['signature-input'] );
	return $h;
}, 'no input' );
$reject( function ( $h ) {
	unset( $h['signature'] );
	return $h;
}, 'no signature' );
$reject( function ( $h ) {
	$h['signature'] = 'sig1=:' . base64_encode( 'short' ) . ':';
	return $h;
}, 'short signature' );
$reject( function ( $h, $p ) use ( $input ) {
	return $input( $h, str_replace( 'web-bot-auth', 'other', $p ) );
}, 'wrong tag' );
$reject( function ( $h, $p ) use ( $input ) {
	return $input( $h, str_replace( 'keyid="k1"', 'keyid="nope"', $p ) );
}, 'unknown key' );
$reject( function ( $h, $p ) use ( $input ) {
	return $input( $h, str_replace( '"@authority" ', '', $p ) );
}, 'authority not covered' );
$reject( function ( $h, $p ) use ( $input ) {
	return $input( $h, str_replace( 'alg="ed25519"', 'alg="rsa"', $p ) );
}, 'wrong alg' );
$reject( function ( $h ) use ( $input, $now ) {
	return $input( $h, '("@authority");created=' . ( $now - 900 ) . ';expires=' . ( $now - 1 ) . ';keyid="k1";tag="web-bot-auth"' );
}, 'expired' );
$reject( function ( $h ) use ( $input, $now ) {
	return $input( $h, '("@authority");created=' . ( $now + 900 ) . ';expires=' . ( $now + 999 ) . ';keyid="k1";tag="web-bot-auth"' );
}, 'from the future' );
$reject( function ( $h ) use ( $input, $now ) {
	return $input( $h, '("@authority");created=' . ( $now - 1 ) . ';expires=' . ( $now + 90000 ) . ';keyid="k1";tag="web-bot-auth"' );
}, 'lifetime too long' );
$reject( function ( $h ) use ( $input, $now ) {
	return $input( $h, '("@authority");created=' . ( $now - 1 ) . ';keyid="k1";tag="web-bot-auth"' );
}, 'no expiry' );
$reject( function ( $h ) {
	unset( $h['signature-agent'] );
	return $h;
}, 'covered header missing' );
$reject( function ( $h, $p ) use ( $input ) {
	return $input( $h, str_replace( '"signature-agent"', '"@method"', $p ) );
}, 'unsupported derived component' );
t_eq( false, W::verify( $headers, 'example.com', $k, $now, function () {
	return false;
} ), 'bad crypto rejected' );
