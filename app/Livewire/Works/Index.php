<?php

namespace App\Livewire\Works;

use App\Domain\Monitoring\WorkStatus;
use App\Models\District;
use App\Models\Work;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $district = null;

    #[Url]
    public string $status = '';

    #[Url]
    public string $flag = '';

    public function updating($name): void
    {
        if (in_array($name, ['search', 'district', 'status', 'flag'], true)) {
            $this->resetPage();
        }
    }

    public function render(WorkStatus $statuses)
    {
        $q = $statuses->withFlags($statuses->filtered(['district' => $this->district, 'status' => $this->status ?: null]))
            ->with(['district:id,name_en,name_as,name_bn', 'currentStage', 'agency:id,name_en,name_as,name_bn', 'latestAcceptedUpdate.submitter:id,name'])
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
                $q->where(fn ($q) => $q->where('works.code', 'ilike', $term)
                    ->orWhere('works.name_en', 'ilike', $term)
                    ->orWhere('works.name_as', 'ilike', $term)
                    ->orWhere('works.erupantor_id', 'ilike', $term));
            })
            ->when(in_array($this->flag, WorkStatus::FLAGS, true), fn ($q) => $statuses->whereFlag($q, $this->flag))
            ->orderBy('works.code');

        return view('livewire.works.index', [
            'works' => $q->paginate(25),
            'districts' => District::whereIn('id', Work::query()->select('district_id'))->orderBy('name_en')->get(),
            'statusOptions' => [Work::STATUS_NOT_STARTED, Work::STATUS_IN_PROGRESS, Work::STATUS_STALLED, Work::STATUS_COMPLETED, Work::STATUS_CANCELLED],
        ])->title(__('Works'));
    }
}
