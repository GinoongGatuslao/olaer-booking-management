<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tbl_address')->updateOrInsert(
            [
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Poblacion',
                'purok' => 'N/A',
            ],
            [
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Poblacion',
                'purok' => 'N/A',
            ]
        );

        $addressId = DB::table('tbl_address')
            ->where('province', 'Sultan Kudarat')
            ->where('city', 'Tacurong City')
            ->where('barangay', 'Poblacion')
            ->value('address_id');

        $users = [
            ['role' => 'Admin', 'first_name' => 'System', 'last_name' => 'Admin', 'username' => 'admin', 'email' => 'admin@olaer.test'],
            ['role' => 'Manager', 'first_name' => 'System', 'last_name' => 'Manager', 'username' => 'manager', 'email' => 'manager@olaer.test'],
            ['role' => 'Cashier', 'first_name' => 'Demo', 'last_name' => 'Cashier', 'username' => 'cashier', 'email' => 'cashier@olaer.test'],
            ['role' => 'Maintenance Staff', 'first_name' => 'Demo', 'last_name' => 'Maintenance', 'username' => 'maintenance', 'email' => 'maintenance@olaer.test'],
            ['role' => 'Security Guard', 'first_name' => 'Demo', 'last_name' => 'Security', 'username' => 'security', 'email' => 'security@olaer.test'],
        ];

        foreach ($users as $user) {
            $roleId = DB::table('tbl_role')->where('role_name', $user['role'])->value('role_id');

            DB::table('tbl_user')->updateOrInsert(
                ['username' => $user['username']],
                [
                    'first_name' => $user['first_name'],
                    'middle_name' => null,
                    'last_name' => $user['last_name'],
                    'password' => Hash::make('password'),
                    'email' => $user['email'],
                    'contact_no' => '09000000000',
                    'status' => 'Active',
                    'address_id' => $addressId,
                    'role_id' => $roleId,
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
