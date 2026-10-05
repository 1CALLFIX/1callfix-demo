<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** F3: franchise-editable copy for a city's public pages. Never holds a slug. */
class CityPageContent extends Model
{
    public const SUBJECT_CITY = 'city';
    public const SUBJECT_CATEGORY = 'category';
    public const SUBJECT_SERVICE = 'service';

    protected $table = 'city_page_contents';

    protected $fillable = ['city_id', 'subject_type', 'subject_id', 'title', 'meta_description', 'intro', 'updated_by'];

    public function city() { return $this->belongsTo(City::class); }
}
