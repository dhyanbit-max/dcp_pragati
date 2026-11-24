<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Models\CoDistrict;
use App\Models\Constituency;
use App\Models\District;
use App\Models\FinancialYear;
use App\Models\FundRelease;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\SchemeType;
use App\Models\StageTemplate;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for local development and training. NOT real Assam data: codes are prefixed DEMO
 * and must be replaced by the Department's pilot data (docs/18 D-1, D-2).
 * Logins: mobiles 9000000001–9000000006; OTP appears in storage/logs/laravel.log.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $fy = FinancialYear::firstOrCreate(['code' => '2026-27'], ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $fund = FundSource::firstOrCreate(['code' => 'DEMO_MLA'], ['name_en' => 'Demo MLA fund']);
        FundSource::firstOrCreate(['code' => 'DEMO_MP'], ['name_en' => 'Demo MP fund']);
        $type = SchemeType::firstOrCreate(['code' => 'DEMO_ROAD'], ['name_en' => 'Road', 'name_as' => 'পথ', 'name_bn' => 'রাস্তা']);
        $agency = ImplementingAgency::firstOrCreate(['code' => 'DEMO_PWD'], ['name_en' => 'Demo PWD division']);

        $template = StageTemplate::firstOrCreate(['code' => 'DEMO_ROAD'], ['name_en' => 'Road works']);
        if ($template->items()->doesntExist()) {
            foreach ([
                [1, 'Not started', 'আৰম্ভ হোৱা নাই', 'শুরু হয়নি', 0, 0],
                [2, 'Earthwork', 'মাটিৰ কাম', 'মাটির কাজ', 0, 30],
                [3, 'Sub-base', 'চাব-বেছ', 'সাব-বেস', 25, 60],
                [4, 'Surfacing', 'পৃষ্ঠ নিৰ্মাণ', 'উপরিতল নির্মাণ', 55, 95],
                [5, 'Completed', 'সম্পূৰ্ণ', 'সম্পূর্ণ', 95, 100],
            ] as [$seq, $en, $as, $bn, $min, $max]) {
                $template->items()->create(compact('seq') + ['name_en' => $en, 'name_as' => $as, 'name_bn' => $bn, 'min_pct' => $min, 'max_pct' => $max]);
            }
        }

        $d1 = District::firstOrCreate(['lgd_code' => 'DEMO1'], ['name_en' => 'Demo District A', 'is_pilot' => true]);
        $d2 = District::firstOrCreate(['lgd_code' => 'DEMO2'], ['name_en' => 'Demo District B', 'is_pilot' => true]);
        $c1 = CoDistrict::firstOrCreate(['lgd_code' => 'DEMO1C'], ['district_id' => $d1->id, 'name_en' => 'Demo Co-district A1', 'is_pilot' => true]);

        $ac1 = Constituency::firstOrCreate(['ac_no' => 901], ['district_id' => $d1->id, 'name_en' => 'Demo constituency 901']);
        $ac2 = Constituency::firstOrCreate(['ac_no' => 902], ['district_id' => $d1->id, 'name_en' => 'Demo constituency 902']);
        $ac3 = Constituency::firstOrCreate(['ac_no' => 903], ['district_id' => $d2->id, 'name_en' => 'Demo constituency 903']);

        $users = [
            ['9000000001', 'State Admin (demo)', Role::STATE_ADMIN, null],
            ['9000000002', 'DPC District A (demo)', Role::DPC, ['level' => 'district', 'district_id' => $d1->id]],
            ['9000000003', 'DPC District B (demo)', Role::DPC, ['level' => 'district', 'district_id' => $d2->id]],
            ['9000000004', 'Co-district officer A1 (demo)', Role::CO_DISTRICT_OFFICER, ['level' => 'co_district', 'co_district_id' => $c1->id]],
            ['9000000005', 'JE District A (demo)', Role::FIELD_USER, null],
            ['9000000006', 'JE District B (demo)', Role::FIELD_USER, null],
        ];
        $byMobile = [];
        foreach ($users as [$mobile, $name, $role, $jurisdiction]) {
            $u = User::firstOrCreate(['mobile' => $mobile], ['name' => $name, 'password' => 'password']);
            $u->syncRoles([$role]);
            if ($jurisdiction) {
                $u->jurisdictions()->firstOrCreate($jurisdiction);
            }
            $byMobile[$mobile] = $u;
        }

        $sites = [
            // [district, co-district, field user mobile, lat, lng, constituency]
            [$d1, $c1, '9000000005', 26.1445, 91.7362, $ac1],
            [$d1, null, '9000000005', 26.1800, 91.7500, $ac1],
            [$d1, $c1, '9000000005', 26.2000, 91.8000, $ac2],
            [$d2, null, '9000000006', 26.7500, 94.2000, $ac3],
            [$d2, null, '9000000006', 26.7600, 94.2200, $ac3],
        ];
        foreach ($sites as $i => [$district, $co, $mobile, $lat, $lng, $ac]) {
            $code = sprintf('DEMO-%s-%03d', $district->lgd_code, $i + 1);
            if (Work::withoutGlobalScopes()->where('code', $code)->exists()) {
                continue;
            }
            $work = Work::create([
                'code' => $code,
                'name_en' => "Demo road work {$code}",
                'scheme_type_id' => $type->id,
                'fund_source_id' => $fund->id,
                'financial_year_id' => $fy->id,
                'implementing_agency_id' => $agency->id,
                'district_id' => $district->id,
                'co_district_id' => $co?->id,
                'constituency_id' => $ac->id,
                'stage_template_id' => $template->id,
                'sanctioned_amount' => 50_00_000_00, // ₹50 lakh in paise
                'sanction_date' => '2026-05-01',
                'target_completion_date' => '2027-02-28',
                'current_stage_item_id' => $template->items()->first()->id,
                'assigned_field_user_id' => $byMobile[$mobile]->id,
            ]);
            $work->setSite($lat, $lng);
            WorkAssignment::firstOrCreate(['work_id' => $work->id, 'user_id' => $byMobile[$mobile]->id, 'role' => 'field']);
            FundRelease::create(['work_id' => $work->id, 'amount' => 20_00_000_00, 'released_on' => '2026-06-01', 'order_no' => "DEMO/REL/{$i}"]);
        }

        // Unofficial Assam outline for the map highlight (dev/demo only; see database/geo/README.md).
        $outline = database_path('geo/assam-state-unofficial.geojson');
        if (is_file($outline) && DB::table('states')->where('lgd_code', '18')->whereNull('boundary')->exists()) {
            app(\App\Domain\Gis\BoundaryImporter::class)->import('state', $outline, 'lgd_code',
                'Unofficial: DataMeet India / ECI via geoBoundaries (CC BY 2.5 IN)');
        }

        // Demo boundaries: a buffered hull around each demo area's works. Not real geography;
        // real boundaries come from `php artisan geo:import-boundaries` (docs/10).
        foreach (['districts' => 'district_id', 'co_districts' => 'co_district_id', 'constituencies' => 'constituency_id'] as $table => $fk) {
            DB::statement("UPDATE $table t SET boundary = sub.geom FROM (
                SELECT $fk AS id, ST_Multi(ST_Buffer(ST_ConvexHull(ST_Collect(site)), 0.06, 'quad_segs=4')) AS geom
                FROM works WHERE $fk IS NOT NULL AND site IS NOT NULL AND code LIKE 'DEMO-%' GROUP BY $fk
            ) sub WHERE t.id = sub.id AND t.boundary IS NULL");
        }
    }
}
