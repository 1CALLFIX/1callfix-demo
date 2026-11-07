<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Automatic shrinking of uploaded display pictures (owner 2026-11-07): a 6 MB phone photo used as a banner or a
 * product picture is stored at the size the site actually shows (App\Support\ImageSizes, already 2× for sharp
 * screens), so pages load fast on mobile data.
 *
 * - Scales DOWN only, keeping the shape, until the picture still covers the best-size box (so any crop the page
 *   makes stays sharp). Never scales up, never crops.
 * - JPEG stays JPEG (quality 82, phone rotation applied), PNG stays PNG (transparency kept), WebP stays WebP.
 * - Re-encoding also drops the photo's hidden data (camera, GPS location).
 * - The smaller file is kept only if it really is smaller; GIF, SVG, PDF, anything unreadable or very large in
 *   pixels is stored exactly as uploaded. A shrink failure never blocks the upload.
 *
 * Proof files (job evidence, KYC, safety, claim and return photos) are NOT passed through here: they stay original.
 */
final class ImageShrinker
{
    /** Above this many pixels GD would need too much memory; store the original. */
    private const MAX_PIXELS = 40_000_000;

    private const QUALITY = 82;

    /** Store $file in $dir on $disk, shrunk for $use (an ImageSizes::USES key). Returns the stored path. */
    public static function store(UploadedFile $file, string $dir, string $use, string $disk = 'public'): string
    {
        try {
            $shrunk = self::shrink((string) $file->getRealPath(), $use);
        } catch (Throwable $e) {
            report($e);
            $shrunk = null;
        }

        if ($shrunk === null) {
            return $file->store($dir, $disk);
        }

        [$bytes, $ext] = $shrunk;
        $path = trim($dir, '/').'/'.Str::random(40).'.'.$ext;
        Storage::disk($disk)->put($path, $bytes);

        return $path;
    }

    /**
     * The shrunk bytes and extension, or null when the original should be kept as it is.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function shrink(string $source, string $use): ?array
    {
        [$boxW, $boxH] = ImageSizes::USES[$use] ?? [null, null];
        if (! $boxW || ! $boxH || ! is_file($source)) {
            return null;
        }

        $info = @getimagesize($source);
        $type = $info[2] ?? null;
        $formats = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (! isset($formats[$type]) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }
        $ext = $formats[$type];

        $img = match ($ext) {
            'jpg' => @imagecreatefromjpeg($source),
            'png' => @imagecreatefrompng($source),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        };
        if (! $img) {
            return null;
        }

        $rotated = $ext === 'jpg' ? self::applyOrientation($img, $source) : false;
        if ($rotated) {
            $img = $rotated;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        // Smallest size that still covers the box on both sides; never larger than the original.
        $scale = min(1, max($boxW / $w, $boxH / $h));
        if ($scale < 1) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $resized = imagecreatetruecolor($nw, $nh);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $resized;
        } elseif (! $rotated && $ext !== 'jpg') {
            // Already small enough and nothing to fix: re-saving a PNG/WebP gains little.
            imagedestroy($img);

            return null;
        }

        ob_start();
        match ($ext) {
            'jpg' => imagejpeg($img, null, self::QUALITY),
            'png' => (imagesavealpha($img, true) && imagepng($img, null, 9)),
            'webp' => imagewebp($img, null, self::QUALITY),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        // A rotated photo must be saved even if not smaller, or it would show sideways.
        if ($bytes === '' || (! $rotated && strlen($bytes) >= (int) filesize($source))) {
            return null;
        }

        return [$bytes, $ext];
    }

    /** Phone photos are stored sideways with an EXIF "rotate me" note; re-saving drops the note, so turn it now. */
    private static function applyOrientation(\GdImage $img, string $source): \GdImage|false
    {
        if (! function_exists('exif_read_data')) {
            return false;
        }
        $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle === 0) {
            return false;
        }
        $turned = imagerotate($img, $angle, 0);
        if (! $turned) {
            return false;
        }
        imagedestroy($img);

        return $turned;
    }
}
