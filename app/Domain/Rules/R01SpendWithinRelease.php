<?php

namespace App\Domain\Rules;

final class R01SpendWithinRelease implements Rule
{
    public function code(): string
    {
        return 'R01';
    }

    public function defaults(): array
    {
        return ['severity' => 'block', 'params' => []];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        return $ctx->update->spentCumulative > $ctx->work->released
            ? new Failure('rules.R01', ['spent' => $ctx->update->spentCumulative, 'released' => $ctx->work->released])
            : null;
    }
}
