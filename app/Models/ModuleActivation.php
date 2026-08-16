<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One explicit activation decision at one exact geography scope. See the
 * creating migration (2026_08_16_901000) and ModuleActivationService for
 * the full resolution/authority model — this class is deliberately thin,
 * all real logic lives in the service so there is exactly one place that
 * decides "is this module active," not one place that stores it and
 * another that separately re-derives the rule.
 */
class ModuleActivation extends Model
{
    protected $table = 'module_activations';

    public const SCOPE_TYPES = ['zone', 'franchise', 'city', 'country'];

    protected $fillable = [
        'module_id',
        'scope_type',
        'scope_id',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
