<?php

use App\Domain\Rules\Context;
use App\Domain\Rules\RuleEngine;
use App\Domain\Rules\Rules;

/**
 * Runs the shared rule fixtures (tests/fixtures/rules/rules.json) against the PHP rules.
 * The field app's Dart mirror must pass the same file.
 */
function ruleFixtures(): array
{
    $data = json_decode(file_get_contents(__DIR__.'/../fixtures/rules/rules.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];
    foreach ($data['cases'] as $case) {
        $ctx = $data['base'];
        foreach ($case['patch'] ?? [] as $key => $value) {
            $ctx[$key] = (is_array($value) && array_is_list($value)) || $value === null || ! is_array($ctx[$key] ?? null)
                ? $value
                : array_merge($ctx[$key], $value);
        }
        $cases[$case['name']] = [$case['rule'], $case['params'] ?? [], $ctx, $case['expect']];
    }

    return $cases;
}

it('evaluates the shared fixture', function (string $code, array $params, array $ctx, string $expect) {
    $context = Context::fromArray($ctx);

    if ($code === '*') {
        $definitions = [];
        $evaluation = (new RuleEngine)->evaluate($context, null, $definitions);
        $failed = array_map(fn ($r) => $r->code, $evaluation->failures());
        expect($failed)->toBe([]);

        return;
    }

    $rule = collect(Rules::all())->first(fn ($r) => $r->code() === $code);
    $failure = $rule->evaluate($context, array_merge($rule->defaults()['params'], $params));

    expect($failure === null ? 'pass' : 'fail')->toBe($expect);
})->with(ruleFixtures());

it('applies severity from definitions and district overrides', function () {
    $data = json_decode(file_get_contents(__DIR__.'/../fixtures/rules/rules.json'), true);
    $ctx = $data['base'];
    $ctx['update']['spent_cumulative'] = 6_000_000; // breaks R01 and R07
    $context = Context::fromArray($ctx);

    $definitions = [
        'R01' => [0 => ['severity' => 'block', 'params' => []], 7 => ['severity' => 'flag', 'params' => []]],
        'R07' => [0 => ['severity' => 'off', 'params' => []]],
    ];
    $engine = new RuleEngine;

    $state = $engine->evaluate($context, null, $definitions);
    expect($state->blocked())->toBeTrue()
        ->and(collect($state->results)->pluck('code'))->not->toContain('R07');

    $district7 = $engine->evaluate($context, 7, $definitions);
    expect($district7->blocked())->toBeFalse()->and($district7->flagged())->toBeTrue();
});
