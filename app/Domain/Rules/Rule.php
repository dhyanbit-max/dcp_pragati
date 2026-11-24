<?php

namespace App\Domain\Rules;

interface Rule
{
    /** e.g. 'R01' */
    public function code(): string;

    /** Default severity and params when no rule_definitions row exists. */
    public function defaults(): array;

    /** Returns null on pass, or a failure (severity is applied by the engine). */
    public function evaluate(Context $ctx, array $params): ?Failure;
}
