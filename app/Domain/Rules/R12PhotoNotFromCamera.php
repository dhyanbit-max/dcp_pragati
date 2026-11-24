<?php

namespace App\Domain\Rules;

/** Photos uploaded as files (web) cannot prove where or when they were taken, so they always go to the DPC. */
final class R12PhotoNotFromCamera implements Rule
{
    public function code(): string
    {
        return 'R12';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => []];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        $uploaded = array_keys(array_filter($ctx->photos, fn (PhotoFacts $p) => ! $p->fromCamera));

        return $uploaded ? new Failure('rules.R12', ['count' => count($uploaded), 'photos' => $uploaded]) : null;
    }
}
