<?php

namespace App\Domain\Rules;

final class R05PhotoFreshness implements Rule
{
    public function code(): string
    {
        return 'R05';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => ['max_age_days' => 3]];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        $maxAgeDays = (int) ($params['max_age_days'] ?? 3);
        $bad = [];
        foreach ($ctx->photos as $i => $p) {
            if ($p->takenAt === null) {
                $bad[] = ['photo' => $i, 'reason' => 'no_time'];
            } elseif ($p->takenAt->lt($ctx->update->capturedAt->subDays($maxAgeDays))) {
                $bad[] = ['photo' => $i, 'reason' => 'too_old'];
            } elseif ($ctx->last && $p->takenAt->lt($ctx->last->capturedAt)) {
                $bad[] = ['photo' => $i, 'reason' => 'before_last_update'];
            } elseif ($p->takenAt->gt($ctx->update->receivedAt->addMinutes(10))) {
                $bad[] = ['photo' => $i, 'reason' => 'in_future'];
            }
        }

        return $bad ? new Failure('rules.R05', ['max_age_days' => $maxAgeDays, 'photos' => $bad]) : null;
    }
}
