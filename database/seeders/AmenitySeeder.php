<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AmenitySeeder extends Seeder
{
    public function run(): void
    {
        $amenities = [
            ['name' => 'Table Set', 'description' => '1 large table and 6 chairs', 'type' => 'Rentable', 'price' => 300.00],
            ['name' => 'Small Table Set', 'description' => '1 small table and 4 chairs', 'type' => 'Rentable', 'price' => 150.00],
            ['name' => 'Chair', 'description' => 'Individual chair rental', 'type' => 'Rentable', 'price' => 25.00],
            ['name' => 'Extra Bedding Set', 'description' => '2 pillows and 1 blanket for 3 pax', 'type' => 'Rentable', 'price' => 300.00],

            ['name' => 'Private Shower', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Television', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Air Conditioning', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Bed', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Pillow', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Blanket', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Foot Rug', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Table', 'description' => 'Room/function hall inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Towel', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Room Key', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Aircon Remote', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Faucet', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Bidet', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'Vase', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
            ['name' => 'TV Remote', 'description' => 'Room inclusive item', 'type' => 'Inclusive', 'price' => 0.00],
        ];

        foreach ($amenities as $amenity) {
            DB::table('tbl_amenity_name')->updateOrInsert(
                ['amenity_name' => $amenity['name']],
                ['amenity_name' => $amenity['name']]
            );

            $amenityNameId = DB::table('tbl_amenity_name')
                ->where('amenity_name', $amenity['name'])
                ->value('amenity_name_id');

            DB::table('tbl_amenity')->updateOrInsert(
                [
                    'amenity_name_id' => $amenityNameId,
                    'amenity_type' => $amenity['type'],
                ],
                [
                    'amenity_description' => $amenity['description'],
                    'amenity_price' => $amenity['price'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
