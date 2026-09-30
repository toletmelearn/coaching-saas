<?php

namespace App\Support\Branding;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Validates and stores an owner's uploaded institute logo. Dimensions are always
 * checked via getimagesize() BEFORE the file is decoded with GD, to avoid decoding a
 * decompression-bomb-style image just to find out it should be rejected. The stored
 * file is always a freshly re-encoded PNG under a random filename on the private
 * disk — the client's filename and declared MIME type are never used, and any
 * embedded metadata/payload in the original file is discarded by the re-encode.
 */
class LogoUploadService
{
    private const MIN_SIDE = 256;

    private const MAX_SIDE = 4096;

    /**
     * 16 million pixels (decimal), not 16 * 1024 * 1024 — chosen so that the per-side
     * cap alone (4096 x 4096 = 16,777,216) already exceeds it, giving this check
     * something to catch even when both sides individually pass.
     */
    private const MAX_PIXELS = 16_000_000;

    /**
     * Returns a translated error message if the file's dimensions are out of bounds,
     * or null if they're fine. Must be called before decode().
     */
    public function dimensionError(UploadedFile $file): ?string
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            return __('settings.logo_errors.invalid_image');
        }

        [$width, $height] = $info;

        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            return __('settings.logo_errors.too_small', ['min' => self::MIN_SIDE]);
        }

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            return __('settings.logo_errors.too_large_dimensions', ['max' => self::MAX_SIDE]);
        }

        if ($width * $height > self::MAX_PIXELS) {
            return __('settings.logo_errors.too_many_pixels');
        }

        return null;
    }

    /**
     * Decodes, re-encodes to PNG, and stores the logo for the given tenant. Returns
     * the stored path. Caller is responsible for validating the file first
     * (dimensionError()) and for deleting any previous logo file.
     */
    public function store(int $tenantId, UploadedFile $file): string
    {
        $image = $this->decode($file);

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        $path = sprintf('tenants/%d/branding/%s.png', $tenantId, Str::random(32));
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    private function decode(UploadedFile $file): GdImage
    {
        $info = getimagesize($file->getRealPath());

        $image = match ($info[2] ?? null) {
            IMAGETYPE_PNG => imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_JPEG => imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_WEBP => imagecreatefromwebp($file->getRealPath()),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('Unable to decode uploaded logo image.');
        }

        return $image;
    }
}
