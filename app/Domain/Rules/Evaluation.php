<?php

namespace App\Domain\Rules;

final class Evaluation
{
    /** @param list<RuleResultData> $results */
    public function __construct(public readonly array $results) {}

    public function blocked(): bool
    {
        return $this->any('block');
    }

    public function flagged(): bool
    {
        return $this->any('flag');
    }

    /** @return list<RuleResultData> */
    public function failures(): array
    {
        return array_values(array_filter($this->results, fn ($r) => $r->outcome !== 'pass'));
    }

    private function any(string $outcome): bool
    {
        foreach ($this->results as $r) {
            if ($r->outcome === $outcome) {
                return true;
            }
        }

        return false;
    }
}
