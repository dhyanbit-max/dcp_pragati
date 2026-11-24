<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['definition_json' => 'array', 'formats' => 'array'];
}
