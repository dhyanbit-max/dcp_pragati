<?php

namespace App\Domain\Rules;

final class R11SpendWithinSanction implements Rule
{
    public function code(): string
    {
        return 'R11';
    }

    public function defaults(): array
    {
        return ['severity' => 'block', 'params' => []];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        return $ctx->update->spentCumulative > $ctx->work->sanctioned
            ? new Failure('rules.R11', ['spent' => $ctx->update->spentCumulative, 'sanctioned' => $ctx->work->sanctioned])
            : null;
    }
}
