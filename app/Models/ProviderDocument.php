<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ProviderDocument extends Model
{
    use HasFactory;

    protected $table = 'provider_documents';

    protected $fillable = [
        'provider_id',
        'type',
        'file_url',
        'status',
        'rejection_reason'
    ];
    public function provider() { return $this->belongsTo(Provider::class); }
}
