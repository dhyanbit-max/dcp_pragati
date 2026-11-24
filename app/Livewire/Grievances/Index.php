<?php

namespace App\Livewire\Grievances;

use App\Models\District;
use App\Models\Grievance;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Grievance inbox for district and co-district officers (docs/20 §3). GrievanceScope limits it to their area. */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open'; // open, overdue, any single status, or all

    #[Url]
    public string $category = '';

    #[Url]
    public ?int $district = null;

    #[Url(as: 'q')]
    public string $search = '';

    public function updating($name): void
    {
        if (in_array($name, ['status', 'category', 'district', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $base = Grievance::query();
        $overdue = fn (Builder $q) => $q->open()->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', Grievance::SUBMITTED)->where('acknowledge_by', '<', now()))
            ->orWhere('resolve_by', '<', now()));

        $counts = [
            'open' => (clone $base)->open()->count(),
            'overdue' => $overdue(clone $base)->count(),
            'resolved30' => (clone $base)->where('status', Grievance::RESOLVED)->where('closed_at', '>=', now()->subDays(30))->count(),
            'avgDays' => (clone $base)->whereNotNull('closed_at')->where('closed_at', '>=', now()->subDays(90))
                ->toBase()->selectRaw('ROUND(AVG(EXTRACT(EPOCH FROM closed_at - created_at) / 86400)::numeric, 1) AS d')->value('d'),
        ];

        $q = (clone $base)->with(['district', 'coDistrict', 'assignee:id,name'])
            ->when($this->status === 'open', fn ($q) => $q->open())
            ->when($this->status === 'overdue', $overdue)
            ->when(in_array($this->status, array_keys(Grievance::TRANSITIONS), true), fn ($q) => $q->where('status', $this->status))
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when($this->district, fn ($q) => $q->where('district_id', $this->district))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
                $q->where(fn ($q) => $q->where('ref_no', 'ilike', $term)->orWhere('subject', 'ilike', $term)->orWhere('work_reference', 'ilike', $term));
            })
            // Most urgent first: overdue, then nearest deadline; closed ones newest first.
            ->orderByRaw("CASE WHEN status IN ('submitted','acknowledged','in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status IN ('submitted','acknowledged','in_progress') THEN resolve_by END ASC NULLS LAST")
            ->orderByDesc('created_at');

        return view('livewire.grievances.index', [
            'grievances' => $q->paginate(25),
            'counts' => $counts,
            'districts' => District::whereIn('id', Grievance::query()->select('district_id'))->orderBy('name_en')->get(),
        ])->title(__('Grievances'));
    }
}
