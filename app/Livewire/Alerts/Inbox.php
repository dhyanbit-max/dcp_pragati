<?php

namespace App\Livewire\Alerts;

use App\Domain\Access\Permission;
use App\Domain\Alerts\AlertActions;
use App\Domain\Alerts\AlertCode;
use App\Models\Alert;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Alert inbox (docs/08): open, acknowledged and recently closed alerts in the viewer's jurisdiction. */
#[Layout('components.layouts.app')]
class Inbox extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'open';

    #[Url]
    public string $code = '';

    public ?int $acting = null;

    public string $action = '';

    public string $note = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize(Permission::HANDLE_ALERTS);
    }

    public function updating($name): void
    {
        if (in_array($name, ['tab', 'code'], true)) {
            $this->resetPage();
            $this->reset('acting', 'action', 'note');
        }
    }

    public function start(int $id, string $action): void
    {
        $this->acting = $id;
        $this->action = in_array($action, ['acknowledge', 'resolve'], true) ? $action : 'acknowledge';
        $this->note = '';
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('acting', 'action', 'note');
    }

    public function confirm(AlertActions $actions): void
    {
        $this->authorize(Permission::HANDLE_ALERTS);
        $this->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => __('Add a short note about what is being done.')]);
        $alert = Alert::query()->findOrFail($this->acting); // jurisdiction-scoped

        try {
            $this->action === 'resolve'
                ? $actions->resolve($alert, auth()->user(), $this->note)
                : $actions->acknowledge($alert, auth()->user(), $this->note);
        } catch (InvalidArgumentException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->flash = $this->action === 'resolve' ? __('Alert resolved.') : __('Alert acknowledged. Escalation is paused for :d days.', ['d' => config('dcp.alerts.ack_pause_days')]);
        $this->reset('acting', 'action', 'note');
    }

    public function render()
    {
        $statuses = match ($this->tab) {
            'acknowledged' => [Alert::STATUS_ACKNOWLEDGED],
            'closed' => [Alert::STATUS_RESOLVED, Alert::STATUS_AUTO_CLOSED],
            default => [Alert::STATUS_OPEN],
        };

        $alerts = Alert::query()
            ->with(['work:id,code,name_en,name_as,district_id', 'work.district:id,name_en,name_as,name_bn'])
            ->whereIn('status', $statuses)
            ->when($this->tab === 'closed', fn ($q) => $q->where('resolved_at', '>', now()->subDays(30)))
            ->when(in_array($this->code, AlertCode::ALL, true), fn ($q) => $q->where('alert_code', $this->code))
            ->orderByDesc('level')->orderBy('opened_at')
            ->paginate(25);

        $counts = Alert::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.alerts.inbox', [
            'alerts' => $alerts,
            'counts' => [
                'open' => (int) ($counts[Alert::STATUS_OPEN] ?? 0),
                'acknowledged' => (int) ($counts[Alert::STATUS_ACKNOWLEDGED] ?? 0),
            ],
        ])->title(__('Alerts'));
    }
}
