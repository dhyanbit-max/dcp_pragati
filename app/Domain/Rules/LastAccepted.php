<?php

namespace App\Domain\Rules;

use Carbon\CarbonImmutable;

final class LastAccepted
{
    public function __construct(
        public readonly int $stageItemId,
        public readonly float $physicalPct,
        public readonly int $spentCumulative,
        public readonly CarbonImmutable $capturedAt,
    ) {}
}
