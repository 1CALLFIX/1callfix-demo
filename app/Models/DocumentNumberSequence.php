<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentNumberSequence extends Model
{
    protected $table = 'document_number_sequences';

    protected $fillable = ['type', 'country_id', 'year', 'last_number'];
}
