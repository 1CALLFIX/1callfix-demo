<?php

namespace App\Services\Marketing;

use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Instagram takes only JPEG pictures, between 4:5 (tall) and 1.91:1 (wide). Our posts reuse banners (often 3:1),
 * item photos (PNG / WebP) and uploads, so before posting we make a JPEG copy, padded with white to the nearest
 * allowed shape (never cropped: banner words stay whole), at most 1440 px wide. Kept on the public disk so Instagram
 * can fetch it; same picture = same file.
 */
class SocialImage
{
    public const MIN_RATIO = 0.8;   // 4:5
    public const MAX_RATIO = 1.91;
    public const MAX_WIDTH = 1440;

    public function instagramJpeg(SocialPost $post): string
    {
        $bytes = $this->bytes($post);
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new RuntimeException('The picture could not be read. Upload a JPG or PNG.');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $ratio = $w / max(1, $h);

        // Canvas in the allowed shape, the picture centred on it.
        [$cw, $ch] = [$w, $h];
        if ($ratio > self::MAX_RATIO) {
            $ch = (int) ceil($w / self::MAX_RATIO);
        } elseif ($ratio < self::MIN_RATIO) {
            $cw = (int) ceil($h * self::MIN_RATIO);
        }
        $scale = min(1, self::MAX_WIDTH / max(1, $cw));
        [$ow, $oh] = [max(1, (int) round($cw * $scale)), max(1, (int) round($ch * $scale))];
        [$pw, $ph] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

        $out = imagecreatetruecolor($ow, $oh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, intdiv($ow - $pw, 2), intdiv($oh - $ph, 2), 0, 0, $pw, $ph, $w, $h);

        ob_start();
        imagejpeg($out, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($out);

        $path = 'marketing/instagram/post-'.$post->id.'-'.substr(sha1($bytes), 0, 12).'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return url(Storage::disk('public')->url($path));
    }

    private function bytes(SocialPost $post): string
    {
        $path = (string) $post->image_path;
        if ($path === '') {
            throw new RuntimeException('Instagram needs a picture. Add one to this post.');
        }

        // Our own files: read from the disk, not over the internet.
        $local = $path;
        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            $p = (string) parse_url($path, PHP_URL_PATH);
            $host = parse_url($path, PHP_URL_HOST);
            $local = ($host === null || $host === parse_url(url('/'), PHP_URL_HOST)) && Str::startsWith($p, '/storage/')
                ? Str::after($p, '/storage/')
                : '';
        }
        if ($local !== '' && Storage::disk('public')->exists($local)) {
            return (string) Storage::disk('public')->get($local);
        }

        $url = $post->imageUrl() ? url($post->imageUrl()) : '';
        $res = Http::timeout(30)->get($url);
        if (! $res->successful() || $res->body() === '') {
            throw new RuntimeException('The picture could not be downloaded.');
        }

        return $res->body();
    }
}
