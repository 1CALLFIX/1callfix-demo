<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycVerificationVideo extends Model
{
    protected $table = 'kyc_verification_videos';

    protected $fillable = [
        'provider_id', 'disk_path', 'mime_type', 'size_bytes', 'challenge_phrase',
        'status', 'uploaded_by', 'upload_source', 'franchise_staff_id',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function uploadedBy() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function franchiseStaff() { return $this->belongsTo(User::class, 'franchise_staff_id'); }
    public function reviewedBy() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
