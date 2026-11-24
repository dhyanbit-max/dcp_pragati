<?php

namespace App\Domain\Rules;

/** Rule catalogue R01–R11 (docs/05-rules-engine.md §3). */
final class Rules
{
    /** @return list<Rule> */
    public static function all(): array
    {
        return [
            new R01SpendWithinRelease, new R02PercentNotDecreasing, new R03PhotoGeofence,
            new R04MinimumPhotos, new R05PhotoFreshness, new R06DuplicatePhoto,
            new R07SpendProgressMismatch, new R08StageMatchesPercent, new R09SpendWithoutProgress,
            new R10LocationAccuracy, new R11SpendWithinSanction, new R12PhotoNotFromCamera,
        ];
    }
}
