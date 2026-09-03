<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the admin-editable "why partner with us" list rendered on the
 * public /coming-soon/partners landing page. Managed from the Website / CMS
 * admin screen (App\Livewire\Cms\Manage), same shape as Faq.
 *
 * `icon` is constrained to ICONS — each key is an <x-icon> glyph that is
 * already shipped, so the public page can render it without a fallback.
 */
class PartnerBenefit extends Model
{
    use HasFactory;

    protected $table = 'partner_benefits';

    protected $fillable = [
        'icon',
        'title',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The fixed icon set an admin can choose from. Key => human label for
     * the admin <select>; the key is stored and passed straight to
     * <x-icon name="..."> on the public page.
     *
     * Every key must exist in resources/views/components/icon.blade.php.
     */
    public const ICONS = [
        'wallet' => 'Wallet / earnings',
        'banknotes' => 'Banknotes / payouts',
        'clock' => 'Clock / flexible hours',
        'shield' => 'Shield / verified & protected',
        'clipboard' => 'Clipboard / jobs',
        'map-pin' => 'Map pin / work nearby',
        'sparkles' => 'Sparkles / grow',
        'star' => 'Star / ratings',
        'chat' => 'Chat / support',
        'users' => 'Users / community',
        'bolt' => 'Bolt / fast',
        'check-circle' => 'Check / simple',
    ];

    public const DEFAULT_ICON = 'sparkles';

    /** Active rows in display order — the exact set and order the public page renders. */
    public function scopeForDisplay(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
