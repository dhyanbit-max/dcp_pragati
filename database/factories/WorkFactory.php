<?php

namespace Database\Factories;

use App\Models\District;
use App\Models\FinancialYear;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\SchemeType;
use App\Models\StageTemplate;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Work> */
class WorkFactory extends Factory
{
    protected $model = Work::class;

    public function definition(): array
    {
        $template = self::template();

        return [
            'code' => 'T-'.fake()->unique()->numerify('######'),
            'name_en' => 'Test work '.fake()->words(3, true),
            'scheme_type_id' => SchemeType::firstOrCreate(['code' => 'T_ROAD'], ['name_en' => 'Road'])->id,
            'fund_source_id' => FundSource::firstOrCreate(['code' => 'T_MLA'], ['name_en' => 'MLA'])->id,
            'financial_year_id' => FinancialYear::firstOrCreate(['code' => '2026-27'], ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31'])->id,
            'implementing_agency_id' => ImplementingAgency::firstOrCreate(['code' => 'T_PWD'], ['name_en' => 'PWD'])->id,
            'district_id' => District::firstOrCreate(['lgd_code' => 'T1'], ['name_en' => 'Test District 1'])->id,
            'stage_template_id' => $template->id,
            'current_stage_item_id' => $template->items()->first()->id,
            'sanctioned_amount' => 100_000_00, // ₹1 lakh
        ];
    }

    public static function template(): StageTemplate
    {
        $t = StageTemplate::firstOrCreate(['code' => 'T_TEMPLATE'], ['name_en' => 'Test template']);
        if ($t->items()->doesntExist()) {
            foreach ([[1, 'Not started', 0, 0], [2, 'Foundation', 0, 40], [3, 'Structure', 35, 90], [4, 'Completed', 90, 100]] as [$seq, $n, $min, $max]) {
                $t->items()->create(['seq' => $seq, 'name_en' => $n, 'min_pct' => $min, 'max_pct' => $max]);
            }
        }

        return $t;
    }

    public function inDistrict(District $district): static
    {
        return $this->state(['district_id' => $district->id]);
    }
}
