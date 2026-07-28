<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Banner extends Model
{
    use HasFactory;

    protected $table = 'banners';

    protected $fillable = [
        'franchise_id',
        'title',
        'image',
        'link',
        'sort_order',
        'is_active'
    ];
    public function franchise() { return $this->belongsTo(Franchise::class); }
}
