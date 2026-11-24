<?php

namespace App\Livewire\Approvals;

use App\Domain\Access\Permission;
use App\Domain\Progress\ApprovalService;
use App\Models\ProgressUpdate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** DPC approval queue (docs/07 §5). Flagged updates are reviewed one by one with a note; clean ones can be bulk-approved. */
#[Layout('components.layouts.app')]
class Queue extends Component
{
    #[Url]
    public string $tab = 'flagged';

    public ?int $reviewing = null;

    public string $note = '';

    /** @var list<int> */
    public array $selected = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize(Permission::APPROVE_PROGRESS);
        if ($this->tab === 'flagged' && ! $this->baseQuery(true)->exists() && $this->baseQuery(false)->exists()) {
            $this->tab = 'clean';
        }
    }

    public function updatedTab(): void
    {
        $this->reset('reviewing', 'note', 'selected');
    }

    public function review(int $id): void
    {
        $this->reviewing = $id;
        $this->note = '';
        $this->resetErrorBag();
    }

    public function closeReview(): void
    {
        $this->reset('reviewing', 'note');
    }

    public function approve(ApprovalService $approvals): void
    {
        $update = $this->current();
        if ($update->is_flagged) {
            $this->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => __('Add a note to approve a flagged update.')]);
        }
        $this->act(fn () => $approvals->approve($update, auth()->user(), $this->note ?: null), __('Approved.'));
    }

    public function returnUpdate(ApprovalService $approvals): void
    {
        $this->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => __('Tell the submitter what to fix.')]);
        $update = $this->current();
        $this->act(fn () => $approvals->returnToSubmitter($update, auth()->user(), $this->note), __('Returned to the submitter.'));
    }

    public function reject(ApprovalService $approvals): void
    {
        $this->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => __('Give a reason for rejecting.')]);
        $update = $this->current();
        $this->act(fn () => $approvals->reject($update, auth()->user(), $this->note), __('Rejected.'));
    }

    public function bulkApprove(ApprovalService $approvals): void
    {
        $this->authorize(Permission::APPROVE_PROGRESS);
        $n = $approvals->bulkApprove(array_map('intval', $this->selected), auth()->user());
        $this->selected = [];
        $this->flash = trans_choice('{0} Nothing approved.|{1} 1 update approved.|[2,*] :count updates approved.', $n, ['count' => $n]);
    }

    public function render()
    {
        $items = $this->baseQuery($this->tab === 'flagged')
            ->with(['work:id,code,name_en,name_as,physical_pct,spent_total,current_stage_item_id,site_radius_m',
                'work.currentStage', 'submitter:id,name', 'stageItem', 'photos',
                'ruleResults' => fn ($q) => $q->where('outcome', '!=', 'pass')])
            ->oldest('received_at')
            ->limit(100)
            ->get();

        return view('livewire.approvals.queue', [
            'items' => $items,
            'counts' => ['flagged' => $this->baseQuery(true)->count(), 'clean' => $this->baseQuery(false)->count()],
            'current' => $this->reviewing ? $items->firstWhere('id', $this->reviewing) : null,
        ])->title(__('Approvals'));
    }

    private function baseQuery(bool $flagged)
    {
        return ProgressUpdate::query()->pending()->where('is_flagged', $flagged);
    }

    private function current(): ProgressUpdate
    {
        $this->authorize(Permission::APPROVE_PROGRESS);

        return ProgressUpdate::query()->pending()->findOrFail($this->reviewing); // jurisdiction-scoped
    }

    private function act(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->flash = $message;
            $this->reset('reviewing', 'note');
        } catch (InvalidArgumentException $e) {
            $this->addError('note', $e->getMessage());
        }
    }
}
