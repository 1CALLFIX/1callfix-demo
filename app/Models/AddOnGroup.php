<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Food F1: a set of add-ons with a choosing rule — "Choose your base" (min 1, max 1), "Extra toppings" (min 0,
 * max 3). min_select 0 = optional. The rule is checked again at order time (F2), never only in the browser.
 */
class AddOnGroup extends Model
{
    use SoftDeletes;

    protected $table = 'add_on_groups';

    protected $fillable = ['store_id', 'name', 'min_select', 'max_select', 'position', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'min_select' => 'integer', 'max_select' => 'integer'];

    public function store() { return $this->belongsTo(Store::class); }
    public function addOns() { return $this->hasMany(AddOn::class); }
    public function products() { return $this->belongsToMany(Product::class, 'product_add_on_group')->withPivot('position'); }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    /** Plain words for the customer and seller: "Pick 1", "Pick up to 3", "Pick 2 to 4", "Optional, up to 3". */
    public function ruleText(): string
    {
        return match (true) {
            $this->min_select === 0 => 'Optional, up to '.$this->max_select,
            $this->min_select === $this->max_select => 'Pick '.$this->min_select,
            default => 'Pick '.$this->min_select.' to '.$this->max_select,
        };
    }

    /**
     * Is this choice of add-on ids valid for the group? Returns an error in plain words, or null.
     *
     * @param  int[]  $chosenIds  ids the customer chose (any group); only this group's active ones count
     */
    public function problemWith(array $chosenIds): ?string
    {
        $mine = $this->addOns()->where('is_active', true)->whereIn('id', $chosenIds)->count();

        if ($mine < $this->min_select) {
            return "{$this->name}: ".strtolower($this->ruleText()).'.';
        }
        if ($mine > $this->max_select) {
            return "{$this->name}: at most {$this->max_select}.";
        }

        return null;
    }
}
