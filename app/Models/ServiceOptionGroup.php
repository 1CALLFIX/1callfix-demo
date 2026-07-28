<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ServiceOptionGroup extends Model
{
    use HasFactory;

    protected $table = 'service_option_groups';

    protected $fillable = [
        'service_id',
        'name',
        'is_required',
        'allow_multiple',
        'sort_order'
    ];

    public function service() { return $this->belongsTo(Service::class); }
    public function options() { return $this->hasMany(ServiceOption::class); }
}
