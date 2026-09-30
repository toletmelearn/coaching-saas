<?php

namespace App\Support\Branding;

use GdImage;

/**
 * Draws the first letter of an institute's name on its accent colour — the icon shown
 * whenever an institute hasn't uploaded its own logo, so the app is installable from
 * day one (see docs/specs/phase-6-branding-pwa.md). Uses a bundled OFL-licensed font
 * (resources/fonts/NotoSans-Bold.ttf, resources/fonts/OFL.txt) via GD's FreeType
 * support — app:preflight fails in production if FreeType isn't available.
 */
class DefaultIconGenerator
{
    public static function make(string $letter, string $hexColor, int $size): GdImage
    {
        $image = imagecreatetruecolor($size, $size);

        [$r, $g, $b] = self::hexToRgb($hexColor);
        $background = imagecolorallocate($image, $r, $g, $b);
        imagefill($image, 0, 0, $background);

        $white = imagecolorallocate($image, 255, 255, 255);
        $fontPath = resource_path('fonts/NotoSans-Bold.ttf');
        $fontSize = (int) round($size * 0.5);

        $box = imagettfbbox($fontSize, 0, $fontPath, $letter);
        $textWidth = abs($box[4] - $box[0]);
        $textHeight = abs($box[5] - $box[1]);

        $x = (int) round(($size - $textWidth) / 2);
        $y = (int) round(($size + $textHeight) / 2);

        imagettftext($image, $fontSize, 0, $x, $y, $white, $fontPath, $letter);

        return $image;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
