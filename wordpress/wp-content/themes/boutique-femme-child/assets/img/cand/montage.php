<?php
$dir = __DIR__;
$cols = 4; $rows = 3; $cell = 250; $pad = 6;
$W = $cols * $cell + ( $cols + 1 ) * $pad;
$H = $rows * $cell + ( $rows + 1 ) * $pad;
$out = imagecreatetruecolor( $W, $H );
imagefill( $out, 0, 0, imagecolorallocate( $out, 20, 22, 26 ) );
$white = imagecolorallocate( $out, 255, 255, 255 );
$black = imagecolorallocate( $out, 0, 0, 0 );
for ( $i = 1; $i <= 12; $i++ ) {
	$f = sprintf( '%s/%02d.jpg', $dir, $i );
	if ( ! file_exists( $f ) ) { continue; }
	$src = @imagecreatefromjpeg( $f );
	if ( ! $src ) { continue; }
	$sw = imagesx( $src ); $sh = imagesy( $src );
	$idx = $i - 1; $c = $idx % $cols; $r = intdiv( $idx, $cols );
	$x = $pad + $c * ( $cell + $pad ); $y = $pad + $r * ( $cell + $pad );
	imagecopyresampled( $out, $src, $x, $y, 0, 0, $cell, $cell, $sw, $sh );
	imagefilledrectangle( $out, $x, $y, $x + 36, $y + 24, $black );
	imagestring( $out, 5, $x + 7, $y + 5, sprintf( '%02d', $i ), $white );
	imagedestroy( $src );
}
imagejpeg( $out, $dir . '/montage.jpg', 88 );
echo "montage ok\n";
