<?php

namespace App\Livewire\Reports;

use App\Domain\Access\Permission;
use App\Domain\Reports\AqAnswer;
use App\Models\AqRequest;
use App\Models\Constituency;
use App\Models\FinancialYear;
use App\Models\FundSource;
use App\Models\SchemeType;
use App\Models\Work;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Assembly Question desk (docs/09 §3). */
#[Layout('components.layouts.app')]
class AqDesk extends Component
{
    public string $questionNo = '';

    public string $session = '';

    public string $memberName = '';

    public string $questionText = '';

    /** @var list<int> */
    public array $constituencies = [];

    public ?int $schemeType = null;

    public ?int $fundSource = null;

    public ?int $fy = null;

    public string $note = '';

    #[Url(as: 'answer')]
    public ?int $answerId = null;

    public function mount(): void
    {
        $this->authorize(Permission::AQ_DESK);
    }

    public function prepare(AqAnswer $aq): void
    {
        $this->authorize(Permission::AQ_DESK);
        $this->validate([
            'questionNo' => ['required', 'string', 'max:20'],
            'session' => ['nullable', 'string', 'max:40'],
            'memberName' => ['nullable', 'string', 'max:255'],
            'questionText' => ['nullable', 'string', 'max:5000'],
            'constituencies' => ['array'],
            'constituencies.*' => ['integer', 'exists:constituencies,id'],
            'note' => ['nullable', 'string', 'max:5000'],
        ], ['questionNo.required' => __('Enter the question number.')]);

        $saved = $aq->answer(
            ['question_no' => $this->questionNo, 'session' => $this->session ?: null, 'member_name' => $this->memberName ?: null, 'question_text' => $this->questionText ?: null],
            ['constituency' => array_map('intval', $this->constituencies), 'scheme_type' => $this->schemeType, 'fund_source' => $this->fundSource, 'fy' => $this->fy],
            auth()->user(),
            $this->note ?: null,
        );
        $this->answerId = $saved->id;
    }

    public function startNew(): void
    {
        $this->reset();
    }

    public function render(AqAnswer $aq)
    {
        $loc = app()->getLocale();
        $answer = $this->answerId ? AqRequest::where('answered_by', auth()->id())->find($this->answerId) : null;

        return view('livewire.reports.aq-desk', [
            'answer' => $answer,
            'result' => $answer ? $aq->rebuild($answer, auth()->user()) : null,
            'history' => AqRequest::with('answerer:id,name')
                ->when(! auth()->user()->hasStateScope(), fn ($q) => $q->where('answered_by', auth()->id()))
                ->latest('id')->limit(10)->get(),
            'options' => [
                'constituency' => Constituency::whereIn('district_id', Work::query()->distinct()->select('district_id'))
                    ->orderBy('ac_no')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->ac_no} · {$c->name($loc)}"]),
                'scheme_type' => SchemeType::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
                'fund_source' => FundSource::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
                'fy' => FinancialYear::orderByDesc('code')->pluck('code', 'id'),
            ],
        ])->title(__('Assembly Question desk'));
    }
}
