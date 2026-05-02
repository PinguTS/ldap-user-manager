<?php

declare(strict_types=1);

session_start();

$image_width  = 180;
$image_height = 60;

/**
 * Generate a random CAPTCHA string using a cryptographically secure source.
 */
function random_captcha_string(int $length = 6): string
{
    $charset = str_split('ABCDEFGHKLMNPQRSTVWXYZ@$3456789');
    $max     = count($charset) - 1;
    $result  = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $charset[random_int(0, $max)];
    }
    return $result;
}

$image = imagecreatetruecolor($image_width, $image_height);
imageantialias($image, true);

$cols = [];

$r = random_int(100, 200);
$g = random_int(100, 200);
$b = random_int(100, 200);

for ($i = 0; $i < 5; $i++) {
    $cols[] = imagecolorallocate($image, $r - 20 * $i, $g - 20 * $i, $b - 20 * $i);
}

imagefill($image, 0, 0, $cols[0]);

$thickness = random_int(2, 10);

for ($i = 0; $i < 10; $i++) {
    imagesetthickness($image, $thickness);
    $line_col = $cols[random_int(1, 4)];
    imagerectangle(
        $image,
        random_int(-$thickness, ($image_width - $thickness)),
        random_int(-$thickness, $thickness),
        random_int(-$thickness, ($image_width - $thickness)),
        random_int(($image_height - $thickness), ($image_width / 2)),
        $line_col
    );
}

$black    = imagecolorallocate($image, 0, 0, 0);
$white    = imagecolorallocate($image, 255, 255, 255);
$textcols = [$black, $white];

/** @var string[] $fonts */
$fonts       = glob(__DIR__ . '/../../assets/fonts/*.ttf') ?: [];
$num_chars   = 6;
$human_proof = random_captcha_string($num_chars);
$font_count  = count($fonts);

$_SESSION['proof_of_humanity'] = $human_proof;

for ($i = 0; $i < $num_chars; $i++) {
    $gap      = ($image_width - 15) / $num_chars;
    $size     = random_int(20, 30);
    $angle    = random_int(-30, 30);
    $txt_x    = 10 + ($i * $gap);
    $txt_y    = random_int(30, ($image_height - 15));
    $txt_col  = $textcols[random_int(0, 1)];
    $txt_font = ($font_count > 0) ? $fonts[random_int(0, $font_count - 1)] : '';
    $txt      = $human_proof[$i];
    if ($txt_font !== '') {
        imagettftext($image, $size, $angle, (int) $txt_x, (int) $txt_y, $txt_col, $txt_font, $txt);
    }
}

header('Content-type: image/png');
imagepng($image);
imagedestroy($image);
