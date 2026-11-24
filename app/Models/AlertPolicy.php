<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertPolicy extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['params_json' => 'array', 'channels' => 'array', 'active' => 'boolean'];
}
