<?php

namespace App\Domain\Rules;

final class R04MinimumPhotos implements Rule
{
    public function code(): string
    {
        return 'R04';
    }

    public function defaults(): array
    {
        return ['severity' => 'block', 'params' => ['min' => 2]];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        $min = (int) ($params['min'] ?? 2);
        if (! $ctx->progressChanged()) {
            return null;
        }

        return count($ctx->photos) < $min ? new Failure('rules.R04', ['min' => $min, 'count' => count($ctx->photos)]) : null;
    }
}
