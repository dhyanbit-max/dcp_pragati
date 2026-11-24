<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            RuleAndAlertDefaultsSeeder::class,
            ReportTemplatesSeeder::class,
            StatesSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call([DemoDataSeeder::class, DemoProgressSeeder::class, DemoStatewideSeeder::class, DemoGrievanceSeeder::class]);
        }
    }
}
