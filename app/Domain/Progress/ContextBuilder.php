<?php

namespace App\Domain\Progress;

use App\Domain\Rules\Context;
use App\Domain\Rules\LastAccepted;
use App\Domain\Rules\PhotoFacts;
use App\Domain\Rules\UpdateFacts;
use App\Domain\Rules\WorkFacts;
use App\Models\ProgressPhoto;
use App\Models\ProgressUpdate;
use App\Models\StageTemplateItem;
use App\Models\Work;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Builds a rules Context from the database for an incoming update. */
class ContextBuilder
{
    /** @param Collection<int, ProgressPhoto> $photos */
    public function build(Work $work, ProgressInput $input, Collection $photos, CarbonImmutable $receivedAt): Context
    {
        $stage = StageTemplateItem::findOrFail($input->stageItemId);

        $accepted = ProgressUpdate::withoutGlobalScopes()
            ->where('work_id', $work->id)
            ->accepted()
            ->orderByDesc('captured_at')->orderByDesc('id')
            ->limit(5)
            ->get(['stage_item_id', 'physical_pct', 'spent_cumulative', 'captured_at']);

        $last = $accepted->first();

        $duplicateHashes = ProgressPhoto::withoutGlobalScopes()
            ->whereIn('sha256', $photos->pluck('sha256'))
            ->whereNotIn('id', $photos->pluck('id'))
            ->pluck('sha256')
            ->flip();

        return new Context(
            work: new WorkFacts(
                sanctioned: $work->sanctioned_amount,
                released: $work->released_total,
                hasSite: $work->hasSite(),
                siteRadiusM: $work->site_radius_m,
            ),
            update: new UpdateFacts(
                stageItemId: $stage->id,
                stageMinPct: $stage->min_pct,
                stageMaxPct: $stage->max_pct,
                physicalPct: $input->physicalPct,
                spentCumulative: $input->spentCumulative,
                capturedAt: CarbonImmutable::instance($input->capturedAt),
                receivedAt: $receivedAt,
                deviceAccuracyM: $input->deviceAccuracyM,
            ),
            last: $last ? new LastAccepted(
                stageItemId: $last->stage_item_id,
                physicalPct: $last->physical_pct,
                spentCumulative: $last->spent_cumulative,
                capturedAt: CarbonImmutable::instance($last->captured_at),
            ) : null,
            photos: $photos->values()->map(fn (ProgressPhoto $p) => new PhotoFacts(
                hasLocation: $p->lat !== null && $p->lng !== null,
                distanceM: $p->distance_from_site_m,
                accuracyM: $p->accuracy_m,
                takenAt: $p->taken_at ? CarbonImmutable::instance($p->taken_at) : null,
                isDuplicate: $duplicateHashes->has($p->sha256),
                isMocked: $p->is_mocked,
                fromCamera: $p->source === 'app',
            ))->all(),
            history: $accepted->map(fn ($u) => [
                'physical_pct' => $u->physical_pct,
                'spent_cumulative' => $u->spent_cumulative,
            ])->all(),
        );
    }
}
