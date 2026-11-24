<?php

namespace App\Domain\Rules;

use Carbon\CarbonImmutable;

/**
 * Everything a rule needs, precomputed. Rules are pure functions of this context, so the same
 * JSON fixtures (tests/fixtures/rules) can test this engine and the Dart mirror in the field app.
 */
final class Context
{
    /**
     * @param  list<PhotoFacts>  $photos
     * @param  list<array{physical_pct: float, spent_cumulative: int}>  $history  recent accepted updates, newest first
     */
    public function __construct(
        public readonly WorkFacts $work,
        public readonly UpdateFacts $update,
        public readonly ?LastAccepted $last,
        public readonly array $photos = [],
        public readonly array $history = [],
    ) {}

    public static function fromArray(array $a): self
    {
        $w = $a['work'];
        $u = $a['update'];
        $l = $a['last_accepted'] ?? null;

        return new self(
            work: new WorkFacts(
                sanctioned: (int) $w['sanctioned'],
                released: (int) $w['released'],
                hasSite: (bool) ($w['has_site'] ?? true),
                siteRadiusM: (int) ($w['site_radius_m'] ?? 200),
            ),
            update: new UpdateFacts(
                stageItemId: (int) $u['stage_item_id'],
                stageMinPct: (float) $u['stage_min_pct'],
                stageMaxPct: (float) $u['stage_max_pct'],
                physicalPct: (float) $u['physical_pct'],
                spentCumulative: (int) $u['spent_cumulative'],
                capturedAt: CarbonImmutable::parse($u['captured_at']),
                receivedAt: CarbonImmutable::parse($u['received_at'] ?? $u['captured_at']),
                deviceAccuracyM: isset($u['device_accuracy_m']) ? (float) $u['device_accuracy_m'] : null,
            ),
            last: $l === null ? null : new LastAccepted(
                stageItemId: (int) $l['stage_item_id'],
                physicalPct: (float) $l['physical_pct'],
                spentCumulative: (int) $l['spent_cumulative'],
                capturedAt: CarbonImmutable::parse($l['captured_at']),
            ),
            photos: array_map(fn (array $p) => new PhotoFacts(
                hasLocation: (bool) ($p['has_location'] ?? true),
                distanceM: isset($p['distance_m']) ? (float) $p['distance_m'] : null,
                accuracyM: isset($p['accuracy_m']) ? (float) $p['accuracy_m'] : null,
                takenAt: isset($p['taken_at']) ? CarbonImmutable::parse($p['taken_at']) : null,
                isDuplicate: (bool) ($p['is_duplicate'] ?? false),
                isMocked: (bool) ($p['is_mocked'] ?? false),
                fromCamera: (bool) ($p['from_camera'] ?? true),
            ), $a['photos'] ?? []),
            history: $a['history'] ?? [],
        );
    }

    public function progressChanged(): bool
    {
        if ($this->last === null) {
            return $this->update->physicalPct > 0;
        }

        return $this->update->physicalPct != $this->last->physicalPct
            || $this->update->stageItemId !== $this->last->stageItemId;
    }
}
