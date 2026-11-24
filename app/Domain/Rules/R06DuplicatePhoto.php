<?php

namespace App\Domain\Rules;

final class R06DuplicatePhoto implements Rule
{
    public function code(): string
    {
        return 'R06';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => []];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        $dupes = array_keys(array_filter($ctx->photos, fn (PhotoFacts $p) => $p->isDuplicate));

        return $dupes ? new Failure('rules.R06', ['photos' => $dupes]) : null;
    }
}
