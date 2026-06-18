<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            PaymentModeSeeder::class,
            EntranceFeeSeeder::class,
            FacilityTypeSeeder::class,
            AmenitySeeder::class,
            FineSeeder::class,
            FacilitySeeder::class,
            UserSeeder::class,
        ]);
    }
}
