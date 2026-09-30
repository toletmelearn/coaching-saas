<?php

namespace App\Support\Branding;

use App\Models\Tenant;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Renders PNG bytes for a tenant's PWA icons and header logo, on the fly, from either
 * the stored logo (tenants/{id}/branding/*.png on the private disk) or a generated
 * default icon (DefaultIconGenerator) when none is set. Nothing here is cached to
 * disk — the branding_version query string on icon URLs is what lets browsers cache
 * these responses safely across a branding change.
 */
class BrandingImageService
{
    /**
     * @param  string  $size  One of '180', '192', '512', '512-maskable'.
     */
    public function icon(Tenant $tenant, string $size): string
    {
        $square = $this->squareSource($tenant, 512);

        $canvas = $size === '512-maskable'
            ? $this->maskable($square, 512)
            : $this->resized($square, (int) $size);

        imagedestroy($square);

        return $this->encode($canvas);
    }

    /**
     * The header/login logo: the actual logo scaled to at most 256px on its longer
     * side (aspect ratio preserved, not cropped), or the square default icon.
     */
    public function headerLogo(Tenant $tenant): string
    {
        $source = $this->loadStoredLogo($tenant);

        if ($source === null) {
            return $this->encode($this->defaultIcon($tenant, 256));
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 256 / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = $this->transparentCanvas($targetWidth, $targetHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        return $this->encode($canvas);
    }

    private function squareSource(Tenant $tenant, int $fallbackSize): GdImage
    {
        $source = $this->loadStoredLogo($tenant) ?? $this->defaultIcon($tenant, $fallbackSize);

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width === $height) {
            return $source;
        }

        $side = min($width, $height);
        $square = $this->transparentCanvas($side, $side);

        imagecopy(
            $square,
            $source,
            0,
            0,
            (int) (($width - $side) / 2),
            (int) (($height - $side) / 2),
            $side,
            $side,
        );
        imagedestroy($source);

        return $square;
    }

    private function loadStoredLogo(Tenant $tenant): ?GdImage
    {
        if ($tenant->logo_path === null || ! Storage::disk('local')->exists($tenant->logo_path)) {
            return null;
        }

        $image = imagecreatefromstring(Storage::disk('local')->get($tenant->logo_path));

        if ($image === false) {
            return null;
        }

        imagesavealpha($image, true);

        return $image;
    }

    private function defaultIcon(Tenant $tenant, int $size): GdImage
    {
        $letter = mb_strtoupper(mb_substr(trim($tenant->name) ?: '?', 0, 1));

        return DefaultIconGenerator::make($letter, $tenant->theme_color, $size);
    }

    private function resized(GdImage $square, int $size): GdImage
    {
        $canvas = $this->transparentCanvas($size, $size);
        imagecopyresampled($canvas, $square, 0, 0, 0, 0, $size, $size, imagesx($square), imagesy($square));

        return $canvas;
    }

    /**
     * Places the (already square) icon inside a white canvas with 20% safe-zone
     * padding on each side — the icon content occupies the inner 60% of the canvas,
     * per the maskable icon convention. Any transparency in the source is flattened
     * onto the white background here.
     */
    private function maskable(GdImage $square, int $canvasSize): GdImage
    {
        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $safeSize = (int) round($canvasSize * 0.6);
        $offset = (int) (($canvasSize - $safeSize) / 2);

        imagecopyresampled($canvas, $square, $offset, $offset, 0, 0, $safeSize, $safeSize, imagesx($square), imagesy($square));

        return $canvas;
    }

    private function transparentCanvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        return $canvas;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        return $binary;
    }
}
