<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['boundary'];

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{"name_$locale"} ?: $this->name_en;
    }

    public function coDistricts(): HasMany
    {
        return $this->hasMany(CoDistrict::class);
    }
}
