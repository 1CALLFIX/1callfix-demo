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
        'disk_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'status',
        'is_current',
        'uploaded_by',
        'upload_source',
        'franchise_staff_id',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'expires_at',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function uploadedBy() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function franchiseStaff() { return $this->belongsTo(User::class, 'franchise_staff_id'); }
    public function reviewedBy() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
