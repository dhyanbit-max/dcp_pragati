<?php

namespace App\Domain\Reports;

use App\Support\Money;

/** A report column. Types: text, int, decimal, money (paise), pct, date. */
final class Column
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $total = false,
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->type, ['int', 'decimal', 'money', 'pct'], true);
    }

    /** Display value for screen and PDF. Excel gets raw numbers (rupees for money). */
    public function format(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return match ($this->type) {
            'money' => Money::inr((int) $v),
            'pct' => number_format((float) $v, 1).'%',
            'int' => number_format((int) $v),
            'decimal' => number_format((float) $v, 1),
            default => (string) $v,
        };
    }

    public function excelValue(mixed $v): mixed
    {
        return match (true) {
            $v === null => null,
            $this->type === 'money' => round(((int) $v) / 100, 2),
            in_array($this->type, ['pct', 'int', 'decimal'], true) => (float) $v,
            default => (string) $v,
        };
    }
}
