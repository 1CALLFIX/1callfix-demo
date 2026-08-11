<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Mirrors ProviderDocument's exact shape. */
class FieldWorkerDocument extends Model
{
    use HasFactory;

    protected $table = 'field_worker_documents';

    protected $fillable = ['field_worker_id', 'type', 'file_url', 'status', 'rejection_reason'];

    public function fieldWorker() { return $this->belongsTo(FieldWorker::class); }
}
