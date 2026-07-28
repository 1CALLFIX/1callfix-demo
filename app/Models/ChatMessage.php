<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ChatMessage extends Model
{
    use HasFactory;

    protected $table = 'chat_messages';

    protected $fillable = [
        'booking_id',
        'sender_id',
        'receiver_id',
        'message',
        'attachment_url',
        'read_at'
    ];

    protected $casts = ['read_at' => 'datetime'];
    public function booking() { return $this->belongsTo(Booking::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'receiver_id'); }
}
