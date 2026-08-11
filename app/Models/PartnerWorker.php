<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The Partner <-> Worker relationship — a link table, not a column on
 * either side (Phase B0 architecture §17), so a worker can hold more than
 * one link if that's ever approved, and a worker with zero rows here is
 * still a fully valid platform-direct worker. No scheduling/availability-
 * conflict logic — out of scope for B0.1.
 */
class PartnerWorker extends Model
{
    use HasFactory;

    protected $table = 'partner_workers';

    protected $fillable = ['provider_id', 'field_worker_id', 'status', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function fieldWorker() { return $this->belongsTo(FieldWorker::class); }
}
