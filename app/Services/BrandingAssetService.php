<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Site logo + derived favicons. Files live on the `public` disk under
 * `branding/` (same disk the Banners/Categories uploads use); only their
 * relative paths are stored, as global Settings:
 *
 *   branding.logo_path          original upload (png/jpg/svg)
 *   branding.logo_display_path  256px-max PNG the header/footer actually load
 *   branding.favicon_path       32x32 PNG (or the SVG itself for an SVG logo)
 *   branding.apple_touch_path   180x180 PNG ('' for an SVG logo)
 *   branding.footer_credit      credit line text
 *   branding.name_beside_logo   brand name shown next to the logo image
 *
 * Every upload gets a fresh random filename, so browsers and CDNs never
 * serve a stale favicon and no query-string cache busting is needed.
 */
class BrandingAssetService
{
    private const DISK = 'public';
    private const DIR = 'branding';

    /** Raster sizes generated from a PNG/JPG logo. */
    private const ICON_SIZES = ['favicon_path' => 32, 'apple_touch_path' => 180];

    /**
     * Owner 2026-11-26 ("replace that logo with the logo file on desktop"): the round logo cut out of the owner's own
     * "1CallFix Logo.png" (its light-blue square background removed, 240 px, white middle), the one in the banners.
     */
    public const PILL_LOGO = 'images/brand/1callfix-logo-round.png';

    /**
     * Owner 2026-11-26: the company logo inside the ID card, certificate and stickers ("the company logo is
     * completely missed"), as a data URI so the PDF maker (dompdf) needs no web request: the owner's round logo
     * (PILL_LOGO); without that file, the admin-uploaded logo (its 256 px copy). null when there is neither.
     */
    public function logoDataUri(): ?string
    {
        static $memo = [];
        $own = public_path(self::PILL_LOGO);
        if (is_file($own)) {
            return $memo[$own] ??= 'data:image/png;base64,'.base64_encode((string) file_get_contents($own));
        }
        $path = (string) Setting::get('branding.logo_display_path', '') ?: (string) Setting::get('branding.logo_path', '');
        if ($path === '') {
            return null;
        }
        if (array_key_exists($path, $memo)) {
            return $memo[$path];
        }

        $disk = Storage::disk(self::DISK);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => null,
        };

        if (! $mime || ! $disk->exists($path)) {
            return $memo[$path] = null;
        }
        $bytes = (string) $disk->get($path);
        if ($mime === 'image/png') {
            $bytes = $this->whiteInside($bytes);
        }

        return $memo[$path] = 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * Owner 2026-11-26 (the banners): the round logo sits on the blue pill with a WHITE middle. The uploaded logo's
     * middle is see-through, so on blue it turned dark. When the middle is see-through and a dark ring closes it, paint
     * a white disc under the logo, as big as the inside of the ring. Anything else is returned unchanged.
     */
    private function whiteInside(string $png): string
    {
        $img = @imagecreatefromstring($png);
        if ($img === false) {
            return $png;
        }
        // Trim the empty margin round the circle, so the circle fills the pill's height as in the banners.
        imagesavealpha($img, true);
        $trimmed = @imagecropauto($img, IMG_CROP_TRANSPARENT);
        if ($trimmed !== false && (imagesx($trimmed) !== imagesx($img) || imagesy($trimmed) !== imagesy($img))) {
            imagedestroy($img);
            $img = $trimmed;
            imagesavealpha($img, true);
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $cx = intdiv($w, 2);
        $cy = intdiv($h, 2);

        // Walk left from the middle: see-through pixels, then the first solid dark pixel is the ring.
        $hole = false;
        $r = null;
        for ($x = $cx; $x > 0; $x--) {
            $c = imagecolorsforindex($img, imagecolorat($img, $x, $cy));
            if ($c['alpha'] >= 100) {
                $hole = true;

                continue;
            }
            if ($hole && $c['alpha'] < 20 && ($c['red'] + $c['green'] + $c['blue']) < 180) {
                $r = $cx - $x;
                break;
            }
        }
        if (! $hole || ! $r || $r < $w * 0.1) {
            if ($img === $trimmed) { // trimmed only
                ob_start();
                imagepng($img);
                $png = (string) ob_get_clean();
            }
            imagedestroy($img);

            return $png;
        }

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
        imagefilledellipse($out, $cx, $cy, 2 * $r + 2, 2 * $r + 2, imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagepng($out);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        imagedestroy($out);

        return $bytes;
    }

    /**
     * Store the upload and derive the icon files.
     *
     * @return array{logo_path:string,logo_display_path:string,favicon_path:string,apple_touch_path:string}
     */
    public function store(UploadedFile $file): array
    {
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (! in_array($ext, ['png', 'jpg', 'svg'], true)) {
            throw new RuntimeException('Unsupported logo type.');
        }

        $token = Str::lower(Str::random(10));
        $disk = Storage::disk(self::DISK);

        if ($ext === 'svg') {
            $svg = (string) file_get_contents($file->getRealPath());
            if (! $this->isSafeSvg($svg)) {
                throw new RuntimeException('This SVG contains scripts or external references and was rejected.');
            }
            $logo = self::DIR."/logo-{$token}.svg";
            $disk->put($logo, $svg);

            // GD cannot rasterise SVG; the browser tab uses the SVG directly.
            return ['logo_path' => $logo, 'logo_display_path' => $logo, 'favicon_path' => $logo, 'apple_touch_path' => ''];
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw new RuntimeException('The image could not be read.');
        }

        $logo = self::DIR."/logo-{$token}.{$ext}";
        $disk->putFileAs(self::DIR, $file, "logo-{$token}.{$ext}");

        // The original can be megabytes; pages load this small copy instead.
        $display = self::DIR."/logo-256-{$token}.png";
        $disk->put($display, $this->scaledPng($source, 256));

        $paths = ['logo_path' => $logo, 'logo_display_path' => $display];
        foreach (self::ICON_SIZES as $key => $size) {
            $name = self::DIR.'/'.($key === 'favicon_path' ? "favicon-32-{$token}.png" : "apple-touch-180-{$token}.png");
            $disk->put($name, $this->squarePng($source, $size));
            $paths[$key] = $name;
        }
        imagedestroy($source);

        return $paths;
    }

    /** Delete the files behind the currently stored paths (call after saving new ones). */
    public function deleteFiles(array $paths): void
    {
        $disk = Storage::disk(self::DISK);
        foreach (array_unique(array_filter($paths)) as $path) {
            // Never delete outside the branding directory, whatever the Setting says.
            if (str_starts_with($path, self::DIR.'/') && ! str_contains($path, '..')) {
                $disk->delete($path);
            }
        }
    }

    public function currentPaths(): array
    {
        return [
            'logo_path' => (string) Setting::get('branding.logo_path', ''),
            'logo_display_path' => (string) Setting::get('branding.logo_display_path', ''),
            'favicon_path' => (string) Setting::get('branding.favicon_path', ''),
            'apple_touch_path' => (string) Setting::get('branding.apple_touch_path', ''),
        ];
    }

    public function url(string $key): ?string
    {
        $path = (string) Setting::get("branding.{$key}", '');
        if ($path === '' && $key === 'logo_display_path') {
            $path = (string) Setting::get('branding.logo_path', '');
        }

        return $path !== ''
            ? asset('storage/'.$path) // request-relative: right scheme/host on https and any local port
            : null;
    }

    public static function creditLine(): string
    {
        return (string) Setting::get('branding.footer_credit', '');
    }

    /** REF 1CF-BRANDING-NAME-BESIDE-LOGO-001 — blank means hidden, same as creditLine(). */
    public static function nameBesideLogo(): string
    {
        return (string) Setting::get('branding.name_beside_logo', '');
    }

    /** Shrink to at most $max px on the long side (never upscale), alpha preserved. */
    private function scaledPng(\GdImage $source, int $max): string
    {
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1, $max / max($w, $h));
        [$dw, $dh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

        $canvas = imagecreatetruecolor($dw, $dh);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return $png;
    }

    /** Contain-fit the logo on a transparent square canvas, alpha preserved. */
    private function squarePng(\GdImage $source, int $size): string
    {
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min($size / $w, $size / $h);
        [$dw, $dh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        // Blending stays off so the source's alpha channel is copied through
        // rather than composited against the transparent fill.
        imagecopyresampled($canvas, $source, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return $png;
    }

    /** Reject the constructs that make an SVG executable when opened directly. */
    private function isSafeSvg(string $svg): bool
    {
        return stripos($svg, '<svg') !== false
            && ! preg_match('/<\s*(script|foreignObject|iframe|embed|object)\b|\bon\w+\s*=|javascript:|<!ENTITY|<!DOCTYPE[^>]*\[/i', $svg);
    }
}
