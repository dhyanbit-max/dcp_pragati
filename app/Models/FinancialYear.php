<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];
}
