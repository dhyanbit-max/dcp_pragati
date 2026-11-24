<?php

namespace App\Models;

use App\Domain\Access\JurisdictionScope;
use App\Jobs\EvaluateWorkAlerts;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ScopedBy(JurisdictionScope::class)]
class FundRelease extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'integer', 'released_on' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logUnguarded()->logOnlyDirty();
    }

    protected static function booted(): void
    {
        // Keep works.released_total in step with the releases table.
        $sync = function (FundRelease $release) {
            Work::withoutGlobalScopes()->whereKey($release->work_id)->update([
                'released_total' => static::withoutGlobalScopes()->where('work_id', $release->work_id)->sum('amount'),
            ]);
            EvaluateWorkAlerts::dispatch($release->work_id)->afterCommit();
        };
        static::saved($sync);
        static::deleted($sync);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }
}
