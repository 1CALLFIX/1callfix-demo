<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ServiceCategory extends Model
{
    use HasFactory;

    protected $table = 'service_categories';

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'icon',
        'description',
        'sort_order',
        'is_active'
    ];

    public function parent() { return $this->belongsTo(ServiceCategory::class, 'parent_id'); }
    public function children() { return $this->hasMany(ServiceCategory::class, 'parent_id'); }
    public function services() { return $this->hasMany(Service::class, 'category_id'); }
}
