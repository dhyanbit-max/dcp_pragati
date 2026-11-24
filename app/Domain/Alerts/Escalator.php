<?php

namespace App\Domain\Alerts;

use App\Domain\Access\JurisdictionScope;
use App\Models\Alert;
use App\Models\EscalationPolicy;

/**
 * Moves unhandled alerts up the ladder (docs/08 §2): DPC, DC, DCP Division at the
 * cumulative hours in escalation_policies, measured from when the alert opened.
 * Acknowledging pauses escalation for `ack_pause_days`; after that the alert is open again.
 */
class Escalator
{
    public function __construct(private readonly AlertNotifier $notifier) {}

    /** @return array{reopened: int, escalated: int} */
    public function run(): array
    {
        $pause = (int) config('dcp.alerts.ack_pause_days', 7);
        $reopened = Alert::withoutGlobalScope(JurisdictionScope::class)
            ->where('status', Alert::STATUS_ACKNOWLEDGED)
            ->where('acknowledged_at', '<', now()->subDays($pause))
            ->update(['status' => Alert::STATUS_OPEN, 'updated_at' => now()]);

        $policies = EscalationPolicy::orderBy('level')->get()->groupBy('alert_code');
        $escalated = 0;

        Alert::withoutGlobalScope(JurisdictionScope::class)
            ->where('status', Alert::STATUS_OPEN)
            ->orderBy('id')
            ->each(function (Alert $alert) use ($policies, &$escalated) {
                $hoursOpen = $alert->opened_at->diffInHours(now());
                $target = $policies->get($alert->alert_code, collect())
                    ->filter(fn ($p) => $hoursOpen >= $p->after_hours)
                    ->max('level') ?? 0;

                if ($target > $alert->level) {
                    $alert->update(['level' => $target, 'last_escalated_at' => now()]);
                    $this->notifier->notify($alert, $target);
                    $escalated++;
                }
            });

        return ['reopened' => $reopened, 'escalated' => $escalated];
    }
}
