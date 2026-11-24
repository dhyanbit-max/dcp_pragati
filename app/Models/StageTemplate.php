<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageTemplate extends Model
{
    protected $guarded = ['id'];

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{"name_$locale"} ?: $this->name_en;
    }

    public function items(): HasMany
    {
        return $this->hasMany(StageTemplateItem::class)->orderBy('seq');
    }
}
