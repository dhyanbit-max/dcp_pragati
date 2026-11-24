<?php

namespace App\Domain\Rules;

final class WorkFacts
{
    public function __construct(
        public readonly int $sanctioned,
        public readonly int $released,
        public readonly bool $hasSite,
        public readonly int $siteRadiusM,
    ) {}
}
