<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class PushNotification extends Model
{
    use HasFactory;

    protected $table = 'push_notifications';

    protected $fillable = [
        'franchise_id',
        'title',
        'body',
        'audience',
        'sent_at'
    ];
    protected $casts = ['sent_at' => 'datetime'];
    public function franchise() { return $this->belongsTo(Franchise::class); }
}
