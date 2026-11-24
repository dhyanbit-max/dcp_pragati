<?php

namespace App\Domain\Progress;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Validated input for a progress update, from any channel. Money in paise. */
final class ProgressInput
{
    /** @param list<string> $photoUuids */
    public function __construct(
        public readonly string $clientUuid,
        public readonly int $workId,
        public readonly int $stageItemId,
        public readonly float $physicalPct,
        public readonly int $spentCumulative,
        public readonly CarbonInterface $capturedAt,
        public readonly string $channel = 'app',
        public readonly ?string $remarks = null,
        public readonly array $photoUuids = [],
        public readonly ?float $deviceLat = null,
        public readonly ?float $deviceLng = null,
        public readonly ?float $deviceAccuracyM = null,
        public readonly ?string $appVersion = null,
        public readonly ?int $formSeconds = null,
    ) {}

    public static function fromArray(array $a, string $channel): self
    {
        return new self(
            clientUuid: $a['client_uuid'],
            workId: (int) $a['work_id'],
            stageItemId: (int) $a['stage_item_id'],
            physicalPct: (float) $a['physical_pct'],
            spentCumulative: (int) $a['spent_cumulative'],
            // Normalise to the app time zone: Eloquent stores dates without an offset, so a UTC time
            // from the field app ("…Z") would otherwise be read back 5.5 hours early.
            capturedAt: Carbon::parse($a['captured_at'])->setTimezone(config('app.timezone')),
            channel: $channel,
            remarks: $a['remarks'] ?? null,
            photoUuids: $a['photo_uuids'] ?? [],
            deviceLat: isset($a['device']['lat']) ? (float) $a['device']['lat'] : null,
            deviceLng: isset($a['device']['lng']) ? (float) $a['device']['lng'] : null,
            deviceAccuracyM: isset($a['device']['accuracy_m']) ? (float) $a['device']['accuracy_m'] : null,
            appVersion: $a['device']['app_version'] ?? null,
            formSeconds: isset($a['form_seconds']) ? (int) $a['form_seconds'] : null,
        );
    }
}
