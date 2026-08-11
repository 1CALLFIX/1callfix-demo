<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $table = 'notification_logs';

    protected $fillable = [
        'notifiable_type', 'notifiable_id', 'channel',
        'notification_type', 'event', 'status', 'error', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function notifiable()
    {
        return $this->morphTo();
    }
}
