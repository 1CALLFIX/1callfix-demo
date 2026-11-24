<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Field associate (owner 2026-11-23): a person who visits shops and restaurants and signs them up. Their link
 * (/fa/{code}) or QR opens the partner sign-up; the shop then remembers who added it (Store::field_associate_id).
 * Rewards are tracked in FieldAssociateReward and paid outside the app from the monthly report.
 */
class FieldAssociate extends Model
{
    protected $fillable = ['user_id', 'franchise_id', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(FieldAssociateReward::class);
    }

    /** FA001, FA002, ...: short enough to say out loud at a shop counter. */
    public static function codeFor(int $id): string
    {
        return 'FA'.str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }

    /** The link (and QR) that starts a shop sign-up credited to this associate. */
    public function link(): string
    {
        return route('field-associate.start', $this->code);
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(FieldAssociateBonus::class);
    }

    /** A test login (@1callfix.test, `php artisan test-accounts`): never on the pay sheet, never mixed with real shops. */
    public function isTest(): bool
    {
        return self::isTestUser($this->user);
    }

    public static function isTestUser(?User $user): bool
    {
        return $user !== null && str_ends_with(strtolower((string) $user->email), \App\Console\Commands\TestAccountsCommand::DOMAIN);
    }

    public static function findActiveByCode(string $code): ?self
    {
        return self::query()->where('code', strtoupper(trim($code)))->where('is_active', true)->first();
    }
}
