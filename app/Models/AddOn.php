<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddOn extends Model
{
    use HasFactory;

    protected $table = 'add_ons';

    protected $fillable = ['store_id', 'name', 'price', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function store() { return $this->belongsTo(Store::class); }
}
