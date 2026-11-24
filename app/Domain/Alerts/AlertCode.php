<?php

namespace App\Domain\Alerts;

/** Alert catalogue, docs/08 §1. */
final class AlertCode
{
    public const NO_UPDATE = 'A01';

    public const MISSED_MILESTONE = 'A02';

    public const UNSPENT_RELEASE = 'A03';

    public const SPEND_MISMATCH = 'A04';

    public const APPROVAL_OVERDUE = 'A05';

    public const ALL = [self::NO_UPDATE, self::MISSED_MILESTONE, self::UNSPENT_RELEASE, self::SPEND_MISMATCH, self::APPROVAL_OVERDUE];

    public static function label(string $code, ?string $locale = null): string
    {
        return __("alert_types.$code.title", [], $locale);
    }
}
