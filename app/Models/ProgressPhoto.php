<?php

namespace App\Models;

use App\Domain\Access\JurisdictionScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(JurisdictionScope::class)]
class ProgressPhoto extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'exif_json'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_m' => 'float',
            'distance_from_site_m' => 'float',
            'is_mocked' => 'boolean',
            'taken_at' => 'datetime',
            'exif_json' => 'array',
        ];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function progressUpdate(): BelongsTo
    {
        return $this->belongsTo(ProgressUpdate::class);
    }
}
