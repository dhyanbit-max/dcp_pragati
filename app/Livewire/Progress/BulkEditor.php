<?php

namespace App\Livewire\Progress;

use App\Domain\Access\Permission;
use App\Domain\Progress\ProgressInput;
use App\Domain\Progress\ProgressSubmissionService;
use App\Models\District;
use App\Models\ProgressUpdate;
use App\Models\StageTemplateItem;
use App\Models\Work;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bulk edit (docs/07 §4): a grid of works in the user's jurisdiction with editable stage / % / spend.
 * Each changed row goes through the same submission service and rules engine as the app and the
 * web form, and creates one update with channel = bulk. Nothing here touches approved figures directly.
 */
#[Layout('components.layouts.app')]
class BulkEditor extends Component
{
    use WithPagination;

    /** Most rows saved in one go, so one request stays well inside the PHP time limit. */
    public const MAX_ROWS_PER_SAVE = 100;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $district = null;

    /** Edits keyed by work id: ['stage' => id, 'pct' => string, 'spent' => rupees string]. Kept across pages. */
    public array $rows = [];

    /** One client_uuid per row, kept until that row is saved, so a double click replays instead of duplicating. */
    public array $uuids = [];

    /** Outcome of the last save, keyed by work id: ['status' => ..., 'flagged' => bool, 'messages' => [...]]. */
    public array $results = [];

    public string $remarks = '';

    public function mount(): void
    {
        $this->authorize(Permission::BULK_EDIT);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'district'], true)) {
            $this->resetPage();
        }
    }

    /** Put the row back to the approved figures. */
    public function resetRow(int $workId): void
    {
        unset($this->rows[$workId], $this->uuids[$workId], $this->results[$workId]);
    }

    public function save(ProgressSubmissionService $service): void
    {
        $this->authorize(Permission::BULK_EDIT);
        $this->resetErrorBag();
        $user = auth()->user();

        // Jurisdiction-scoped: rows for works this user cannot see are dropped, never submitted.
        $works = Work::query()->whereIn('id', array_map('intval', array_keys($this->rows)))
            ->with('stageTemplate.items:id,stage_template_id')->get()->keyBy('id');
        $changed = $works->filter(fn (Work $w) => $this->isChanged($w, $this->rows[$w->id]));

        if ($changed->isEmpty()) {
            $this->addError('rows', __('Nothing has changed yet.'));

            return;
        }
        if ($changed->count() > self::MAX_ROWS_PER_SAVE) {
            $this->addError('rows', __('Save at most :max rows at a time.', ['max' => self::MAX_ROWS_PER_SAVE]));

            return;
        }

        $this->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        foreach ($changed as $work) {
            $row = $this->rows[$work->id];
            if ($error = $this->rowError($work, $row)) {
                $this->results[$work->id] = ['status' => 'invalid', 'flagged' => false, 'messages' => [$error]];

                continue;
            }

            $this->uuids[$work->id] ??= (string) Str::uuid();
            $result = $service->submit($user, new ProgressInput(
                clientUuid: $this->uuids[$work->id],
                workId: $work->id,
                stageItemId: (int) $row['stage'],
                physicalPct: (float) $row['pct'],
                spentCumulative: (int) round((float) $row['spent'] * 100),
                capturedAt: now(),
                channel: 'bulk',
                remarks: $this->remarks !== '' ? $this->remarks : null,
            ));

            $out = $result->toArray();
            $this->results[$work->id] = [
                'status' => $out['status'],
                'flagged' => $out['flagged'],
                'messages' => array_map(fn ($r) => $r['code'].' · '.$r['message'], $out['rule_results']),
            ];

            // A rejected row keeps the user's figures so they can be corrected; it needs a new uuid
            // because the rejected update is kept on record. A sent row goes back to the approved figures.
            unset($this->uuids[$work->id]);
            if (! $result->rejected()) {
                unset($this->rows[$work->id]);
            }
        }

        $counts = collect($this->results)->only($changed->keys()->all())->countBy(fn ($r) => match (true) {
            in_array($r['status'], [ProgressUpdate::STATUS_REJECTED, 'invalid'], true) => 'rejected',
            default => 'sent',
        });
        session()->flash('status', __(':sent sent for approval, :rejected not accepted.', [
            'sent' => $counts['sent'] ?? 0,
            'rejected' => $counts['rejected'] ?? 0,
        ]));
    }

    private function isChanged(Work $work, array $row): bool
    {
        return (int) ($row['stage'] ?? 0) !== (int) $work->current_stage_item_id
            || round((float) ($row['pct'] ?? -1), 2) !== round((float) $work->physical_pct, 2)
            || (int) round((float) ($row['spent'] ?? -1) * 100) !== (int) $work->spent_total;
    }

    /** Form-level checks; business rules are left to the rules engine. */
    private function rowError(Work $work, array $row): ?string
    {
        $stageIds = $work->stageTemplate?->items->pluck('id')->all() ?? [];
        if (! in_array((int) ($row['stage'] ?? 0), $stageIds, true)) {
            return __('Choose a stage.');
        }
        if (! is_numeric($row['pct'] ?? null) || $row['pct'] < 0 || $row['pct'] > 100) {
            return __('Physical progress must be between 0 and 100.');
        }
        if (! is_numeric($row['spent'] ?? null) || $row['spent'] < 0) {
            return __('Total spent must be zero or more.');
        }

        return null;
    }

    public static function pctString(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
    }

    public function render()
    {
        $works = Work::query()
            ->whereNotIn('works.status', [Work::STATUS_COMPLETED, Work::STATUS_CANCELLED])
            ->when($this->district, fn ($q, $v) => $q->where('works.district_id', $v))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
                $q->where(fn ($q) => $q->where('works.code', 'ilike', $term)
                    ->orWhere('works.name_en', 'ilike', $term)
                    ->orWhere('works.name_as', 'ilike', $term)
                    ->orWhere('works.erupantor_id', 'ilike', $term));
            })
            ->withCount(['progressUpdates as pending_count' => fn ($q) => $q->where('status', ProgressUpdate::STATUS_PENDING)])
            ->with(['district:id,name_en,name_as,name_bn', 'currentStage'])
            ->orderBy('works.code')
            ->paginate(25);

        // Rows the user has not touched show the approved figures.
        foreach ($works as $w) {
            $this->rows[$w->id] ??= [
                'stage' => $w->current_stage_item_id,
                'pct' => self::pctString((float) $w->physical_pct),
                'spent' => (string) intdiv($w->spent_total, 100),
            ];
        }

        $changedIds = Work::query()->whereIn('id', array_map('intval', array_keys($this->rows)))
            ->get(['id', 'current_stage_item_id', 'physical_pct', 'spent_total'])
            ->filter(fn (Work $w) => $this->isChanged($w, $this->rows[$w->id]))->pluck('id')->all();

        return view('livewire.progress.bulk-editor', [
            'works' => $works,
            'stagesByTemplate' => StageTemplateItem::whereIn('stage_template_id', $works->pluck('stage_template_id')->unique())
                ->orderBy('seq')->get()->groupBy('stage_template_id'),
            'districts' => District::whereIn('id', Work::query()->select('district_id'))->orderBy('name_en')->get(),
            'changedIds' => $changedIds,
        ])->title(__('Bulk edit'));
    }
}
