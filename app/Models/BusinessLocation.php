<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessLocation extends Model
{
    use HasFactory;

    protected $table = 'business_locations';

    protected $fillable = ['business_account_id', 'address_id', 'label'];

    public function businessAccount() { return $this->belongsTo(BusinessAccount::class); }
    public function address() { return $this->belongsTo(Address::class); }
}
