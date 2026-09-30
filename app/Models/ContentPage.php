<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ContentPage extends Model
{
    use HasFactory;

    protected $table = 'content_pages';

    protected $fillable = [
        'slug',
        'title',
        'content',
        'is_active',
        'show_in_footer',
        'footer_order',
        'meta_description',
    ];

    protected $casts = ['is_active' => 'boolean', 'show_in_footer' => 'boolean'];
}
