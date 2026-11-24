<?php

namespace App\Models;

use App\Domain\Access\JurisdictionScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy(JurisdictionScope::class)]
class Alert extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_AUTO_CLOSED = 'auto_closed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details_json' => 'array',
            'opened_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'last_escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class);
    }
}
