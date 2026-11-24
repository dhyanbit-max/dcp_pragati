<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImplementingAgency extends Model
{
    protected $guarded = ['id'];

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{"name_$locale"} ?: $this->name_en;
    }
}
