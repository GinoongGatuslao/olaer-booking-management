<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentModeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Cash', 'GCash'] as $mode) {
            DB::table('tbl_mode_of_payment')->updateOrInsert(
                ['mode_of_payment' => $mode],
                ['mode_of_payment' => $mode]
            );
        }
    }
}
