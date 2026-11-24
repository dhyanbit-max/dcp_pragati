<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Progress\ApprovalService;
use App\Domain\Progress\PhotoService;
use App\Domain\Progress\ProgressInput;
use App\Domain\Progress\ProgressSubmissionService;
use App\Models\CoDistrict;
use App\Models\Constituency;
use App\Models\District;
use App\Models\FinancialYear;
use App\Models\FundRelease;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\ProgressUpdate;
use App\Models\SchemeType;
use App\Models\StageTemplate;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A statewide demo set on top of DemoDataSeeder, so the dashboard map, reports and alerts have something to show.
 * NOT real data: districts carry real place names marked "(demo)" with DEMO codes, and their outlines are
 * Voronoi cells around a point in each place, clipped to the Assam outline. Real outlines and LGD codes come
 * from the Department (docs/18 D-1, D-8) and replace all of this.
 *
 * Schemes are Assam's real Decentralised Planning schemes (MLALADS, MPLADS, Untied Fund, Assam Darshan, Aspirational
 * District Programme, Special Development Package) with work types and amounts in their published ranges.
 * Work names, villages, sanction orders and progress figures are made up.
 *
 * Progress goes through the real submission and approval services, so every figure passed the rules engine.
 * Deterministic (fixed random seed). Idempotent: does nothing if it has already run.
 * Logins: DPC 90000011NN, JE 90000021NN (NN = district number below); OTP in the app log.
 */
class DemoStatewideSeeder extends Seeder
{
    /** [code, name, lat, lng, co-districts] — a point in each place; co-districts are named after nearby towns. */
    private const DISTRICTS = [
        ['DEMO11', 'Dhubri', 26.02, 89.98, ['Dhubri', 'Bilasipara']],
        ['DEMO12', 'Kokrajhar', 26.42, 90.27, ['Kokrajhar', 'Gossaigaon']],
        ['DEMO13', 'Goalpara', 26.12, 90.62, ['Goalpara', 'Lakhipur']],
        ['DEMO14', 'Barpeta', 26.33, 91.00, ['Barpeta', 'Sarupeta']],
        ['DEMO15', 'Darrang', 26.45, 92.03, ['Mangaldai', 'Dalgaon']],
        ['DEMO16', 'Sonitpur', 26.70, 92.80, ['Tezpur', 'Dhekiajuli', 'Biswanath']],
        ['DEMO17', 'Nagaon', 26.35, 92.68, ['Nagaon', 'Kaliabor']],
        ['DEMO18', 'Karbi Anglong', 25.95, 93.45, ['Diphu', 'Bokajan']],
        ['DEMO19', 'Golaghat', 26.52, 93.97, ['Golaghat', 'Bokakhat']],
        ['DEMO20', 'Lakhimpur', 27.23, 94.10, ['North Lakhimpur', 'Dhakuakhana']],
        ['DEMO21', 'Dibrugarh', 27.47, 94.91, ['Dibrugarh', 'Naharkatia']],
        ['DEMO22', 'Tinsukia', 27.49, 95.36, ['Tinsukia', 'Margherita']],
        ['DEMO23', 'Dima Hasao', 25.17, 93.02, ['Haflong', 'Maibang']],
        ['DEMO24', 'Cachar', 24.82, 92.80, ['Silchar', 'Lakhipur (Cachar)']],
    ];

    /** Points for the two original demo districts (Guwahati and near Jorhat), so their works stay inside their cells. */
    private const ORIGINAL = ['DEMO1' => [26.16, 91.76], 'DEMO2' => [26.76, 94.21]];

    /**
     * Assam's Decentralised Planning schemes as fund sources (T&D Department):
     * [code, name, weight, min ₹ lakh, max ₹ lakh, work kinds]. Amounts are per work and follow the public figures:
     * MLALADS ₹1 crore per MLA a year (₹1.5 crore from 2026-27), MPLADS ₹5 crore per MP a year,
     * Assam Darshan grants of ₹2.5 lakh (2019-20 onward) and ₹1.5 lakh per namghar (recent rounds).
     */
    private const FUNDS = [
        ['DEMO_MLALADS', 'MLA Local Area Development Scheme (MLALADS)', 34, 3, 25, ['road', 'culvert', 'school', 'hall', 'water']],
        ['DEMO_MPLADS', 'Members of Parliament Local Area Development Scheme (MPLADS)', 20, 5, 50, ['road', 'culvert', 'school', 'water', 'hall']],
        ['DEMO_UNTIED', 'Untied Fund', 14, 5, 30, ['road', 'water', 'hall', 'school']],
        ['DEMO_ASSAM_DARSHAN', 'Assam Darshan (religious places)', 16, 1.5, 2.5, ['namghar']],
        ['DEMO_ADP', 'Aspirational District Programme', 8, 20, 100, ['school', 'water', 'hall']],
        ['DEMO_SDP', 'Special Development Package (2023 Memorandum of Settlement)', 8, 150, 900, ['bridge', 'road', 'sports']],
    ];

    /** Aspirational districts among the demo set: only these get Aspirational District Programme works. */
    private const ASPIRATIONAL = ['DEMO11', 'DEMO13', 'DEMO14', 'DEMO15'];

    /** Kind of work → [scheme type code, type name, stage template, work names]. */
    private const KINDS = [
        'road' => ['DEMO_ROAD', 'Road', 'DEMO_ROAD', ['Improvement of :village road with PMB (:km km)', 'Construction of RCC road at :village', 'Gravelling of :village to :village2 road (:km km)']],
        'culvert' => ['DEMO_CULVERT', 'Culvert and bridge', 'DEMO_ROAD', ['Construction of RCC slab culvert on :village road', 'Construction of RCC box culvert at :village']],
        'bridge' => ['DEMO_CULVERT', 'Culvert and bridge', 'DEMO_ROAD', ['Construction of RCC bridge over nullah at :village', 'Construction of RCC bridge on :place to :village road']],
        'school' => ['DEMO_SCHOOL', 'School building', 'DEMO_BUILDING', ['Additional classroom at :village LP School', 'Boundary wall at :village High School', 'Girls\' toilet block at :village ME School']],
        'water' => ['DEMO_WATER', 'Drinking water', 'DEMO_BUILDING', ['Deep tube well with overhead tank at :village', 'Extension of piped water supply at :village']],
        'hall' => ['DEMO_HALL', 'Community building', 'DEMO_BUILDING', ['Construction of community hall at :village', 'Construction of Anganwadi centre at :village', 'Construction of waiting shed at :village Tiniali']],
        'namghar' => ['DEMO_NAMGHAR', 'Namghar and religious place', 'DEMO_BUILDING', ['Renovation of :village Namghar', 'Development of :village Sarbajanin Namghar']],
        'sports' => ['DEMO_SPORTS', 'Sports infrastructure', 'DEMO_BUILDING', ['Construction of mini stadium at :place', 'Construction of indoor sports hall at :place']],
    ];

    /** Common village name forms across Assam, for variety. Not specific real villages. */
    private const VILLAGES = ['Borgaon', 'Natun Gaon', 'Kachari Gaon', 'Bamun Gaon', 'Gohain Gaon', 'Ahom Gaon', 'Kumar Gaon', 'Kathalguri',
        'Barbari', 'Kaliabari', 'Bor Ali', 'Rangagora', 'Dhekiagaon', 'Bhogpur', 'Chakalaghat', 'Kalakuchi', 'Pub Bongaon', 'Uttar Kalakhowa'];

    /** Weighted scenarios: what the work's history looks like today. */
    private const SCENARIOS = ['healthy' => 30, 'none' => 13, 'stale' => 15, 'stalled' => 10, 'delayed' => 10, 'completed' => 8, 'spend_ahead' => 7, 'pending' => 7];

    public function run(PhotoService $photos, ProgressSubmissionService $submit, ApprovalService $approvals): void
    {
        if (District::where('lgd_code', self::DISTRICTS[0][0])->exists()) {
            $this->command?->info('Statewide demo data already present.');

            return;
        }
        mt_srand(20261122);
        DB::transaction(fn () => $this->seed($photos, $submit, $approvals));
    }

    private function seed(PhotoService $photos, ProgressSubmissionService $submit, ApprovalService $approvals): void
    {
        [$fys, $funds, $schemes, $templates, $agencies] = $this->masters();
        $districts = $this->districtsAndUsers();
        $this->outlines($districts);

        $n = 0;
        foreach ($districts as $d) {
            $count = mt_rand(5, 8);
            for ($i = 1; $i <= $count; $i++) {
                $n++;
                $co = $d['co'][array_rand($d['co'])];
                [$fundCode, , , $minLakh, $maxLakh, $kinds] = $this->pickFund($d['district']->lgd_code);
                $kind = $kinds[mt_rand(0, count($kinds) - 1)];
                [$schemeCode, , $templateCode, $names] = self::KINDS[$kind];
                $agencyCode = $this->agencyFor($kind, $fundCode);
                $scenario = $this->pick();
                $sanctionedOn = now()->subDays(mt_rand(150, 420))->startOfDay();
                $sanctionedLakh = $fundCode === 'DEMO_ASSAM_DARSHAN'
                    ? ($sanctionedOn->lt('2025-04-01') ? 2.5 : 1.5)
                    : $this->roundLakh($minLakh + (mt_rand(0, 1000) / 1000) ** 2 * ($maxLakh - $minLakh)); // most works are small
                $target = match ($scenario) {
                    'delayed' => now()->subDays(mt_rand(5, 60)),
                    'completed' => $sanctionedOn->copy()->addDays(mt_rand(120, 400)),
                    default => now()->addDays(mt_rand(30, 300)),
                };
                $place = $d['co_names'][$co->id];
                $villages = array_rand(array_flip(self::VILLAGES), 2);
                $name = str_replace(
                    [':village2', ':village', ':place', ':km'],
                    [$villages[1], $villages[0], $place, number_format(mt_rand(5, 40) / 10, 1)],
                    $names[mt_rand(0, count($names) - 1)]
                );

                $work = Work::create([
                    'code' => sprintf('DEMO-%s-%03d', $d['district']->lgd_code, $i),
                    'name_en' => $name,
                    'scheme_type_id' => $schemes[$schemeCode]->id,
                    'fund_source_id' => $funds[$fundCode]->id,
                    'financial_year_id' => ($sanctionedOn->lt('2026-04-01') ? $fys['2025-26'] : $fys['2026-27'])->id,
                    'implementing_agency_id' => $agencies[$agencyCode]->id,
                    'district_id' => $d['district']->id,
                    'co_district_id' => $co->id,
                    'constituency_id' => $d['ac']->id,
                    'stage_template_id' => $templates[$templateCode]->id,
                    'sanctioned_amount' => (int) round($sanctionedLakh * 1_00_000 * 100),
                    'sanction_order_no' => sprintf('DEMO/SO/%s/%03d', $d['district']->lgd_code, $i),
                    'sanction_date' => $sanctionedOn->toDateString(),
                    'target_completion_date' => $target->toDateString(),
                    'current_stage_item_id' => $templates[$templateCode]->items->first()->id,
                    'assigned_field_user_id' => $d['je']->id,
                ]);
                // A random point inside the co-district (kept off its edge); its point on surface if it is tiny or missing.
                $site = DB::selectOne("SELECT ST_Y(p) lat, ST_X(p) lng FROM (
                    SELECT COALESCE(ST_GeometryN(ST_GeneratePoints(inner_geom, 1, ?), 1), ST_PointOnSurface(boundary)) p
                    FROM (SELECT boundary, CASE WHEN ST_IsEmpty(ST_Buffer(boundary, -0.01)) THEN boundary ELSE ST_Buffer(boundary, -0.01) END inner_geom
                          FROM co_districts WHERE id = ? AND boundary IS NOT NULL AND NOT ST_IsEmpty(boundary)) b
                ) s", [$n, $co->id]);
                [$lat, $lng] = $site ? [(float) $site->lat, (float) $site->lng] : [$d['lat'] + mt_rand(-50, 50) / 1000, $d['lng'] + mt_rand(-50, 50) / 1000];
                $work->setSite($lat, $lng);
                WorkAssignment::firstOrCreate(['work_id' => $work->id, 'user_id' => $d['je']->id, 'role' => 'field']);

                $this->history($work->fresh(), $scenario, $sanctionedOn, $d, $photos, $submit, $approvals);
            }
        }

        $this->command?->info('Statewide demo: '.count($districts)." districts, $n works.");
    }

    private function pickFund(string $districtCode): array
    {
        $funds = array_values(array_filter(self::FUNDS, fn ($f) => $f[0] !== 'DEMO_ADP' || in_array($districtCode, self::ASPIRATIONAL, true)));
        $r = mt_rand(1, array_sum(array_column($funds, 2)));
        foreach ($funds as $f) {
            if (($r -= $f[2]) <= 0) {
                return $f;
            }
        }

        return $funds[0];
    }

    /** Who executes: large road and bridge works go to PWD, water to PHE, large buildings to PWD (Building), the rest to the DCP cell or block. */
    private function agencyFor(string $kind, string $fund): string
    {
        return match (true) {
            in_array($kind, ['road', 'bridge'], true) && in_array($fund, ['DEMO_SDP', 'DEMO_ADP', 'DEMO_MPLADS'], true) => 'DEMO_PWDR',
            $kind === 'water' => 'DEMO_PHED',
            in_array($kind, ['sports', 'school'], true) && in_array($fund, ['DEMO_SDP', 'DEMO_ADP'], true) => 'DEMO_PWDB',
            $kind === 'namghar' => 'DEMO_DCP',
            default => mt_rand(0, 1) ? 'DEMO_DCP' : 'DEMO_BDO',
        };
    }

    /** Sanction amounts look like real ones: ₹0.5 lakh steps under ₹10 lakh, whole lakhs up to ₹1 crore, ₹5 lakh steps above. */
    private function roundLakh(float $lakh): float
    {
        return match (true) {
            $lakh < 10 => round($lakh * 2) / 2,
            $lakh < 100 => round($lakh),
            default => round($lakh / 5) * 5,
        };
    }

    private function pick(): string
    {
        $r = mt_rand(1, array_sum(self::SCENARIOS));
        foreach (self::SCENARIOS as $name => $weight) {
            if (($r -= $weight) <= 0) {
                return $name;
            }
        }

        return 'healthy';
    }

    /** Releases first (spend may never pass them, R01), then 2–4 updates through submission and approval. */
    private function history(Work $work, string $scenario, Carbon $sanctionedOn, array $d, PhotoService $photos, ProgressSubmissionService $submit, ApprovalService $approvals): void
    {
        $sanctioned = $work->sanctioned_amount;
        $releaseShare = match ($scenario) { 'completed' => 1.0, 'spend_ahead' => 0.8, 'none' => 0.4, default => 0.7 };
        FundRelease::create(['work_id' => $work->id, 'amount' => (int) ($sanctioned * 0.4), 'released_on' => $sanctionedOn->copy()->addDays(20)->toDateString(), 'order_no' => "DEMO/REL/{$work->code}/1"]);
        if ($releaseShare > 0.4) {
            FundRelease::create(['work_id' => $work->id, 'amount' => (int) ($sanctioned * ($releaseShare - 0.4)), 'released_on' => $sanctionedOn->copy()->addDays(90)->toDateString(), 'order_no' => "DEMO/REL/{$work->code}/2"]);
        }
        if ($scenario === 'none') {
            return;
        }

        // [final physical %, days since last update]
        [$finalPct, $lastDaysAgo] = match ($scenario) {
            'healthy' => [mt_rand(20, 85), mt_rand(1, 6)],
            'stale' => [mt_rand(15, 70), mt_rand(10, 45)],
            'stalled' => [mt_rand(10, 50), mt_rand(65, 110)],
            'delayed' => [mt_rand(35, 80), mt_rand(2, 20)],
            'completed' => [100, mt_rand(3, 40)],
            'spend_ahead' => [mt_rand(10, 25), mt_rand(2, 12)],
            'pending' => [mt_rand(20, 70), mt_rand(1, 3)],
        };
        $firstDaysAgo = (int) min((int) $sanctionedOn->diffInDays(now()) - 25, $lastDaysAgo + mt_rand(60, 140));
        $steps = mt_rand(2, 4);
        $released = (int) FundRelease::withoutGlobalScopes()->where('work_id', $work->id)->sum('amount'); // exact, after rounding

        $spent = 0;
        $prevPct = 0;
        for ($k = 1; $k <= $steps; $k++) {
            $last = $k === $steps;
            // Always up (R02), and never flat while spend rises (R09).
            $pct = $last ? $finalPct : min($finalPct - ($steps - $k), max($prevPct + 1, (int) round($finalPct * $k / $steps) - mt_rand(0, 3)));
            $prevPct = $pct;
            $daysAgo = (int) round($firstDaysAgo - ($firstDaysAgo - $lastDaysAgo) * ($k - 1) / max(1, $steps - 1));
            $share = $scenario === 'spend_ahead' && $last ? 0.65 : $pct / 100 * (mt_rand(85, 102) / 100);
            $spent = (int) min($released, max($spent + 1_000_00, $share * $sanctioned)); // R01, and never down
            $farPhoto = $scenario === 'pending' && $last && mt_rand(0, 1) === 1; // some pending updates are flagged

            $update = $this->submit($work, $pct, $spent, Carbon::now()->subDays($daysAgo)->setTime(mt_rand(9, 16), mt_rand(0, 59)), $farPhoto, $d['je'], $photos, $submit);
            $approve = ! ($scenario === 'pending' && $last);
            if ($approve && $update->status === ProgressUpdate::STATUS_PENDING) {
                $approvals->approve($update, $d['dpc'], $update->is_flagged ? 'Checked on site (demo)' : null);
            }
            if ($update->status === ProgressUpdate::STATUS_REJECTED) {
                $this->command?->warn("Demo update for {$work->code} was rejected by the rules; history stops there.");

                return;
            }
        }
    }

    private function submit(Work $work, float $pct, int $spent, Carbon $at, bool $far, User $field, PhotoService $photos, ProgressSubmissionService $submit): ProgressUpdate
    {
        $seq = match (true) { $pct <= 30 => 2, $pct <= 60 => 3, $pct < 96 => 4, default => 5 };
        $site = DB::selectOne('SELECT ST_Y(site) lat, ST_X(site) lng FROM works WHERE id = ?', [$work->id]);
        $uuids = [];
        foreach ([0, 1] as $k) {
            $uuids[] = $photos->store($field, $work, $this->demoJpeg($work->code, $at, $k), [
                'client_uuid' => (string) Str::uuid(),
                'lat' => $site->lat + ($far && $k === 0 ? 0.012 : 0.0002 * ($k + 1)),
                'lng' => $site->lng + 0.0002,
                'accuracy_m' => 5 + mt_rand(0, 8),
                'taken_at' => $at->copy()->subMinutes(12 - $k)->toIso8601String(),
            ])->client_uuid;
        }

        return $submit->submit($field, new ProgressInput(
            clientUuid: (string) Str::uuid(),
            workId: $work->id,
            stageItemId: $work->stageTemplate->items->firstWhere('seq', $seq)->id,
            physicalPct: $pct,
            spentCumulative: $spent,
            capturedAt: $at,
            remarks: mt_rand(0, 3) === 0 ? ['Work progressing as planned.', 'Material delivered at site.', 'Slow due to rain.', 'Labour shortage this week.'][mt_rand(0, 3)] : null,
            photoUuids: $uuids,
            formSeconds: mt_rand(60, 240),
        ))->update;
    }

    /** @return array{0: array<string, FinancialYear>, 1: array<string, FundSource>, 2: array<string, SchemeType>, 3: array<string, StageTemplate>, 4: array<string, ImplementingAgency>} */
    private function masters(): array
    {
        $fys = [
            '2025-26' => FinancialYear::firstOrCreate(['code' => '2025-26'], ['starts_on' => '2025-04-01', 'ends_on' => '2026-03-31']),
            '2026-27' => FinancialYear::firstOrCreate(['code' => '2026-27'], ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']),
        ];
        $funds = [];
        foreach (self::FUNDS as [$code, $name]) {
            $funds[$code] = FundSource::firstOrCreate(['code' => $code], ['name_en' => $name]);
        }
        $agencies = [];
        foreach ([
            'DEMO_DCP' => 'District Decentralised Planning Cell', 'DEMO_BDO' => 'Block Development Office (Panchayat & RD)',
            'DEMO_PWDR' => 'PWD (Roads) division', 'DEMO_PWDB' => 'PWD (Building) division', 'DEMO_PHED' => 'Public Health Engineering division',
        ] as $code => $name) {
            $agencies[$code] = ImplementingAgency::firstOrCreate(['code' => $code], ['name_en' => $name]);
        }

        $templates = ['DEMO_ROAD' => StageTemplate::where('code', 'DEMO_ROAD')->firstOrFail()];
        $building = StageTemplate::firstOrCreate(['code' => 'DEMO_BUILDING'], ['name_en' => 'Building works']);
        if ($building->items()->doesntExist()) {
            foreach ([
                [1, 'Not started', 'আৰম্ভ হোৱা নাই', 'শুরু হয়নি', 0, 0],
                [2, 'Foundation', 'ভেটি', 'ভিত্তি', 0, 30],
                [3, 'Superstructure', 'ওপৰৰ গাঁথনি', 'উপরিকাঠামো', 25, 60],
                [4, 'Finishing', 'ফিনিচিং', 'সমাপ্তির কাজ', 55, 95],
                [5, 'Completed', 'সম্পূৰ্ণ', 'সম্পূর্ণ', 95, 100],
            ] as [$seq, $en, $as, $bn, $min, $max]) {
                $building->items()->create(compact('seq') + ['name_en' => $en, 'name_as' => $as, 'name_bn' => $bn, 'min_pct' => $min, 'max_pct' => $max]);
            }
        }
        $templates['DEMO_BUILDING'] = $building;
        foreach ($templates as $t) {
            $t->load('items');
        }

        $schemes = [];
        foreach (self::KINDS as [$code, $name]) {
            $schemes[$code] ??= SchemeType::firstOrCreate(['code' => $code], ['name_en' => $name]);
        }

        return [$fys, $funds, $schemes, $templates, $agencies];
    }

    /** @return list<array{district: District, co: list<CoDistrict>, co_names: array<int, string>, ac: Constituency, dpc: User, je: User}> */
    private function districtsAndUsers(): array
    {
        $out = [];
        foreach (self::DISTRICTS as $i => [$code, $name, , , $coNames]) {
            $nn = str_pad((string) ($i + 11), 2, '0', STR_PAD_LEFT);
            $district = District::create(['lgd_code' => $code, 'name_en' => "$name (demo)", 'is_pilot' => true]);
            $co = [];
            $coLabels = [];
            foreach ($coNames as $j => $coName) {
                $c = CoDistrict::create(['lgd_code' => $code.'C'.($j + 1), 'district_id' => $district->id, 'name_en' => "$coName (demo)", 'is_pilot' => true]);
                $co[] = $c;
                $coLabels[$c->id] = $coName;
            }
            $ac = Constituency::create(['ac_no' => 900 + $i + 11, 'district_id' => $district->id, 'name_en' => "Demo constituency $name"]);

            $dpc = User::firstOrCreate(['mobile' => "90000011$nn"], ['name' => "DPC $name (demo)", 'password' => 'password']);
            $dpc->syncRoles([Role::DPC]);
            $dpc->jurisdictions()->firstOrCreate(['level' => 'district', 'district_id' => $district->id]);
            $je = User::firstOrCreate(['mobile' => "90000021$nn"], ['name' => "JE $name (demo)", 'password' => 'password']);
            $je->syncRoles([Role::FIELD_USER]);

            $out[] = ['lat' => self::DISTRICTS[$i][2], 'lng' => self::DISTRICTS[$i][3], 'district' => $district, 'co' => $co, 'co_names' => $coLabels, 'ac' => $ac, 'dpc' => $dpc, 'je' => $je];
        }

        return $out;
    }

    /**
     * Demo outlines that tile Assam: Voronoi cells around one point per district, clipped to the state outline;
     * co-districts split each district the same way. Replaces the earlier hull outlines of all DEMO areas.
     */
    private function outlines(array $districts): void
    {
        if (! DB::table('states')->where('lgd_code', '18')->whereNotNull('boundary')->exists()) {
            $this->command?->warn('No Assam outline loaded: demo district outlines skipped.');

            return;
        }

        $points = [];
        foreach (self::ORIGINAL as $code => [$lat, $lng]) {
            $points[] = [DB::table('districts')->where('lgd_code', $code)->value('id'), $lat, $lng];
        }
        foreach ($districts as $i => $d) {
            $points[] = [$d['district']->id, self::DISTRICTS[$i][2], self::DISTRICTS[$i][3]];
        }
        $points = array_values(array_filter($points, fn ($p) => $p[0] !== null));
        $this->voronoi('districts', $points, "(SELECT boundary FROM states WHERE lgd_code = '18')");

        // Co-districts: points around the district's point, split inside the district outline.
        $coPoints = [];
        $demoA = DB::table('districts')->where('lgd_code', 'DEMO1')->value('id');
        if ($demoA) {
            $a2 = CoDistrict::firstOrCreate(['lgd_code' => 'DEMO1C2'], ['district_id' => $demoA, 'name_en' => 'Demo Co-district A2', 'is_pilot' => true]);
            $coPoints[$demoA] = [[DB::table('co_districts')->where('lgd_code', 'DEMO1C')->value('id'), 26.17, 91.77], [$a2->id, 25.95, 91.40]];
        }
        foreach ($districts as $i => $d) {
            [, , $lat, $lng] = self::DISTRICTS[$i];
            $offsets = [[0.0, 0.0], [-0.1, 0.14], [0.1, -0.14]];
            $coPoints[$d['district']->id] = array_map(fn ($c, $j) => [$c->id, $lat + $offsets[$j][0], $lng + $offsets[$j][1]], $d['co'], array_keys($d['co']));
        }
        foreach ($coPoints as $districtId => $pts) {
            $this->voronoi('co_districts', $pts, "(SELECT boundary FROM districts WHERE id = $districtId)");
        }

        DB::statement("UPDATE constituencies c SET boundary = d.boundary FROM districts d
            WHERE c.district_id = d.id AND d.lgd_code LIKE 'DEMO%' AND c.ac_no > 910");
    }

    /** @param list<array{0:int,1:float,2:float}> $points [id, lat, lng] */
    private function voronoi(string $table, array $points, string $clipSql): void
    {
        $values = implode(',', array_map(fn ($p) => sprintf('(%d, ST_SetSRID(ST_MakePoint(%F, %F), 4326))', $p[0], $p[2], $p[1]), $points));
        DB::statement("
            WITH pts(id, g) AS (VALUES $values),
            clip AS (SELECT $clipSql AS geom),
            cells AS (
                SELECT (ST_Dump(ST_VoronoiPolygons(ST_Collect(pts.g), 0, ST_Expand(ST_Envelope(clip.geom), 1)))).geom AS cell
                FROM pts, clip GROUP BY clip.geom
            )
            UPDATE $table t
            SET boundary = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Intersection(cells.cell, clip.geom)), 3)), updated_at = NOW()
            FROM pts, cells, clip
            WHERE t.id = pts.id AND ST_Contains(cells.cell, pts.g)
        ");
    }

    private function demoJpeg(string $code, Carbon $at, int $k): UploadedFile
    {
        $img = imagecreatetruecolor(640, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, mt_rand(80, 150), mt_rand(100, 160), mt_rand(70, 120)));
        $white = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 20, 20, 'DEMO PHOTO '.($k + 1), $white);
        imagestring($img, 4, 20, 440, $code.'  '.$at->format('Y-m-d H:i'), $white);
        $path = tempnam(sys_get_temp_dir(), 'dcp').'.jpg';
        imagejpeg($img, $path, 70);
        imagedestroy($img);

        return new UploadedFile($path, basename($path), 'image/jpeg', null, true);
    }
}
