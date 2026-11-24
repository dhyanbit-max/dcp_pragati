<?php

namespace App\Domain\Rules;

final class R07SpendProgressMismatch implements Rule
{
    public function code(): string
    {
        return 'R07';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => ['threshold_pts' => 25]];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        if ($ctx->work->sanctioned <= 0) {
            return null;
        }
        $threshold = (float) ($params['threshold_pts'] ?? 25);
        $financial = $ctx->update->spentCumulative * 100 / $ctx->work->sanctioned;
        $gap = $financial - $ctx->update->physicalPct;

        return $gap > $threshold
            ? new Failure('rules.R07', ['financial_pct' => round($financial, 2), 'physical_pct' => $ctx->update->physicalPct, 'threshold_pts' => $threshold])
            : null;
    }
}
