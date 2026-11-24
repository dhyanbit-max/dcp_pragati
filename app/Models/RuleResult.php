<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleResult extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['details_json' => 'array'];

    public function progressUpdate(): BelongsTo
    {
        return $this->belongsTo(ProgressUpdate::class);
    }
}
