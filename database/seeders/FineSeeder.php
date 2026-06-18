<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FineSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Damaged', 'Missing', 'Stained'] as $damageType) {
            DB::table('tbl_damage_type')->updateOrInsert(
                ['damage_type' => $damageType],
                ['damage_type' => $damageType]
            );
        }

        $situationalFines = [
            [
                'situational_fine' => 'Stained Fabric',
                'situational_fine_description' => 'Any stained fabric assessed by maintenance staff',
                'fine_charge' => 200.00,
            ],
            [
                'situational_fine' => 'Vomit in Room',
                'situational_fine_description' => 'Vomit found anywhere in the room',
                'fine_charge' => 1000.00,
            ],
            [
                'situational_fine' => 'Vomit on Sheets or Fabric',
                'situational_fine_description' => 'Vomit found on sheets or other fabric surfaces',
                'fine_charge' => 2000.00,
            ],
        ];

        foreach ($situationalFines as $fine) {
            DB::table('tbl_fine')->updateOrInsert(
                [
                    'fine_type' => 'Situational',
                    'situational_fine' => $fine['situational_fine'],
                ],
                [
                    'amenity_id' => null,
                    'damage_type_id' => null,
                    'situational_fine_description' => $fine['situational_fine_description'],
                    'fine_charge' => $fine['fine_charge'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
