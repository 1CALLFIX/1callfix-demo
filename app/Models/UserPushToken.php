<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Android app's push token for one account (see the create_user_push_tokens_table migration). */
class UserPushToken extends Model
{
    protected $fillable = ['user_id', 'app', 'token'];

    public function user() { return $this->belongsTo(User::class); }
}
