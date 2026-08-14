<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycDocumentRequirement extends Model
{
    protected $table = 'kyc_document_requirements';

    protected $fillable = [
        'applicable_type', 'document_type', 'label', 'is_required',
        'country_id', 'sort_order', 'is_active',
    ];

    protected $casts = ['is_required' => 'boolean', 'is_active' => 'boolean'];

    public function country() { return $this->belongsTo(Country::class); }
}
