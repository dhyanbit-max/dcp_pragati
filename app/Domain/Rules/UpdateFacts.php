<?php

namespace App\Domain\Rules;

use Carbon\CarbonImmutable;

final class UpdateFacts
{
    public function __construct(
        public readonly int $stageItemId,
        public readonly float $stageMinPct,
        public readonly float $stageMaxPct,
        public readonly float $physicalPct,
        public readonly int $spentCumulative,
        public readonly CarbonImmutable $capturedAt,
        public readonly CarbonImmutable $receivedAt,
        public readonly ?float $deviceAccuracyM,
    ) {}
}
