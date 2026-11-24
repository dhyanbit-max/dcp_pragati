<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageTemplateItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['min_pct' => 'float', 'max_pct' => 'float'];

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{"name_$locale"} ?: $this->name_en;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(StageTemplate::class, 'stage_template_id');
    }
}
