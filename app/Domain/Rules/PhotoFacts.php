<?php

namespace App\Domain\Rules;

use Carbon\CarbonImmutable;

final class PhotoFacts
{
    public function __construct(
        public readonly bool $hasLocation,
        public readonly ?float $distanceM,
        public readonly ?float $accuracyM,
        public readonly ?CarbonImmutable $takenAt,
        public readonly bool $isDuplicate,
        public readonly bool $isMocked,
        public readonly bool $fromCamera = true,
    ) {}
}
