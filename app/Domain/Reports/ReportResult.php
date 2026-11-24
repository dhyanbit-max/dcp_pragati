<?php

namespace App\Domain\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** A run report: what was asked, when, and the rows. Rendered on screen, to Excel or to PDF. */
final class ReportResult
{
    /**
     * @param  list<Column>  $columns
     * @param  list<string>  $filterSummary  human-readable "District: X" lines
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly array $columns,
        public readonly Collection $rows,
        public readonly ?array $totals,
        public readonly array $filterSummary,
        public readonly Carbon $asOf,
        public readonly string $generatedBy,
        public readonly ?string $note = null,
        public readonly ?string $heading = null, // e.g. AQ question number
        public readonly array $summary = [],     // label => formatted value, shown above the table
    ) {}

    public function fileName(string $ext): string
    {
        return sprintf('%s-%s.%s', strtolower($this->code), $this->asOf->format('Ymd-Hi'), $ext);
    }
}
