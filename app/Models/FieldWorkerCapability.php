<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per capability a FieldWorker holds — a worker can have several
 * (see App\Support\WorkerTypes for the valid capability_type values).
 * service_category_id only applies to Service-vertical capabilities
 * (service_technician/handyman); it's null for parcel_rider/taxi_driver/etc.
 */
class FieldWorkerCapability extends Model
{
    use HasFactory;

    protected $table = 'field_worker_capabilities';

    protected $fillable = ['field_worker_id', 'capability_type', 'service_category_id'];

    public function fieldWorker() { return $this->belongsTo(FieldWorker::class); }
    public function serviceCategory() { return $this->belongsTo(ServiceCategory::class); }
}
