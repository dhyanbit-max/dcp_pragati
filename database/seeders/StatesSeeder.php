<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Assam (LGD state code 18). The outline itself is loaded with `geo:import-boundaries state <file>`. */
class StatesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('states')->updateOrInsert(
            ['lgd_code' => '18'],
            ['name_en' => 'Assam', 'name_as' => 'অসম', 'name_bn' => 'আসাম', 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
