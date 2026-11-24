<?php

namespace App\Livewire\Progress;

use App\Domain\Access\Permission;
use App\Domain\Progress\MissingPhotosException;
use App\Domain\Progress\PhotoService;
use App\Domain\Progress\ProgressInput;
use App\Domain\Progress\ProgressSubmissionService;
use App\Models\Work;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Web progress entry (docs/07 §4): same fields and rules as the app, pre-filled from the last
 * approved figures. Photos uploaded here are always flagged for the DPC (rule R12).
 */
#[Layout('components.layouts.app')]
class EntryForm extends Component
{
    use WithFileUploads;

    public Work $work; // jurisdiction-scoped route binding

    public ?int $stageItemId = null;

    public ?string $physicalPct = null;

    public ?string $spentRupees = null;

    public string $remarks = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    /** Photos already stored on an earlier (rejected) attempt, reused on resubmit. @var list<string> */
    public array $storedPhotoUuids = [];

    /** @var list<array{code:string, outcome:string, message:?string}> */
    public array $ruleResults = [];

    public function mount(): void
    {
        $this->authorize(Permission::SUBMIT_PROGRESS);
        $this->stageItemId = $this->work->current_stage_item_id;
        $this->physicalPct = rtrim(rtrim(number_format($this->work->physical_pct, 2, '.', ''), '0'), '.');
        $this->spentRupees = (string) intdiv($this->work->spent_total, 100);
    }

    public function rules(): array
    {
        $stageIds = $this->work->stageTemplate->items()->pluck('id')->all();

        return [
            'stageItemId' => ['required', 'integer', 'in:'.implode(',', $stageIds)],
            'physicalPct' => ['required', 'numeric', 'between:0,100'],
            'spentRupees' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'photos' => ['array', 'max:10'],
            'photos.*' => ['file', 'mimes:jpg,jpeg', 'max:4096'],
        ];
    }

    public function submit(PhotoService $photoService, ProgressSubmissionService $service)
    {
        $this->authorize(Permission::SUBMIT_PROGRESS);
        $this->validate();
        $this->ruleResults = [];
        $user = auth()->user();

        foreach ($this->photos as $file) {
            $this->storedPhotoUuids[] = $photoService->store($user, $this->work, $file, [
                'client_uuid' => (string) Str::uuid(),
                'source' => 'web',
            ])->client_uuid;
        }
        $this->photos = [];

        try {
            $result = $service->submit($user, new ProgressInput(
                clientUuid: (string) Str::uuid(),
                workId: $this->work->id,
                stageItemId: (int) $this->stageItemId,
                physicalPct: (float) $this->physicalPct,
                spentCumulative: (int) round((float) $this->spentRupees * 100),
                capturedAt: now(),
                channel: 'web',
                remarks: $this->remarks ?: null,
                photoUuids: $this->storedPhotoUuids,
            ));
        } catch (MissingPhotosException) {
            $this->storedPhotoUuids = [];
            $this->addError('photos', __('Some photos could not be found. Add them again.'));

            return null;
        }

        $this->ruleResults = $result->toArray()['rule_results'];

        if ($result->rejected()) {
            return null; // stays on the form with the reasons; stored photos are reused
        }

        session()->flash('status', $result->update->is_flagged
            ? __('Update sent to the DPC. It is flagged for review.')
            : __('Update sent to the DPC for approval.'));

        return $this->redirectRoute('works.show', $this->work, navigate: true);
    }

    public function render()
    {
        return view('livewire.progress.entry-form', [
            'stages' => $this->work->stageTemplate->items()->get(),
        ])->title(__('Record progress').' · '.$this->work->code);
    }
}
