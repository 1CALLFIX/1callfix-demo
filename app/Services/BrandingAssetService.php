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
 *
 * Every upload gets a fresh random filename, so browsers and CDNs never
 * serve a stale favicon and no query-string cache busting is needed.
 */
class BrandingAssetService
{
    public const DEFAULT_CREDIT = 'Made with ❤ in India by 1CallFix Solutions Pvt Ltd';

    private const DISK = 'public';
    private const DIR = 'branding';

    /** Raster sizes generated from a PNG/JPG logo. */
    private const ICON_SIZES = ['favicon_path' => 32, 'apple_touch_path' => 180];

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
        return (string) Setting::get('branding.footer_credit', self::DEFAULT_CREDIT);
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
