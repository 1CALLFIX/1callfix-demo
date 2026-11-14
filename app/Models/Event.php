<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Events E1 (owner 2026-11-13): one event with its ticket types. The organiser builds it as a draft, sends it for
 * review, and only an admin-approved event can go on sale (E2). Status: draft → in_review → approved | rejected;
 * approved → cancelled | completed.
 */
class Event extends Model
{
    public const STATUSES = ['draft', 'in_review', 'approved', 'rejected', 'cancelled', 'completed'];

    public const STATUS_LABELS = [
        'draft' => 'Draft', 'in_review' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Needs changes',
        'cancelled' => 'Cancelled', 'completed' => 'Finished',
    ];

    protected $fillable = [
        'event_organiser_id', 'franchise_id', 'zone_id', 'title', 'slug', 'category', 'description', 'cover_image', 'photos',
        'venue_name', 'venue_address', 'lat', 'lng', 'accommodation_id', 'starts_at', 'ends_at', 'min_age', 'terms',
        'status', 'rejection_reason', 'submitted_at', 'approved_at', 'is_featured', 'cancelled_at', 'cancellation_reason', 'cancelled_by', 'completed_at',
    ];

    protected $casts = [
        'photos' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_featured' => 'boolean',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'min_age' => 'integer',
    ];

    public function organiser() { return $this->belongsTo(EventOrganiser::class, 'event_organiser_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function accommodation() { return $this->belongsTo(Accommodation::class); }
    public function ticketTypes() { return $this->hasMany(EventTicketType::class)->orderBy('sort_order')->orderBy('id'); }
    public function orders() { return $this->hasMany(EventOrder::class); }
    public function tickets() { return $this->hasMany(EventTicket::class); }

    /** The organiser may still change it: anything not yet approved, finished or cancelled. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? Str::headline((string) $this->status);
    }

    public function coverUrl(): ?string
    {
        $p = $this->cover_image;

        return $p === null ? null : (str_starts_with($p, 'http') ? $p : Storage::disk('public')->url($p));
    }

    /** @return list<string> the event's other photos as links (owner 2026-11-14: every photo can be seen). */
    public function photoUrls(): array
    {
        return array_values(array_filter(array_map(fn ($p) => ! is_string($p) || $p === '' ? null : (str_starts_with($p, 'http') ? $p : Storage::disk('public')->url($p)), (array) ($this->photos ?? []))));
    }

    /** A clean, readable slug; -2, -3 only on a clash (public link rule). */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 150, '')) ?: 'event';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
