<?php

namespace Database\Seeders;

use App\Domain\Reports\ReportRunner;
use App\Models\ReportTemplate;
use Illuminate\Database\Seeder;

/** One report_templates row per report so runs can be recorded. Layout lives in the report classes until the Department's formats arrive (docs/18 D-5). */
class ReportTemplatesSeeder extends Seeder
{
    public function run(ReportRunner $runner): void
    {
        foreach ($runner->all() as $code => $report) {
            ReportTemplate::updateOrCreate(['code' => $code], [
                'name_en' => $report->title(), 'type' => 'mis', 'definition_json' => [], 'formats' => ['xlsx', 'pdf'],
            ]);
        }
        ReportTemplate::updateOrCreate(['code' => 'AQ'], [
            'name_en' => 'Assembly Question reply', 'type' => 'aq', 'definition_json' => [], 'formats' => ['xlsx', 'pdf'],
        ]);
    }
}
