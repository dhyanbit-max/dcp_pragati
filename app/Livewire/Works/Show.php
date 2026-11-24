<?php

namespace App\Livewire\Works;

use App\Domain\Access\Permission;
use App\Domain\Monitoring\WorkStatus;
use App\Models\Alert;
use App\Models\FundRelease;
use App\Models\ProgressUpdate;
use App\Models\Work;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Unified progress record (docs/07 §3): one timeline per work, physical and financial together. */
#[Layout('components.layouts.app')]
class Show extends Component
{
    public Work $work; // route binding applies JurisdictionScope, so out-of-scope works 404

    public bool $showReleaseForm = false;

    public ?string $releaseRupees = null;

    public ?string $releasedOn = null;

    public string $releaseOrderNo = '';

    public function openReleaseForm(): void
    {
        $this->authorize(Permission::RECORD_RELEASE);
        $this->showReleaseForm = true;
        $this->releasedOn ??= now()->toDateString();
    }

    public function recordRelease(): void
    {
        $this->authorize(Permission::RECORD_RELEASE);
        $work = Work::query()->findOrFail($this->work->id); // re-check scope, fresh totals
        $remaining = $work->sanctioned_amount - FundRelease::query()->where('work_id', $work->id)->sum('amount');

        $this->validate([
            'releaseRupees' => ['required', 'numeric', 'gt:0', 'max:'.($remaining / 100)],
            'releasedOn' => ['required', 'date', 'before_or_equal:today'],
            'releaseOrderNo' => ['nullable', 'string', 'max:100'],
        ], [
            'releaseRupees.max' => __('Releases cannot exceed the sanctioned amount. At most :amount can still be released.', ['amount' => Money::inr($remaining)]),
        ]);

        FundRelease::create([
            'work_id' => $work->id,
            'amount' => (int) round((float) $this->releaseRupees * 100),
            'released_on' => $this->releasedOn,
            'order_no' => $this->releaseOrderNo ?: null,
            'source' => 'manual',
            'entered_by' => auth()->id(),
        ]);

        $this->reset('showReleaseForm', 'releaseRupees', 'releasedOn', 'releaseOrderNo');
        session()->flash('status', __('Release recorded.'));
    }

    public function render(WorkStatus $statuses)
    {
        $work = $statuses->withFlags(Work::query()->withSiteCoords()->whereKey($this->work->id))
            ->with(['district', 'coDistrict', 'constituency', 'schemeType', 'fundSource', 'financialYear', 'agency',
                'currentStage', 'assignedFieldUser:id,name', 'stages.item', 'releases' => fn ($q) => $q->orderBy('released_on')])
            ->firstOrFail();

        $updates = ProgressUpdate::query()->where('work_id', $work->id)
            ->with(['submitter:id,name', 'reviewer:id,name', 'stageItem', 'photos', 'ruleResults' => fn ($q) => $q->where('outcome', '!=', 'pass')])
            ->orderByDesc('captured_at')->orderByDesc('id')->get();

        $alerts = Alert::query()->where('work_id', $work->id)->orderByDesc('opened_at')->get();

        return view('livewire.works.show', [
            'w' => $work,
            'series' => $this->series($work, $updates),
            'timeline' => $this->timeline($work, $updates, $alerts),
            'photos' => $updates->whereIn('status', [ProgressUpdate::STATUS_ACCEPTED, ProgressUpdate::STATUS_PENDING])->flatMap->photos,
            'mismatchThreshold' => $statuses->mismatchThreshold(),
            // Grievances linked to this work, for officers who handle them (GrievanceScope applies).
            'grievances' => config('dcp.public.grievances') && auth()->user()->can(\App\Domain\Access\Permission::HANDLE_GRIEVANCES)
                ? \App\Models\Grievance::where('work_id', $work->id)->latest()->get() : collect(),
        ])->title($work->code);
    }

    /** Physical % and financial % at each accepted update, starting from sanction at 0 / 0. */
    private function series(Work $work, Collection $updates): array
    {
        $points = [];
        $start = $work->sanction_date ?? $work->created_at;
        $points[] = ['t' => $start->getTimestamp(), 'date' => $start->format('d M Y'), 'physical' => 0.0, 'financial' => 0.0];

        foreach ($updates->where('status', ProgressUpdate::STATUS_ACCEPTED)->sortBy('captured_at') as $u) {
            $points[] = [
                't' => $u->captured_at->getTimestamp(),
                'date' => $u->captured_at->format('d M Y'),
                'physical' => round($u->physical_pct, 1),
                'financial' => $work->sanctioned_amount > 0 ? round($u->spent_cumulative * 100 / $work->sanctioned_amount, 1) : 0.0,
            ];
        }

        return $points;
    }

    private function timeline(Work $work, Collection $updates, Collection $alerts): Collection
    {
        $events = collect();
        if ($work->sanction_date) {
            $events->push(['at' => $work->sanction_date, 'type' => 'sanction', 'model' => $work]);
        }
        foreach ($work->releases as $r) {
            $events->push(['at' => $r->released_on, 'type' => 'release', 'model' => $r]);
        }
        foreach ($updates as $u) {
            $events->push(['at' => $u->captured_at, 'type' => 'update', 'model' => $u]);
        }
        foreach ($alerts as $a) {
            $events->push(['at' => $a->opened_at, 'type' => 'alert', 'model' => $a]);
        }

        return $events->sortByDesc(fn ($e) => $e['at']->getTimestamp())->values();
    }
}
