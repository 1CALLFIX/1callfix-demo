<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Wallet extends Model
{
    use HasFactory;

    protected $table = 'wallets';

    protected $fillable = [
        'user_id',
        'balance'
    ];

    // frozen_at / frozen_reason / frozen_by (EARN3 D5) are deliberately NOT
    // fillable — only WalletFreezeService writes them, via forceFill().
    protected $casts = ['frozen_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function frozenBy() { return $this->belongsTo(User::class, 'frozen_by'); }
    public function transactions() { return $this->hasMany(WalletTransaction::class); }
}
