<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WHERE a Role's permissions apply for a given user — see the migration's
 * docblock for the full reasoning. scope_id is one of:
 *   country_id / city_id / zone_id / franchise_id / module_id (per scope_type),
 * or null when scope_type = 'global'.
 */
class RoleAssignment extends Model
{
    protected $table = 'role_assignments';

    protected $fillable = ['user_id', 'role_id', 'scope_type', 'scope_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
