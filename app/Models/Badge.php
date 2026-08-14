<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A badge TYPE ("NEW", "FEATURED", a custom admin-created one) -- see the
 * create_badges_table migration's own docblock for the full manual/
 * automatic design. Not to be confused with ProviderBadge, a separate,
 * narrower, pre-existing provider trust/KYC pivot (gold/silver/top_rated)
 * this engine does not touch or replace.
 */
class Badge extends Model
{
    use HasFactory;

    protected $table = 'badges';

    protected $fillable = [
        'key', 'label', 'description', 'icon', 'text_color', 'bg_color',
        'priority', 'mode', 'rule_type', 'rule_config', 'default_duration_days', 'is_active',
    ];

    protected $casts = [
        'rule_config' => 'array',
        'is_active' => 'boolean',
    ];

    public function assignments() { return $this->hasMany(BadgeAssignment::class); }

    public function isAutomatic(): bool
    {
        return $this->mode === 'automatic';
    }

    /** Style + label shape for customer-facing serialization — see BadgeService::badgesFor(). */
    public function toDisplayArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'text_color' => $this->text_color,
            'bg_color' => $this->bg_color,
            'priority' => $this->priority,
        ];
    }
}
