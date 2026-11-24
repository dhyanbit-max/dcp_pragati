<?php

namespace App\Domain\Reports;

use App\Domain\Monitoring\WorkStatus;
use Illuminate\Support\Collection;

/**
 * A standard report (docs/09). Subclasses return rows as arrays keyed by column key.
 * Work queries go through WorkStatus::filtered(), so the viewer's jurisdiction scope always applies.
 */
abstract class Report
{
    public function __construct(protected WorkStatus $status) {}

    abstract public function code(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** @return list<Column> */
    abstract public function columns(array $params): array;

    /** @return Collection<int, array<string, mixed>> */
    abstract public function rows(array $filters, array $params): Collection;

    /** Extra options beyond the common filters, e.g. ['group_by' => ['district' => 'District', ...]]. */
    public function options(): array
    {
        return [];
    }

    /** Default: sum every column marked `total`; percentage columns are recomputed by subclasses if needed. */
    public function totals(Collection $rows, array $params): ?array
    {
        $cols = array_filter($this->columns($params), fn (Column $c) => $c->total);
        if ($cols === [] || $rows->isEmpty()) {
            return null;
        }
        $t = [];
        foreach ($cols as $c) {
            $t[$c->key] = $rows->sum($c->key);
        }

        return $t;
    }

    protected static function pct(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }
}
