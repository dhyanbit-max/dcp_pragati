<?php

namespace App\Domain\Alerts;

use App\Models\Alert;
use App\Models\User;
use InvalidArgumentException;

/** Acknowledge / resolve, shared by the web inbox and the API. Callers load the alert through the jurisdiction scope. */
class AlertActions
{
    public function acknowledge(Alert $alert, User $user, string $note): Alert
    {
        if ($alert->status !== Alert::STATUS_OPEN) {
            throw new InvalidArgumentException(__('Only open alerts can be acknowledged.'));
        }
        $alert->update([
            'status' => Alert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'resolution_note' => $note,
            'resolved_by' => $user->id,
        ]);

        return $alert;
    }

    public function resolve(Alert $alert, User $user, string $note): Alert
    {
        if (! in_array($alert->status, [Alert::STATUS_OPEN, Alert::STATUS_ACKNOWLEDGED], true)) {
            throw new InvalidArgumentException(__('This alert is already closed.'));
        }
        $alert->update([
            'status' => Alert::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $user->id,
            'resolution_note' => $note,
        ]);

        return $alert;
    }
}
