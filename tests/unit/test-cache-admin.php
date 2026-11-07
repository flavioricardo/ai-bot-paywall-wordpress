<?php
use Portaria\Admin_Actions;
use Portaria\Cache_Compat;

$block = Cache_Compat::litespeed_block( array( 'GPTBot', 'Screaming Frog' ) );
t_eq(
	"# BEGIN Portaria\n<IfModule LiteSpeed>\nRewriteEngine on\nRewriteCond %{HTTP_USER_AGENT} (GPTBot|Screaming\\sFrog) [NC]\nRewriteRule .* - [E=Cache-Control:no-cache]\n</IfModule>\n# END Portaria\n",
	$block,
	'litespeed block'
);

$original = "# BEGIN LSCACHE\nsome rules\n# END LSCACHE\n# BEGIN WordPress\nmore\n# END WordPress\n";
t_eq( $original, Cache_Compat::strip_block( $block . $original ), 'block removed cleanly' );
t_eq( $original, Cache_Compat::strip_block( $original ), 'nothing to remove' );
t_eq( $original, Cache_Compat::strip_block( Cache_Compat::strip_block( $block . $original ) ), 'removal is idempotent' );
$crlf = str_replace( "\n", "\r\n", $block ) . $original;
t_eq( $original, Cache_Compat::strip_block( $crlf ), 'windows line endings removed too' );

t_eq( "'=cmd|calc", Admin_Actions::csv_safe( '=cmd|calc' ), 'formula neutralized' );
t_eq( "'+1", Admin_Actions::csv_safe( '+1' ), 'plus neutralized' );
t_eq( "'@x", Admin_Actions::csv_safe( '@x' ), 'at neutralized' );
t_eq( "'-1", Admin_Actions::csv_safe( '-1' ), 'minus neutralized' );
t_eq( '/hello/', Admin_Actions::csv_safe( '/hello/' ), 'path untouched' );
t_eq( '', Admin_Actions::csv_safe( '' ), 'empty untouched' );
