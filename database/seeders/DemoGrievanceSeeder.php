<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Grievances\GrievanceService;
use App\Models\Grievance;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo grievances in every state, filed and handled through GrievanceService (timeline, audit, SMS to the log).
 * Citizen mobiles are 90000099NN demo numbers; no real person's data. Idempotent.
 */
class DemoGrievanceSeeder extends Seeder
{
    /** [category, subject, description, scheme types it fits (null: any work)] */
    private const CASES = [
        ['quality', 'Road surface broken within two months', 'The new road surface has cracked and the edges are breaking near the school gate. Rain water collects in the potholes and two-wheelers are slipping.', ['DEMO_ROAD']],
        ['delay', 'Culvert work stopped for weeks', 'Earth was dug and left open more than a month ago. Nobody has come back to finish the culvert and the road is blocked for tractors.', ['DEMO_CULVERT']],
        ['not_started', 'Signboard put up but no work started', 'The signboard for this work was put up in the summer. No material or workers have come since then.', null],
        ['safety', 'Open pit next to the road without barricade', 'A deep pit for the culvert is open next to the road with no barricade or light. Children walk this way to school in the dark.', ['DEMO_CULVERT', 'DEMO_ROAD']],
        ['incomplete', 'Classroom left without doors and windows', 'The building looks finished from outside but doors, windows and electric fittings are missing, so it cannot be used.', ['DEMO_SCHOOL']],
        ['quality', 'Cement plaster coming off the community hall walls', 'Large pieces of plaster are falling from the inside walls of the newly built hall. The mix looks weak.', ['DEMO_HALL']],
        ['wrong_location', 'Tube well dug away from the village', 'The deep tube well was dug far from the houses, near a private plot, not at the place agreed in the gaon sabha.', ['DEMO_WATER']],
        ['delay', 'Namghar renovation half done', 'The old roof was removed for renovation but the new roof is not complete. Prayers are being held under a tarpaulin.', ['DEMO_NAMGHAR']],
        ['other', 'Request to share the estimate for the road work', 'We want to know the length and the sanctioned amount of the road work in our village. The signboard is not readable.', ['DEMO_ROAD']],
        ['quality', 'Water tank leaking from the base', 'The overhead tank built under the water supply work is leaking from the base and the platform is getting damp.', ['DEMO_WATER']],
    ];

    public function run(GrievanceService $service): void
    {
        if (Grievance::withoutGlobalScopes()->exists()) {
            $this->command?->info('Grievances already present.');

            return;
        }
        mt_srand(4242);

        $districts = DB::table('districts')->orderBy('id')->pluck('id');
        $n = 0;
        foreach ($districts as $i => $districtId) {
            $officer = User::role(Role::DPC)->whereHas('jurisdictions', fn ($j) => $j->where('district_id', $districtId))->first();
            $works = Work::withoutGlobalScopes()->with('schemeType:id,code')->where('district_id', $districtId)->orderBy('code')->get()->shuffle(mt_rand());
            foreach ($works->take(mt_rand(1, 3)) as $work) {
                $n++;
                // A complaint that fits the kind of work (a leaking tank only for water supply works).
                $fits = array_values(array_filter(self::CASES, fn ($c) => $c[3] === null || in_array($work->schemeType?->code, $c[3], true)));
                [$category, $subject, $description] = $fits[mt_rand(0, count($fits) - 1)];
                $daysAgo = mt_rand(0, 40);
                Carbon::setTestNow(now()->subDays($daysAgo)->setTime(mt_rand(8, 20), mt_rand(0, 59)));
                $g = $service->submit([
                    'district_id' => $districtId,
                    'co_district_id' => $work->co_district_id,
                    'category' => $category,
                    'subject' => $subject,
                    'description' => $description,
                    'work_reference' => mt_rand(0, 1) ? $work->name_en : null,
                    'location_text' => null,
                    'lat' => null,
                    'lng' => null,
                    'citizen_name' => mt_rand(0, 2) ? null : 'Demo citizen '.$n,
                    'citizen_mobile' => sprintf('90000099%02d', $n % 100),
                    'citizen_locale' => ['en', 'as', 'bn'][mt_rand(0, 2)],
                ]);

                // How far the office got: older grievances are further along; a few are left late on purpose.
                if ($officer && $daysAgo > 2 && mt_rand(0, 5) > 0) {
                    Carbon::setTestNow(now()->addDays(mt_rand(1, 2)));
                    $service->linkWork($g, $officer, $work);
                    $service->transition($g, $officer, Grievance::ACKNOWLEDGED);
                    if ($daysAgo > 6) {
                        Carbon::setTestNow(now()->addDays(mt_rand(1, 3)));
                        $service->transition($g, $officer, Grievance::IN_PROGRESS, 'Junior engineer asked to inspect the site.');
                        $service->note($g, $officer, 'Site visit planned this week.', true);
                    }
                    if ($daysAgo > 12 && mt_rand(0, 3) > 0) {
                        Carbon::setTestNow(now()->addDays(mt_rand(2, 6)));
                        mt_rand(0, 4) > 0
                            ? $service->transition($g, $officer, Grievance::RESOLVED, 'Site inspected by the JE. The contractor has repaired the defect and the work was checked again.')
                            : $service->transition($g, $officer, Grievance::REJECTED, 'This work is executed by another department. Forwarded to the concerned division.');
                    }
                }
                Carbon::setTestNow();
            }
        }

        $this->command?->info("Demo grievances: $n.");
    }
}
