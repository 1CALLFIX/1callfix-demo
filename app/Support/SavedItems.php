<?php

namespace App\Support;

use App\Models\SavedItem;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * ♡ Like / Save (owner 2026-11-07). The page draws the heart with a sealed ticket (item key, name, link, photo) so the
 * browser can only save what the server showed it; the ticket is opened again on save. One list per account.
 */
class SavedItems
{
    /** @var array<int, array<string, true>> saved item keys per user, read once per request */
    private static array $keys = [];

    public static function has(?User $user, string $key): bool
    {
        if (! $user) {
            return false;
        }
        self::$keys[$user->id] ??= SavedItem::where('user_id', $user->id)->pluck('item_key')->flip()->map(fn () => true)->all();

        return isset(self::$keys[$user->id][$key]);
    }

    public static function ticket(string $key, string $title, string $url, ?string $image): string
    {
        return Crypt::encryptString(json_encode(['k' => $key, 't' => $title, 'u' => $url, 'i' => $image]));
    }

    /** @return array{k: string, t: string, u: string, i: ?string}|null */
    public static function open(string $ticket): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($ticket), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($data) && isset($data['k'], $data['t'], $data['u']) && self::isOwnPath($data['u']) ? $data : null;
    }

    /** Saves when not saved, removes when saved. Returns true when it is saved now. */
    public static function toggle(User $user, array $item): bool
    {
        $existing = SavedItem::where('user_id', $user->id)->where('item_key', $item['k'])->first();
        if ($existing) {
            $existing->delete();
            unset(self::$keys[$user->id]);

            return false;
        }
        SavedItem::create([
            'user_id' => $user->id,
            'item_key' => mb_substr($item['k'], 0, 60),
            'title' => mb_substr($item['t'], 0, 255),
            'url' => mb_substr($item['u'], 0, 500),
            'image_url' => isset($item['i']) && $item['i'] !== '' ? mb_substr($item['i'], 0, 1000) : null,
        ]);
        unset(self::$keys[$user->id]);

        return true;
    }

    /** Only a path on this site: "/x", never "//other.host" or "https://…". */
    public static function isOwnPath(string $path): bool
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\');
    }

    /** Forget the per-request memory (tests). */
    public static function flush(): void
    {
        self::$keys = [];
    }
}
