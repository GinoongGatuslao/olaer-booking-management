<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EntranceFeeSeeder extends Seeder
{
    public function run(): void
    {
        $fees = [
            ['entrance_fee_name' => 'Adult', 'entrance_fee_price' => 100.00],
            ['entrance_fee_name' => 'Children', 'entrance_fee_price' => 75.00],
            ['entrance_fee_name' => 'Senior Citizen / PWD', 'entrance_fee_price' => 80.00],
        ];

        foreach ($fees as $fee) {
            DB::table('tbl_entrance_fee')->updateOrInsert(
                ['entrance_fee_name' => $fee['entrance_fee_name']],
                array_merge($fee, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
