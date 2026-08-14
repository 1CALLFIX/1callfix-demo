<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedDocument extends Model
{
    public $timestamps = false;

    protected $table = 'generated_documents';

    protected $fillable = ['number', 'type', 'documentable_type', 'documentable_id', 'country_id', 'generated_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function documentable() { return $this->morphTo(); }
    public function country() { return $this->belongsTo(Country::class); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by'); }
}
