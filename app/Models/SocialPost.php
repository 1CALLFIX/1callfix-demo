<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** A planned social media post (Admin → Marketing & Offers → Social posts, owner 2026-11-21). */
class SocialPost extends Model
{
    /** platform => [label, colour classes, where to post it by hand on a computer] */
    public const PLATFORMS = [
        'facebook' => ['Facebook', 'bg-blue-600 text-white', 'https://www.facebook.com/'],
        'instagram' => ['Instagram', 'bg-pink-600 text-white', 'https://business.facebook.com/latest/composer'],
        'google_business' => ['Google Business', 'bg-emerald-600 text-white', 'https://business.google.com/'],
        'whatsapp' => ['WhatsApp Status', 'bg-green-600 text-white', 'https://web.whatsapp.com/'],
    ];

    public const STATUSES = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'posted' => 'Posted', 'skipped' => 'Skipped'];

    protected $fillable = [
        'title', 'caption_en', 'caption_te', 'hashtags', 'image_path', 'link_url', 'module', 'franchise_id', 'city_id',
        'country_id', 'platforms', 'scheduled_for', 'status', 'skip_reason', 'source', 'source_ref', 'created_by',
    ];

    protected function casts(): array
    {
        return ['platforms' => 'array', 'scheduled_for' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SocialPostDelivery::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return Str::startsWith($this->image_path, ['http://', 'https://', '/'])
            ? $this->image_path
            : Storage::disk('public')->url($this->image_path);
    }

    /** The post's link with tracking for one platform, so visits and bookings from it show in Live Visitors. */
    public function trackedLink(string $platform): ?string
    {
        if (! $this->link_url) {
            return null;
        }
        $url = Str::startsWith($this->link_url, ['http://', 'https://']) ? $this->link_url : url($this->link_url);
        $glue = str_contains($url, '?') ? '&' : '?';

        return $url.$glue.http_build_query(['utm_source' => $platform, 'utm_medium' => 'social', 'utm_campaign' => 'post-'.$this->id]);
    }

    /** Caption + hashtags + link, ready to paste for one platform. Telugu follows English when both are written. */
    public function shareText(string $platform): string
    {
        $parts = array_filter([trim((string) $this->caption_en), trim((string) $this->caption_te), trim((string) $this->hashtags), $this->trackedLink($platform)]);

        return implode("\n\n", $parts);
    }

    public function isPostedOn(string $platform): bool
    {
        return $this->deliveries->contains(fn ($d) => $d->platform === $platform && $d->status === 'posted');
    }
}
