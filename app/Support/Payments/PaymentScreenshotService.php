<?php

namespace App\Support\Payments;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Validates and stores a student's uploaded payment screenshot.
 *
 * Same discipline as LogoUploadService: the dimensions are read with getimagesize()
 * BEFORE anything is decoded, so a decompression bomb is rejected on its header
 * alone, and the file that lands on disk is always a freshly re-encoded PNG under a
 * random name on the private disk. The client's filename and declared MIME type are
 * never trusted, and any payload or metadata carried in the original upload is
 * discarded by the re-encode.
 *
 * Screenshots are evidence in a financial dispute, so they are kept private and only
 * ever served through a short-lived signed URL that is also authorised per viewer —
 * never at a guessable public path (see SECURITY.md).
 */
class PaymentScreenshotService
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
     * or null if they're fine. Must be called before store().
     */
    public function dimensionError(UploadedFile $file): ?string
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            return __('payments.upload.invalid_image');
        }

        [$width, $height] = $info;

        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            return __('payments.upload.too_small', ['min' => self::MIN_SIDE]);
        }

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            return __('payments.upload.too_large', ['max' => self::MAX_SIDE]);
        }

        if ($width * $height > self::MAX_PIXELS) {
            return __('payments.upload.too_many_pixels');
        }

        return null;
    }

    /**
     * Decodes, re-encodes to PNG and stores the screenshot for the given tenant.
     * Returns the stored path. The caller validates first (mimes + dimensionError).
     */
    public function store(int $tenantId, UploadedFile $file): string
    {
        $image = $this->decode($file);

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        $path = sprintf('tenants/%d/payments/%s.png', $tenantId, Str::random(32));
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
            throw new RuntimeException('Unable to decode uploaded payment screenshot.');
        }

        return $image;
    }
}
