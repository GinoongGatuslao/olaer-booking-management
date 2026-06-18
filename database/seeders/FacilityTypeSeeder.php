<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FacilityTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Cottage', 'Room', 'Function Hall'] as $type) {
            DB::table('tbl_facility_type')->updateOrInsert(
                ['facility_type' => $type],
                ['facility_type' => $type]
            );
        }
    }
}
