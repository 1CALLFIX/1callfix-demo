<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_log';

    protected $fillable = [
        'causer_id',
        'subject_type',
        'subject_id',
        'description',
        'properties'
    ];
    protected $casts = ['properties' => 'array'];
    public function causer() { return $this->belongsTo(User::class, 'causer_id'); }
}
