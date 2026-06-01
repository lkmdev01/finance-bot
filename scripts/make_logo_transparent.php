<?php

// Usage:
// php scripts/make_logo_transparent.php
//
// Generates:
// - public/brand/logo-inovafinance-bg.png (copy of root logo)
// - public/brand/logo-inovafinance-icon.png (transparent icon cutout)
//
// Requirements: ext-gd enabled.

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$src = $root . DIRECTORY_SEPARATOR . 'logo-inovafinance.png';
$outDir = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'brand';
$bgOut = $outDir . DIRECTORY_SEPARATOR . 'logo-inovafinance-bg.png';
$iconOut = $outDir . DIRECTORY_SEPARATOR . 'logo-inovafinance-icon.png';

if (!extension_loaded('gd')) {
    fail('GD extension is required.');
}

if (!is_file($src)) {
    fail("Source not found: {$src}");
}

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fail("Failed to create output dir: {$outDir}");
}

if (!copy($src, $bgOut)) {
    fail("Failed to copy bg logo to: {$bgOut}");
}

$im = @imagecreatefrompng($src);
if (!$im) {
    fail("Failed to read PNG: {$src}");
}

imagealphablending($im, true);
imagesavealpha($im, true);

$w = imagesx($im);
$h = imagesy($im);

// Heuristic: keep only the bright green bars and discard the dark background.
// We build a minimal bounding box to crop the output tight.
$minX = $w;
$minY = $h;
$maxX = -1;
$maxY = -1;
$kept = [];

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgba = imagecolorat($im, $x, $y);
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;

        $isGreenish = ($g > 90) && (($g - $r) > 25) && (($g - $b) > 25) && (($r + $g + $b) > 140);
        if (!$isGreenish) {
            continue;
        }

        // Convert to GD alpha (0 opaque .. 127 transparent).
        // Higher contrast => more opaque.
        $contrast = max(0, $g - max($r, $b));
        $opaque = min(127, (int) round($contrast * 1.6)); // 0..127
        $alpha = 127 - $opaque;

        $kept[] = [$x, $y, $r, $g, $b, $alpha];

        if ($x < $minX) $minX = $x;
        if ($y < $minY) $minY = $y;
        if ($x > $maxX) $maxX = $x;
        if ($y > $maxY) $maxY = $y;
    }
}

if ($maxX < 0) {
    imagedestroy($im);
    fail('No pixels matched; adjust thresholds.');
}

$tw = $maxX - $minX + 1;
$th = $maxY - $minY + 1;

$out = imagecreatetruecolor($tw, $th);
imagealphablending($out, false);
imagesavealpha($out, true);

$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefilledrectangle($out, 0, 0, $tw - 1, $th - 1, $transparent);

foreach ($kept as $p) {
    [$x, $y, $r, $g, $b, $alpha] = $p;
    $c = imagecolorallocatealpha($out, $r, $g, $b, $alpha);
    imagesetpixel($out, $x - $minX, $y - $minY, $c);
}

if (!imagepng($out, $iconOut)) {
    imagedestroy($out);
    imagedestroy($im);
    fail("Failed to write: {$iconOut}");
}

imagedestroy($out);
imagedestroy($im);

fwrite(STDOUT, "Wrote: {$bgOut}" . PHP_EOL);
fwrite(STDOUT, "Wrote: {$iconOut} ({$tw}x{$th})" . PHP_EOL);

