<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** F3: an old slug that must keep resolving (301) to the item it belonged to. See SlugManager. */
class SlugRedirect extends Model
{
    protected $table = 'slug_redirects';

    protected $fillable = ['scope', 'old_slug', 'target_type', 'target_id'];
}
