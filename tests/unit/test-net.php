<?php
use Portaria\Net;

t_eq( true, Net::ip_in_cidr( '132.196.86.10', '132.196.86.0/24' ), 'v4 in' );
t_eq( false, Net::ip_in_cidr( '132.196.87.10', '132.196.86.0/24' ), 'v4 out' );
t_eq( true, Net::ip_in_cidr( '10.0.0.5', '10.0.0.5/32' ), 'v4 exact' );
t_eq( true, Net::ip_in_cidr( '10.0.0.5', '10.0.0.5' ), 'no mask' );
t_eq( true, Net::ip_in_cidr( '10.0.0.129', '10.0.0.128/25' ), 'sub-byte in' );
t_eq( false, Net::ip_in_cidr( '10.0.0.127', '10.0.0.128/25' ), 'sub-byte out' );
t_eq( true, Net::ip_in_cidr( '2001:4860:4801::1', '2001:4860:4801::/48' ), 'v6 in' );
t_eq( false, Net::ip_in_cidr( '2001:4860:4802::1', '2001:4860:4801::/48' ), 'v6 out' );
t_eq( false, Net::ip_in_cidr( '10.0.0.1', '2001:4860::/32' ), 'family mismatch' );
t_eq( false, Net::ip_in_cidr( 'nonsense', '10.0.0.0/8' ), 'bad ip' );
t_eq( false, Net::ip_in_cidr( '10.0.0.1', '10.0.0.0/99' ), 'bad mask' );
t_eq( true, Net::ip_in_cidr( '8.8.8.8', '0.0.0.0/0' ), 'zero mask' );

t_eq( '1.2.3.4', Net::client_ip( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '9.9.9.9' ), '' ), 'untrusted header ignored' );
t_eq( '9.9.9.9', Net::client_ip( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '9.9.9.9' ), 'X-Real-IP' ), 'trusted header used' );
t_eq( '7.7.7.7', Net::client_ip( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '66.249.66.1, 7.7.7.7' ), 'X-Forwarded-For' ), 'xff right-most resists spoofing' );
t_eq( '1.2.3.4', Net::client_ip( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_REAL_IP' => 'bad' ), 'X-Real-IP' ), 'bad header falls back' );
t_eq( '1.2.3.4', Net::client_ip( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '9.9.9.9' ), 'Evil-Header' ), 'unknown setting ignored' );
t_eq( '', Net::client_ip( array(), '' ), 'no address' );
t_eq( '1.2.3.4', Net::client_ip( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '9.9.9.9' ), Net::DIRECT ), 'direct ignores headers' );

t_eq( true, Net::host_matches_suffix( 'crawl-66.googlebot.com.', array( 'googlebot.com' ) ), 'subdomain' );
t_eq( true, Net::host_matches_suffix( 'googlebot.com', array( 'googlebot.com' ) ), 'exact host' );
t_eq( false, Net::host_matches_suffix( 'fakegooglebot.com', array( 'googlebot.com' ) ), 'lookalike' );
t_eq( false, Net::host_matches_suffix( 'googlebot.com.evil.io', array( 'googlebot.com' ) ), 'suffix spoof' );
