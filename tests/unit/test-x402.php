<?php
use Portaria\X402;

$net    = X402::networks()['base-sepolia'];
$wallet = '0x209693Bc6afc0C5328bA36FaF03C514EF312287C';
$url    = 'https://example.com/a/';

$c  = X402::challenge( $net, '10000', $wallet, $url, 'Page', 'PAYMENT-SIGNATURE header is required', 'a@b.c' );
$v2 = json_decode( base64_decode( $c['header'] ), true );
t_eq( 2, $v2['x402Version'], 'header is v2' );
t_eq( 'eip155:84532', $v2['accepts'][0]['network'], 'caip2' );
t_eq( '10000', $v2['accepts'][0]['amount'], 'amount string' );
t_eq( array( 'name' => 'USDC', 'version' => '2' ), $v2['accepts'][0]['extra'], 'eip712 domain' );
t_eq( $url, $v2['resource']['url'], 'resource url' );
t_eq( 1, $c['body']['x402Version'], 'body is v1' );
t_eq( 'base-sepolia', $c['body']['accepts'][0]['network'], 'v1 network' );
t_eq( '10000', $c['body']['accepts'][0]['maxAmountRequired'], 'v1 amount' );
t_eq( 'a@b.c', $c['body']['licensingContact'], 'contact' );
t_eq( 'USD Coin', X402::networks()['base']['name'], 'mainnet domain differs' );
t_eq( true, '' !== X402::challenge( $net, '1', $wallet, $url, "Bad \xB1 bytes", 'e' )['header'], 'invalid UTF-8 does not empty the header' );

$auth = array(
	'from'        => '0x857b06519E91e3A54538791bDbb0E22373e36b66',
	'to'          => $wallet,
	'value'       => '10000',
	'validAfter'  => '1',
	'validBefore' => '2',
	'nonce'       => '0xaa',
);
$p2  = array( 'x402Version' => 2, 'accepted' => $v2['accepts'][0], 'payload' => array( 'signature' => '0xsig', 'authorization' => $auth ) );
$p1  = array( 'x402Version' => 1, 'scheme' => 'exact', 'network' => 'base-sepolia', 'payload' => array( 'signature' => '0xsig', 'authorization' => $auth ) );
$enc = function ( $a ) {
	return base64_encode( json_encode( $a ) );
};

t_eq( $p2, X402::decode_payment( $enc( $p2 ) ), 'decode v2' );
t_eq( $p1, X402::decode_payment( $enc( $p1 ) ), 'decode v1' );
t_eq( null, X402::decode_payment( '' ), 'empty' );
t_eq( null, X402::decode_payment( '!!!not base64' ), 'bad base64' );
t_eq( null, X402::decode_payment( base64_encode( 'not json' ) ), 'bad json' );
t_eq( null, X402::decode_payment( $enc( array( 'x402Version' => 3 ) + $p2 ) ), 'unsupported version' );
t_eq( null, X402::decode_payment( $enc( array( 'x402Version' => '2' ) + $p2 ) ), 'version must be a number' );
t_eq( null, X402::decode_payment( $enc( array( 'x402Version' => 2, 'payload' => array() ) ) ), 'no authorization' );
$bad = $p2;
unset( $bad['payload']['authorization']['nonce'] );
t_eq( null, X402::decode_payment( $enc( $bad ) ), 'missing field' );
$bad = $p2;
$bad['payload']['authorization']['value'] = 10000;
t_eq( null, X402::decode_payment( $enc( $bad ) ), 'non-string field' );
t_eq( null, X402::decode_payment( str_repeat( 'A', X402::MAX_HEADER_BYTES + 1 ) ), 'oversized' );

t_eq( null, X402::check_payment( $p2, $net, '10000', $wallet ), 'v2 ok' );
t_eq( null, X402::check_payment( $p1, $net, '10000', $wallet ), 'v1 ok' );
t_eq( null, X402::check_payment( $p2, $net, '10000', strtolower( $wallet ) ), 'address case-insensitive' );
t_eq( 'invalid_exact_evm_payload_authorization_value_mismatch', X402::check_payment( $p2, $net, '20000', $wallet ), 'underpaid v2' );
t_eq( 'invalid_exact_evm_payload_authorization_value_mismatch', X402::check_payment( $p1, $net, '20000', $wallet ), 'underpaid v1' );
$other = '0x0000000000000000000000000000000000000001';
t_eq( 'invalid_exact_evm_payload_recipient_mismatch', X402::check_payment( $p2, $net, '10000', $other ), 'wrong recipient v2' );
t_eq( 'invalid_exact_evm_payload_recipient_mismatch', X402::check_payment( $p1, $net, '10000', $other ), 'wrong recipient v1' );
$m = $p2;
$m['accepted']['network'] = 'eip155:8453';
t_eq( 'invalid_network', X402::check_payment( $m, $net, '10000', $wallet ), 'wrong network' );
$m = $p2;
$m['accepted']['asset'] = $other;
t_eq( 'invalid_payment_requirements', X402::check_payment( $m, $net, '10000', $wallet ), 'wrong asset' );
$m = $p2;
$m['accepted']['scheme'] = 'upto';
t_eq( 'invalid_scheme', X402::check_payment( $m, $net, '10000', $wallet ), 'wrong scheme' );
$m = $p2;
unset( $m['accepted'] );
t_eq( 'invalid_payload', X402::check_payment( $m, $net, '10000', $wallet ), 'no accepted' );
$m = $p2;
$m['accepted']['payTo'] = array( 'x' );
t_eq( 'invalid_exact_evm_payload_recipient_mismatch', X402::check_payment( $m, $net, '10000', $wallet ), 'non-string payTo' );
$m = $p2;
$m['payload']['authorization']['to'] = $other;
t_eq( 'invalid_exact_evm_payload_recipient_mismatch', X402::check_payment( $m, $net, '10000', $wallet ), 'signed to someone else' );
$m = $p2;
$m['payload']['authorization']['value'] = '1';
t_eq( 'invalid_exact_evm_payload_authorization_value_mismatch', X402::check_payment( $m, $net, '10000', $wallet ), 'signed less than accepted' );
$m = $p2;
$m['payload']['authorization']['from'] = 'nope';
t_eq( 'invalid_payload', X402::check_payment( $m, $net, '10000', $wallet ), 'bad payer' );
$m = $p1;
$m['network'] = 'base';
t_eq( 'invalid_network', X402::check_payment( $m, $net, '10000', $wallet ), 'v1 wrong network' );

$b = X402::facilitator_body( $p2, $net, '10000', $wallet, $url, 'Page' );
t_eq( 2, $b['x402Version'], 'body version' );
t_eq( '10000', $b['paymentRequirements']['amount'], 'server requirements v2' );
$tampered = $p2;
$tampered['accepted']['amount'] = '1';
t_eq( '10000', X402::facilitator_body( $tampered, $net, '10000', $wallet, $url, 'Page' )['paymentRequirements']['amount'], 'client cannot set requirements' );
$b = X402::facilitator_body( $p1, $net, '10000', $wallet, $url, 'Page' );
t_eq( '10000', $b['paymentRequirements']['maxAmountRequired'], 'server requirements v1' );
t_eq( $url, $b['paymentRequirements']['resource'], 'v1 resource' );

t_eq( 'paid', X402::read_settlement( array( 'success' => true, 'transaction' => '0xabc', 'payer' => '0x1' ) )['status'], 'paid' );
t_eq( 'failed', X402::read_settlement( array( 'success' => true, 'transaction' => '' ) )['status'], 'success without tx is not paid' );
$s = X402::read_settlement( array( 'success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0xabc' ) );
t_eq( array( 'pending', '0xabc' ), array( $s['status'], $s['transaction'] ), 'pending keeps hash' );
t_eq( 'failed', X402::read_settlement( array( 'success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '' ) )['status'], 'pending without hash fails' );
$s = X402::read_settlement( array( 'success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '' ) );
t_eq( array( 'failed', 'insufficient_funds' ), array( $s['status'], $s['reason'] ), 'failure reason' );
t_eq( 'unexpected_settle_error', X402::read_settlement( null )['reason'], 'transport failure' );
t_eq( 'failed', X402::read_settlement( array( 'success' => 'true', 'transaction' => '0xabc' ) )['status'], 'success must be boolean' );
t_eq( true, X402::is_address( $wallet ), 'address ok' );
t_eq( false, X402::is_address( '0x123' ), 'address short' );
