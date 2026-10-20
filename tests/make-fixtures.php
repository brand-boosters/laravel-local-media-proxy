<?php

// Generates real image files (with GD) that the test harness seeds into the fake production bucket.
// Usage: php tests/make-fixtures.php <directory>

$dir = $argv[1] ?? sys_get_temp_dir().'/local-media-proxy-fixtures';
@mkdir($dir.'/images/products/1', 0777, true);
@mkdir($dir.'/images/banners', 0777, true);

$image = imagecreatetruecolor(800, 600);
for ($y = 0; $y < 600; $y += 20) {
    imagefilledrectangle($image, 0, $y, 799, $y + 19, imagecolorallocate($image, 20 + $y % 200, 80, 160));
}
imagestring($image, 5, 330, 290, 'PRODUCTION', imagecolorallocate($image, 255, 255, 255));
imagejpeg($image, $dir.'/images/products/1/photo.jpg', 85);
imagepng(imagescale($image, 400), $dir.'/images/banners/home.png');
imagewebp(imagescale($image, 200), $dir.'/images/banners/home.webp');
file_put_contents($dir.'/images/banners/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><circle cx="32" cy="32" r="30" fill="#0f6cbd"/></svg>');

echo "Fixtures written to {$dir}\n";

// A visibly different image used as a "local replacement" in demos.
@mkdir($dir.'/images/replacements', 0777, true);
$local = imagecreatetruecolor(800, 600);
imagefilledrectangle($local, 0, 0, 799, 599, imagecolorallocate($local, 16, 124, 16));
imagefilledellipse($local, 400, 300, 360, 360, imagecolorallocate($local, 255, 255, 255));
imagestring($local, 5, 335, 292, 'LOCAL OVERRIDE', imagecolorallocate($local, 16, 124, 16));
imagepng($local, $dir.'/images/replacements/new-photo.png');
