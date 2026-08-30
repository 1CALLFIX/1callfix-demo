<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a customer's services cart. Written only through
 * App\Services\Customer\ServiceCartService, which owns the merge rule, the
 * active-service guard and the category/subcategory snapshotting.
 */
class ServiceCartItem extends Model
{
    protected $fillable = [
        'user_id',
        'service_id',
        'category_id',
        'subcategory_id',
        'quantity',
        'selected_options',
        'scheduled_at',
        'customer_note',
        'unit_price_estimate',
    ];

    protected $casts = [
        'selected_options' => 'array',
        'scheduled_at' => 'datetime',
        'quantity' => 'integer',
        'unit_price_estimate' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ServiceSubcategory::class, 'subcategory_id');
    }

    /** The key this line groups under in the cart's "visits" view: its subcategory, or its category when it has none. */
    public function visitGroupKey(): string
    {
        return $this->subcategory_id ? 'sub:'.$this->subcategory_id : 'cat:'.$this->category_id;
    }
}
