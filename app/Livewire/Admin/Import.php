<?php

namespace App\Livewire\Admin;

use App\Domain\Access\Permission;
use App\Domain\Import\ImportReport;
use App\Domain\Import\WorkbookImporter;
use App\Domain\Import\WorkbookReader;
use App\Models\SyncBatch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/** Workbook import with a mandatory preview (docs/11 mode B/C). */
#[Layout('components.layouts.app')]
class Import extends Component
{
    use WithFileUploads;

    public $file = null;

    /** Stored copy of the previewed file, so Import uses exactly what was previewed. */
    public ?string $storedPath = null;

    public ?string $fileName = null;

    public ?array $preview = null;

    public ?array $result = null;

    public function mount(): void
    {
        $this->authorize(Permission::MANAGE_MASTERS);
    }

    public function updatedFile(): void
    {
        $this->reset('preview', 'result', 'storedPath', 'fileName');
    }

    public function runPreview(WorkbookReader $reader, WorkbookImporter $importer): void
    {
        $this->authorize(Permission::MANAGE_MASTERS);
        $this->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,csv']]);

        $this->fileName = $this->file->getClientOriginalName();
        $this->storedPath = $this->file->storeAs('imports', Str::uuid().'.'.strtolower($this->file->getClientOriginalExtension()), 'local');
        $this->file = null;

        $this->preview = $this->execute($reader, $importer, commit: false)?->toArray();
    }

    public function import(WorkbookReader $reader, WorkbookImporter $importer): void
    {
        $this->authorize(Permission::MANAGE_MASTERS);
        if (! $this->storedPath || ! $this->preview) {
            return;
        }
        $this->result = $this->execute($reader, $importer, commit: true)?->toArray();
        Storage::disk('local')->delete($this->storedPath);
        $this->reset('preview', 'storedPath');
    }

    public function startOver(): void
    {
        if ($this->storedPath) {
            Storage::disk('local')->delete($this->storedPath);
        }
        $this->reset('file', 'preview', 'result', 'storedPath', 'fileName');
    }

    private function execute(WorkbookReader $reader, WorkbookImporter $importer, bool $commit): ?ImportReport
    {
        try {
            $sheets = $reader->read(Storage::disk('local')->path($this->storedPath), $this->fileName);
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return null;
        }

        return $importer->run($sheets, auth()->user(), $commit, $this->fileName);
    }

    public function render()
    {
        return view('livewire.admin.import', [
            'history' => SyncBatch::with('starter:id,name')->where('entity', 'workbook')
                ->when(! auth()->user()->hasStateScope(), fn ($q) => $q->where('started_by', auth()->id()))
                ->latest('id')->limit(10)->get(),
        ])->title(__('Import data'));
    }
}
