<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class FranchiseApplication extends Model
{
    use HasFactory;

    protected $table = 'franchise_applications';

    protected $fillable = [
        'applicant_name',
        'phone',
        'email',
        'proposed_city',
        'notes',
        'status'
    ];
}
