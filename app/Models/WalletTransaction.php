<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class WalletTransaction extends Model
{
    use HasFactory;

    protected $table = 'wallet_transactions';

    protected $fillable = [
        'wallet_id',
        'amount',
        'is_credit',
        'reason',
        'ref',
        'actor_id',
        'status'
    ];
    protected $casts = ['is_credit' => 'boolean'];
    public function wallet() { return $this->belongsTo(Wallet::class); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
