<?php

namespace App\Livewire\Grievances;

use App\Domain\Access\Permission;
use App\Domain\Grievances\GrievanceService;
use App\Models\Grievance;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** One grievance: details, the citizen's contact (revealed on demand, logged), timeline and actions (docs/20 §3). */
#[Layout('components.layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $grievanceId;

    public bool $showContact = false;

    public string $note = '';

    public bool $notePublic = false;

    public string $closeNote = '';

    public ?string $closeAs = null; // resolved / rejected, while the close form is open

    public string $workCode = '';

    public ?int $assignTo = null;

    public ?string $flash = null;

    public function mount(Grievance $grievance): void
    {
        $this->authorize(Permission::HANDLE_GRIEVANCES);
        $this->grievanceId = $grievance->id; // route binding applied GrievanceScope
        $this->workCode = $grievance->work?->code ?? '';
        $this->assignTo = $grievance->assigned_to;
    }

    private function grievance(): Grievance
    {
        return Grievance::findOrFail($this->grievanceId); // scoped again on every action
    }

    public function revealContact(): void
    {
        $g = $this->grievance();
        activity()->performedOn($g)->causedBy(auth()->user())->log('viewed citizen contact');
        $this->showContact = true;
    }

    public function move(string $to, GrievanceService $service): void
    {
        $this->run(fn () => $service->transition($this->grievance(), auth()->user(), $to), __('Status updated. The citizen gets an SMS.'));
    }

    public function close(GrievanceService $service): void
    {
        $this->validate(['closeNote' => ['required', 'string', 'min:10', 'max:2000'], 'closeAs' => ['required', 'in:resolved,rejected']]);
        if ($this->run(fn () => $service->transition($this->grievance(), auth()->user(), $this->closeAs, $this->closeNote), __('Closed. The citizen gets an SMS with your note.'))) {
            $this->reset('closeNote', 'closeAs');
        }
    }

    public function addNote(GrievanceService $service): void
    {
        $this->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);
        if ($this->run(fn () => $service->note($this->grievance(), auth()->user(), $this->note, $this->notePublic), __('Note added.'))) {
            $this->reset('note', 'notePublic');
        }
    }

    public function assign(GrievanceService $service): void
    {
        $user = $this->officers($this->grievance())->firstWhere('id', $this->assignTo);
        if (! $user) {
            $this->addError('assignTo', __('Choose an officer.'));

            return;
        }
        $this->run(fn () => $service->assign($this->grievance(), auth()->user(), $user), __('Assigned to :n.', ['n' => $user->name]));
    }

    public function linkWork(GrievanceService $service): void
    {
        $g = $this->grievance();
        $code = trim($this->workCode);
        $work = $code === '' ? null : Work::where('code', $code)->where('district_id', $g->district_id)->first();
        if ($code !== '' && ! $work) {
            $this->addError('workCode', __('No work with this code in :d.', ['d' => $g->district->name(app()->getLocale())]));

            return;
        }
        $this->run(fn () => $service->linkWork($g, auth()->user(), $work), $work ? __('Linked to :c.', ['c' => $work->code]) : __('Work link removed.'));
    }

    private function run(callable $action, string $ok): bool
    {
        try {
            $action();
            $this->flash = $ok;
            $this->resetErrorBag();

            return true;
        } catch (InvalidArgumentException $e) {
            $this->addError('action', $e->getMessage());

            return false;
        }
    }

    /** Officers who may handle grievances in this grievance's district (and state admins). */
    private function officers(Grievance $g)
    {
        return User::permission(Permission::HANDLE_GRIEVANCES)->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereHas('roles', fn ($r) => $r->whereIn('name', \App\Domain\Access\Role::STATE_ROLES))
                ->orWhereHas('jurisdictions', fn ($j) => $j->where('district_id', $g->district_id)
                    ->orWhere(fn ($j) => $j->whereNotNull('co_district_id')->where('co_district_id', $g->co_district_id))))
            ->orderBy('name')->get(['id', 'name', 'designation']);
    }

    public function render()
    {
        $g = Grievance::with(['district', 'coDistrict', 'work', 'assignee:id,name', 'events.user:id,name'])->findOrFail($this->grievanceId);

        return view('livewire.grievances.show', [
            'g' => $g,
            'officers' => $this->officers($g),
            'works' => Work::where('district_id', $g->district_id)->orderBy('code')->limit(500)->get(['id', 'code', 'name_en']),
        ])->title($g->ref_no);
    }
}
