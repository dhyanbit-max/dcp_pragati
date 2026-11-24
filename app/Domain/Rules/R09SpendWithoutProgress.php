<?php

namespace App\Domain\Rules;

final class R09SpendWithoutProgress implements Rule
{
    public function code(): string
    {
        return 'R09';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => ['updates' => 2]];
    }

    /** Flags when spend rose but physical % stayed flat across the last N steps (this update included). */
    public function evaluate(Context $ctx, array $params): ?Failure
    {
        $n = max(1, (int) ($params['updates'] ?? 2));
        $series = array_merge(
            [['physical_pct' => $ctx->update->physicalPct, 'spent_cumulative' => $ctx->update->spentCumulative]],
            $ctx->history,
        );
        if (count($series) < $n + 1) {
            return null;
        }
        for ($i = 0; $i < $n; $i++) {
            $newer = $series[$i];
            $older = $series[$i + 1];
            $spendUp = (int) $newer['spent_cumulative'] > (int) $older['spent_cumulative'];
            $pctFlat = (float) $newer['physical_pct'] <= (float) $older['physical_pct'];
            if (! ($spendUp && $pctFlat)) {
                return null;
            }
        }

        return new Failure('rules.R09', ['updates' => $n]);
    }
}
