<?php

namespace App\Domain\Rules;

final class R08StageMatchesPercent implements Rule
{
    public function code(): string
    {
        return 'R08';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => []];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        $u = $ctx->update;

        return ($u->physicalPct < $u->stageMinPct || $u->physicalPct > $u->stageMaxPct)
            ? new Failure('rules.R08', ['pct' => $u->physicalPct, 'min' => $u->stageMinPct, 'max' => $u->stageMaxPct])
            : null;
    }
}
