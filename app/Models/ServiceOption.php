<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ServiceOption extends Model
{
    use HasFactory;

    protected $table = 'service_options';

    protected $fillable = [
        'service_option_group_id',
        'name',
        'price_delta',
        'sort_order',
        'is_active'
    ];

    public function group() { return $this->belongsTo(ServiceOptionGroup::class, 'service_option_group_id'); }
}
