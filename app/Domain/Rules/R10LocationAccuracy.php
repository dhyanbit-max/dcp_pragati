<?php

namespace App\Domain\Rules;

final class R10LocationAccuracy implements Rule
{
    public function code(): string
    {
        return 'R10';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => ['max_accuracy_m' => 50]];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        $limit = (float) ($params['max_accuracy_m'] ?? 50);
        $bad = [];
        foreach ($ctx->photos as $i => $p) {
            if ($p->isMocked) {
                $bad[] = ['photo' => $i, 'reason' => 'mock_location'];
            } elseif ($p->accuracyM !== null && $p->accuracyM > $limit) {
                $bad[] = ['photo' => $i, 'reason' => 'low_accuracy', 'accuracy_m' => $p->accuracyM];
            }
        }

        return $bad ? new Failure('rules.R10', ['max_accuracy_m' => $limit, 'photos' => $bad]) : null;
    }
}
