<?php

namespace App\Domain\Rules;

final class R02PercentNotDecreasing implements Rule
{
    public function code(): string
    {
        return 'R02';
    }

    public function defaults(): array
    {
        return ['severity' => 'block', 'params' => []];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        if ($ctx->last === null) {
            return null;
        }

        return $ctx->update->physicalPct < $ctx->last->physicalPct
            ? new Failure('rules.R02', ['new' => $ctx->update->physicalPct, 'previous' => $ctx->last->physicalPct])
            : null;
    }
}
