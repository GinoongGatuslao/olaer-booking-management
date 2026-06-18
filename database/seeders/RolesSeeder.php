<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Admin', 'Manager', 'Cashier', 'Maintenance Staff', 'Security Guard'] as $roleName) {
            DB::table('tbl_role')->updateOrInsert(
                ['role_name' => $roleName],
                ['role_name' => $roleName]
            );
        }
    }
}
