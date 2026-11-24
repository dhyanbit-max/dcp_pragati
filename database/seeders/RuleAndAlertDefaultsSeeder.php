<?php

namespace Database\Seeders;

use App\Domain\Rules\Rules;
use App\Models\AlertPolicy;
use App\Models\EscalationPolicy;
use App\Models\RuleDefinition;
use Illuminate\Database\Seeder;

/**
 * State-wide default rule definitions and alert policies.
 * Values are the [Assumption] defaults in docs/18 §4; confirm them in the week-2 workshop.
 * Re-running does not overwrite values an admin has changed.
 */
class RuleAndAlertDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $overrides = [
            'R03' => ['radius_m' => 200],
        ];

        foreach (Rules::all() as $rule) {
            $d = $rule->defaults();
            RuleDefinition::firstOrCreate(
                ['code' => $rule->code(), 'scope_district_id' => null],
                ['severity' => $d['severity'], 'params_json' => ($overrides[$rule->code()] ?? []) + $d['params'], 'active' => true],
            );
        }

        $alerts = [
            'A01' => [['reminder_days' => 7, 'alert_days' => 10], ['in_app', 'sms', 'whatsapp']],
            'A02' => [['grace_days' => 7], ['in_app', 'sms', 'whatsapp']],
            'A03' => [['release_age_days' => 60, 'utilisation_below_pct' => 50], ['in_app', 'email']],
            'A04' => [[], ['in_app', 'email']],
            'A05' => [['pending_days' => 3], ['in_app', 'email']],
        ];
        foreach ($alerts as $code => [$params, $channels]) {
            AlertPolicy::firstOrCreate(['code' => $code], ['params_json' => $params, 'channels' => $channels, 'active' => true]);

            foreach ([[1, 3 * 24, 'dpc'], [2, 10 * 24, 'district_collector'], [3, 24 * 24, 'state_admin']] as [$level, $hours, $role]) {
                EscalationPolicy::firstOrCreate(['alert_code' => $code, 'level' => $level], ['after_hours' => $hours, 'role' => $role]);
            }
        }
    }
}
