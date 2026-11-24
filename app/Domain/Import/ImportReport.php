<?php

namespace App\Domain\Import;

/** Outcome of an import or preview: counts per sheet plus row-level errors and warnings. */
final class ImportReport
{
    /** @var array<string, array{created:int, updated:int, skipped:int, failed:int}> */
    public array $counts = [];

    /** @var list<array{sheet:string, row:int, level:string, message:string}> */
    public array $issues = [];

    public function count(string $sheet, string $outcome): void
    {
        $this->counts[$sheet] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $this->counts[$sheet][$outcome]++;
    }

    public function error(string $sheet, int $row, string $message): void
    {
        $this->issues[] = ['sheet' => $sheet, 'row' => $row, 'level' => 'error', 'message' => $message];
    }

    public function warning(string $sheet, int $row, string $message): void
    {
        $this->issues[] = ['sheet' => $sheet, 'row' => $row, 'level' => 'warning', 'message' => $message];
    }

    public function failedRows(): int
    {
        return array_sum(array_column($this->counts, 'failed'));
    }

    public function changedRows(): int
    {
        return array_sum(array_map(fn ($c) => $c['created'] + $c['updated'], $this->counts));
    }

    public function toArray(): array
    {
        return ['counts' => $this->counts, 'issues' => $this->issues];
    }

    public static function fromArray(array $a): self
    {
        $r = new self;
        $r->counts = $a['counts'] ?? [];
        $r->issues = $a['issues'] ?? [];

        return $r;
    }
}
