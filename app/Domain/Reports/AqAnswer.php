<?php

namespace App\Domain\Reports;

use App\Domain\Monitoring\WorkStatus;
use App\Models\AqRequest;
use App\Models\User;
use App\Models\Work;
use App\Support\Money;

/**
 * Assembly Question desk (docs/09 §3): answer by constituency, scheme type / fund source and year.
 * Summary figures first, then the list of works. Each answer is saved in aq_requests for reuse and audit.
 * Layout is provisional until the Department's AQ reply format is shared (docs/18 D-5).
 */
class AqAnswer
{
    public const PARAMS = ['constituency', 'district', 'scheme_type', 'fund_source', 'fy', 'status'];

    public function __construct(
        private readonly WorkStatus $status,
        private readonly ReportRunner $runner,
    ) {}

    public function build(array $question, array $params, User $user, ?string $note = null): ReportResult
    {
        $started = hrtime(true);
        $loc = app()->getLocale();
        $filters = array_intersect_key(array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== []), array_flip(self::PARAMS));

        $works = $this->status->filtered($filters)
            ->with(['constituency', 'schemeType', 'fundSource', 'financialYear', 'district'])
            ->orderBy('works.constituency_id')->orderBy('works.code')
            ->get();

        $rows = $works->map(fn (Work $w) => [
            'code' => $w->code,
            'name' => ($loc === 'as' && $w->name_as) ? $w->name_as : $w->name_en,
            'constituency' => $w->constituency ? "{$w->constituency->ac_no} {$w->constituency->name($loc)}" : '—',
            'scheme' => $w->schemeType->name($loc).' / '.$w->fundSource->name($loc),
            'fy' => $w->financialYear->code,
            'sanctioned' => $w->sanctioned_amount,
            'released' => $w->released_total,
            'spent' => $w->spent_total,
            'physical_pct' => $w->physical_pct,
            'status' => __(ucfirst(str_replace('_', ' ', $w->status))),
        ]);

        $columns = [
            new Column('code', __('Code')),
            new Column('name', __('Work')),
            new Column('constituency', __('Constituency')),
            new Column('scheme', __('Scheme / fund')),
            new Column('fy', __('Year')),
            new Column('sanctioned', __('Sanctioned'), 'money', true),
            new Column('released', __('Released'), 'money', true),
            new Column('spent', __('Spent'), 'money', true),
            new Column('physical_pct', __('Physical'), 'pct'),
            new Column('status', __('Status')),
        ];
        $totals = $rows->isEmpty() ? null : [
            'code' => __('Total'),
            'sanctioned' => $rows->sum('sanctioned'),
            'released' => $rows->sum('released'),
            'spent' => $rows->sum('spent'),
        ];

        $summary = [
            __('Number of works') => number_format($works->count()),
            __('Amount sanctioned') => Money::inr((int) $works->sum('sanctioned_amount')),
            __('Amount released') => Money::inr((int) $works->sum('released_total')),
            __('Amount spent') => Money::inr((int) $works->sum('spent_total')),
            __('Completed') => number_format($works->where('status', Work::STATUS_COMPLETED)->count()),
            __('In progress') => number_format($works->whereIn('status', [Work::STATUS_IN_PROGRESS, Work::STATUS_STALLED])->count()),
            __('Not started') => number_format($works->where('status', Work::STATUS_NOT_STARTED)->count()),
        ];

        $heading = trim(implode(' · ', array_filter([
            __('Assembly Question No. :no', ['no' => $question['question_no']]),
            $question['session'] ?? null,
            ! empty($question['member_name']) ? __('Asked by :name', ['name' => $question['member_name']]) : null,
        ])));

        $result = new ReportResult(
            code: 'AQ',
            title: __('Reply to Assembly Question'),
            columns: $columns,
            rows: $rows,
            totals: $totals,
            filterSummary: $this->runner->describe($filters),
            asOf: now(),
            generatedBy: $user->name,
            note: trim(implode("\n\n", array_filter([$question['question_text'] ?? null, $note]))) ?: null,
            heading: $heading,
            summary: $summary,
        );

        $this->lastDurationStart = $started;

        return $result;
    }

    private int $lastDurationStart = 0;

    /** Build the answer and save the question for reuse and audit. */
    public function answer(array $question, array $params, User $user, ?string $note = null): AqRequest
    {
        $result = $this->build($question, $params, $user, $note);
        $run = $this->runner->record('AQ', $params, $user, 'html', $this->lastDurationStart, 'aq');

        return AqRequest::create([
            'question_no' => $question['question_no'],
            'session' => $question['session'] ?? null,
            'member_name' => $question['member_name'] ?? null,
            'question_text' => $question['question_text'] ?? null,
            'params_json' => array_intersect_key($params, array_flip(self::PARAMS)) + ['note' => $note, 'works' => $result->rows->count()],
            'report_run_id' => $run->id,
            'answered_by' => $user->id,
            'answered_at' => now(),
        ]);
    }

    /** Rebuild a saved answer with current data (for display and export). */
    public function rebuild(AqRequest $aq, User $user): ReportResult
    {
        $p = $aq->params_json ?? [];

        return $this->build($aq->only(['question_no', 'session', 'member_name', 'question_text']), $p, $user, $p['note'] ?? null);
    }
}
