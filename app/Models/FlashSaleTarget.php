<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlashSaleTarget extends Model
{
    use HasFactory;

    protected $table = 'flash_sale_targets';

    protected $fillable = ['flash_sale_id', 'service_id'];

    public function flashSale() { return $this->belongsTo(FlashSale::class); }
    public function service() { return $this->belongsTo(Service::class); }
}
