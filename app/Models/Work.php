<?php

namespace App\Models;

use App\Domain\Access\JurisdictionScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A DCP work / scheme. Money in paise. `site` is a PostGIS point (EPSG:4326);
 * use setSite() to write it and withSiteCoords() to read lat/lng.
 */
#[ScopedBy(JurisdictionScope::class)]
class Work extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STALLED = 'stalled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id', 'site'];

    protected $hidden = ['site'];

    protected function casts(): array
    {
        return [
            'sanctioned_amount' => 'integer',
            'released_total' => 'integer',
            'spent_total' => 'integer',
            'physical_pct' => 'float',
            'sanction_date' => 'date',
            'target_completion_date' => 'date',
            'last_accepted_update_at' => 'datetime',
            'is_pilot' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logUnguarded()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function coDistrict(): BelongsTo
    {
        return $this->belongsTo(CoDistrict::class);
    }

    public function constituency(): BelongsTo
    {
        return $this->belongsTo(Constituency::class);
    }

    public function schemeType(): BelongsTo
    {
        return $this->belongsTo(SchemeType::class);
    }

    public function fundSource(): BelongsTo
    {
        return $this->belongsTo(FundSource::class);
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(ImplementingAgency::class, 'implementing_agency_id');
    }

    public function stageTemplate(): BelongsTo
    {
        return $this->belongsTo(StageTemplate::class);
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(StageTemplateItem::class, 'current_stage_item_id');
    }

    public function assignedFieldUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_field_user_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(FundRelease::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(WorkStage::class);
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(ProgressUpdate::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkAssignment::class);
    }

    public function setSite(?float $lat, ?float $lng): void
    {
        $value = ($lat === null || $lng === null)
            ? null
            : DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)', $lng, $lat));

        static::withoutGlobalScopes()->whereKey($this->getKey())->update(['site' => $value]);
    }

    public function scopeWithSiteCoords(Builder $query): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('works.*');
        }

        return $query->addSelect([
            DB::raw('ST_Y(works.site) as site_lat'),
            DB::raw('ST_X(works.site) as site_lng'),
        ]);
    }

    public function hasSite(): bool
    {
        return static::withoutGlobalScopes()->whereKey($this->getKey())->whereNotNull('site')->exists();
    }

    /** Distance in metres from the work site to a point, or null if the work has no site. */
    public function distanceTo(float $lat, float $lng): ?float
    {
        $d = DB::selectOne(
            'SELECT ST_Distance(site::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS d FROM works WHERE id = ? AND site IS NOT NULL',
            [$lng, $lat, $this->getKey()]
        );

        return $d ? round((float) $d->d, 1) : null;
    }

    public function financialPct(): float
    {
        return $this->sanctioned_amount > 0 ? round($this->spent_total * 100 / $this->sanctioned_amount, 2) : 0.0;
    }

    public function utilisationPct(): float
    {
        return $this->released_total > 0 ? round($this->spent_total * 100 / $this->released_total, 2) : 0.0;
    }

    /** The accepted update with the latest capture time, for eager loading on lists (who updated last). */
    public function latestAcceptedUpdate(): HasOne
    {
        return $this->hasOne(ProgressUpdate::class)->ofMany(['captured_at' => 'max', 'id' => 'max'], fn ($q) => $q->accepted());
    }

    public function lastAcceptedUpdate(): ?ProgressUpdate
    {
        return $this->progressUpdates()->where('status', ProgressUpdate::STATUS_ACCEPTED)
            ->latest('captured_at')->latest('id')->first();
    }
}
