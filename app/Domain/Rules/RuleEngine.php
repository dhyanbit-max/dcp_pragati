<?php

namespace App\Domain\Rules;

use App\Models\RuleDefinition;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every active rule against a Context. Severity and params come from rule_definitions:
 * district override, then the state-wide row, then the rule's defaults. See docs/05-rules-engine.md.
 */
class RuleEngine
{
    public const CACHE_KEY = 'rule_definitions.v1';

    /** @param list<Rule>|null $rules */
    public function __construct(private ?array $rules = null)
    {
        $this->rules ??= Rules::all();
    }

    public function evaluate(Context $ctx, ?int $districtId = null, ?array $definitions = null): Evaluation
    {
        $definitions ??= $this->definitions();
        $results = [];

        foreach ($this->rules as $rule) {
            $def = $definitions[$rule->code()][$districtId ?? 0]
                ?? $definitions[$rule->code()][0]
                ?? $rule->defaults();
            $severity = $def['severity'];
            if ($severity === 'off') {
                continue;
            }
            $params = array_merge($rule->defaults()['params'], $def['params'] ?? []);
            $failure = $rule->evaluate($ctx, $params);

            $results[] = new RuleResultData(
                code: $rule->code(),
                outcome: $failure ? $severity : 'pass',
                messageKey: $failure?->messageKey,
                details: $failure?->details ?? [],
            );
        }

        return new Evaluation($results);
    }

    /** @return array<string, array<int, array{severity: string, params: array}>> keyed by code, then district id (0 = state-wide) */
    public function definitions(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $out = [];
            foreach (RuleDefinition::query()->get() as $d) {
                $out[$d->code][$d->scope_district_id ?? 0] = [
                    'severity' => $d->active ? $d->severity : 'off',
                    'params' => $d->params_json ?? [],
                ];
            }

            return $out;
        });
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
