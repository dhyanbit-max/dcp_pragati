<?php

namespace App\Models;

use App\Domain\Access\JurisdictionScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A progress update. Only `accepted` updates may feed dashboards, alerts, reports or e-Rupantor. */
#[ScopedBy(JurisdictionScope::class)]
class ProgressUpdate extends Model
{
    use LogsActivity;

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_RETURNED = 'returned';

    public const CHANNELS = ['app', 'web', 'bulk', 'import'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'physical_pct' => 'float',
            'spent_cumulative' => 'integer',
            'captured_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'is_flagged' => 'boolean',
            'device_lat' => 'float',
            'device_lng' => 'float',
            'device_accuracy_m' => 'float',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'is_flagged', 'physical_pct', 'spent_cumulative', 'stage_item_id', 'reviewed_by', 'review_note'])
            ->logOnlyDirty();
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function stageItem(): BelongsTo
    {
        return $this->belongsTo(StageTemplateItem::class, 'stage_item_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProgressPhoto::class);
    }

    public function ruleResults(): HasMany
    {
        return $this->hasMany(RuleResult::class);
    }

    public function scopeAccepted(Builder $q): Builder
    {
        return $q->where($this->getTable().'.status', self::STATUS_ACCEPTED);
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where($this->getTable().'.status', self::STATUS_PENDING);
    }
}
