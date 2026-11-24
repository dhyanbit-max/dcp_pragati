<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkStage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['planned_date' => 'date', 'achieved_date' => 'date'];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StageTemplateItem::class, 'stage_template_item_id');
    }
}
