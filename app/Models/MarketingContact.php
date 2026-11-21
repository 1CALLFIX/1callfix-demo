<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** An old customer who is not on the app yet (Admin → Marketing & Offers → Contacts, owner 2026-11-21). */
class MarketingContact extends Model
{
    protected $fillable = [
        'name', 'phone', 'email', 'franchise_id', 'city_id', 'country_id', 'city_name', 'import_batch',
        'email_invited_at', 'whatsapp_invited_at', 'joined_user_id', 'unsubscribed_at', 'unsubscribe_token', 'imported_by',
    ];

    protected function casts(): array
    {
        return ['email_invited_at' => 'datetime', 'whatsapp_invited_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contact) {
            $contact->unsubscribe_token ??= Str::random(48);
        });
    }

    public function joinedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'joined_user_id');
    }

    /**
     * Marks contacts whose mobile has since signed up (last 10 digits match a user's phone). Cheap: only contacts not
     * yet joined, and one query per 500. Returns how many were newly matched.
     */
    public static function syncJoined(): int
    {
        $matched = 0;
        static::query()->whereNull('joined_user_id')->whereNotNull('phone')->select(['id', 'phone'])
            ->chunkById(500, function ($contacts) use (&$matched) {
                $byTail = $contacts->keyBy(fn ($c) => substr(preg_replace('/\D/', '', (string) $c->phone), -10));
                $users = User::query()->where('role', 'customer')
                    ->where(function ($q) use ($byTail) {
                        foreach ($byTail->keys() as $tail) {
                            $q->orWhere('phone', 'like', '%'.$tail);
                        }
                    })->get(['id', 'phone']);
                foreach ($users as $user) {
                    $contact = $byTail->get(substr(preg_replace('/\D/', '', (string) $user->phone), -10));
                    if ($contact) {
                        DB::table('marketing_contacts')->where('id', $contact->id)->whereNull('joined_user_id')->update(['joined_user_id' => $user->id, 'updated_at' => now()]);
                        $matched++;
                    }
                }
            });

        return $matched;
    }
}
