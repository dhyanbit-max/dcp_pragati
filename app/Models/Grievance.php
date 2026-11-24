<?php

namespace App\Models;

use App\Domain\Grievances\GrievanceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A citizen grievance about a work or a place (docs/20). Scoped to the officer's district / co-district.
 * Citizen name and mobile are personal data: never logged in the audit trail, cleared after retention.
 */
#[ScopedBy(GrievanceScope::class)]
class Grievance extends Model
{
    use LogsActivity;

    public const SUBMITTED = 'submitted';

    public const ACKNOWLEDGED = 'acknowledged';

    public const IN_PROGRESS = 'in_progress';

    public const RESOLVED = 'resolved';

    public const REJECTED = 'rejected';

    public const OPEN = [self::SUBMITTED, self::ACKNOWLEDGED, self::IN_PROGRESS];

    /** Allowed moves. Resolved and rejected are final; a reopened case is a new grievance. */
    public const TRANSITIONS = [
        self::SUBMITTED => [self::ACKNOWLEDGED, self::IN_PROGRESS, self::RESOLVED, self::REJECTED],
        self::ACKNOWLEDGED => [self::IN_PROGRESS, self::RESOLVED, self::REJECTED],
        self::IN_PROGRESS => [self::RESOLVED, self::REJECTED],
        self::RESOLVED => [],
        self::REJECTED => [],
    ];

    private const PERSONAL = ['citizen_name', 'citizen_mobile'];

    protected $guarded = ['id'];

    protected $hidden = self::PERSONAL;

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'mobile_verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'acknowledge_by' => 'datetime',
            'resolve_by' => 'datetime',
            'acknowledged_at' => 'datetime',
            'closed_at' => 'datetime',
            'personal_data_purged_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logUnguarded()->logExcept(self::PERSONAL)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function coDistrict(): BelongsTo
    {
        return $this->belongsTo(CoDistrict::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GrievanceEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('grievances.status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && (
            ($this->status === self::SUBMITTED && $this->acknowledge_by->isPast()) || $this->resolve_by->isPast()
        );
    }

    /** 98765 43210 → 98XXXXX210, for screens that only need to recognise the number. */
    public function maskedMobile(): ?string
    {
        $m = $this->citizen_mobile;

        return $m ? substr($m, 0, 2).str_repeat('X', max(0, strlen($m) - 5)).substr($m, -3) : null;
    }
}
