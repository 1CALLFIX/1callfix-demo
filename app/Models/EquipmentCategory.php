<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** RENTAL MODULE IMPLEMENTATION -- Equipment/Machinery taxonomy (construction, machinery, tools, generators, agricultural, lifting, other). */
class EquipmentCategory extends Model
{
    protected $table = 'equipment_categories';

    protected $fillable = ['name', 'slug', 'icon', 'requires_inspection', 'is_active'];

    protected $casts = ['requires_inspection' => 'boolean', 'is_active' => 'boolean'];

    public function items() { return $this->hasMany(EquipmentItem::class); }
}
