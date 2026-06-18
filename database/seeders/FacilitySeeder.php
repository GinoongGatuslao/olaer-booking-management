<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $cottageTypeId = DB::table('tbl_facility_type')->where('facility_type', 'Cottage')->value('facility_type_id');
        $roomTypeId = DB::table('tbl_facility_type')->where('facility_type', 'Room')->value('facility_type_id');
        $functionHallTypeId = DB::table('tbl_facility_type')->where('facility_type', 'Function Hall')->value('facility_type_id');

        $this->seedCottages($cottageTypeId);
        $this->seedRooms($roomTypeId);
        $this->seedFunctionHalls($functionHallTypeId);
        $this->attachInclusiveAmenities();
    }

    private function seedCottages(int $typeId): void
    {
        $groups = [
            ['prefix' => 'C300', 'count' => 55, 'size' => 'Small Cottage', 'capacity' => '4-6', 'price' => 300.00],
            ['prefix' => 'C400', 'count' => 46, 'size' => 'Medium Cottage', 'capacity' => '8-10', 'price' => 400.00],
            ['prefix' => 'C600', 'count' => 26, 'size' => 'Large Cottage', 'capacity' => '10-15', 'price' => 600.00],
            ['prefix' => 'C900', 'count' => 5, 'size' => 'Extra Large Cottage', 'capacity' => '15-25', 'price' => 900.00],
        ];

        foreach ($groups as $group) {
            for ($i = 1; $i <= $group['count']; $i++) {
                $facilityName = $group['prefix'] . '-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);

                $this->upsertFacilityWithPrice(
                    facilityName: $facilityName,
                    facilityTypeId: $typeId,
                    facilitySize: $group['size'],
                    capacity: $group['capacity'],
                    prices: [
                        'Day Rate' => $group['price'],
                        'Night Rate' => $group['price'],
                    ]
                );
            }
        }
    }

    private function seedRooms(int $typeId): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->upsertFacilityWithPrice(
                facilityName: 'R-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                facilityTypeId: $typeId,
                facilitySize: 'Standard Room',
                capacity: '4 default / 10 max',
                prices: [
                    'Overnight' => 2500.00,
                ]
            );
        }
    }

    private function seedFunctionHalls(int $typeId): void
    {
        $halls = [
            ['name' => 'FH-1', 'size' => 'Function Hall 1', 'capacity' => '25', 'price' => 1200.00],
            ['name' => 'FH-2', 'size' => 'Function Hall 2', 'capacity' => '30', 'price' => 1500.00],
        ];

        foreach ($halls as $hall) {
            $this->upsertFacilityWithPrice(
                facilityName: $hall['name'],
                facilityTypeId: $typeId,
                facilitySize: $hall['size'],
                capacity: $hall['capacity'],
                prices: [
                    'Day Rate' => $hall['price'],
                    'Night Rate' => $hall['price'],
                ]
            );
        }
    }

    private function upsertFacilityWithPrice(
        string $facilityName,
        int $facilityTypeId,
        string $facilitySize,
        string $capacity,
        array $prices
    ): void {
        DB::table('tbl_facility')->updateOrInsert(
            ['facility_name' => $facilityName],
            [
                'facility_type_id' => $facilityTypeId,
                'facility_size' => $facilitySize,
                'facility_status' => 'Available',
                'capacity' => $capacity,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $facilityId = DB::table('tbl_facility')
            ->where('facility_name', $facilityName)
            ->value('facility_id');

        foreach ($prices as $rateType => $price) {
            DB::table('tbl_facility_price')->updateOrInsert(
                [
                    'facility_id' => $facilityId,
                    'rate_type' => $rateType,
                ],
                [
                    'facility_price' => $price,
                ]
            );
        }
    }

    private function attachInclusiveAmenities(): void
    {
        $roomAmenities = [
            'Private Shower' => 1,
            'Television' => 1,
            'Air Conditioning' => 1,
            'Bed' => 2,
            'Pillow' => 4,
            'Blanket' => 4,
            'Foot Rug' => 1,
            'Table' => 1,
            'Chair' => 4,
            'Towel' => 4,
            'Room Key' => 1,
            'Aircon Remote' => 1,
            'Faucet' => 1,
            'Bidet' => 1,
            'Vase' => 1,
            'TV Remote' => 1,
        ];

        $functionHallAmenities = [
            'Table' => ['FH-1' => 4, 'FH-2' => 5],
            'Chair' => ['FH-1' => 25, 'FH-2' => 30],
        ];

        $this->attachAmenitiesToFacilitiesByPrefix('R-', $roomAmenities);

        foreach ($functionHallAmenities as $amenityName => $facilityQuantities) {
            foreach ($facilityQuantities as $facilityName => $quantity) {
                $this->attachAmenity($facilityName, $amenityName, $quantity);
            }
        }
    }

    private function attachAmenitiesToFacilitiesByPrefix(string $prefix, array $amenities): void
    {
        $facilityNames = DB::table('tbl_facility')
            ->where('facility_name', 'like', $prefix . '%')
            ->pluck('facility_name');

        foreach ($facilityNames as $facilityName) {
            foreach ($amenities as $amenityName => $quantity) {
                $this->attachAmenity($facilityName, $amenityName, $quantity);
            }
        }
    }

    private function attachAmenity(string $facilityName, string $amenityName, int $quantity): void
    {
        $facilityId = DB::table('tbl_facility')
            ->where('facility_name', $facilityName)
            ->value('facility_id');

        $amenityId = DB::table('tbl_amenity')
            ->join('tbl_amenity_name', 'tbl_amenity.amenity_name_id', '=', 'tbl_amenity_name.amenity_name_id')
            ->where('tbl_amenity_name.amenity_name', $amenityName)
            ->where('tbl_amenity.amenity_type', 'Inclusive')
            ->value('tbl_amenity.amenity_id');

        // Chair is both rentable and used as inclusive in rooms/function halls.
        // If no inclusive Chair exists, fall back to the rentable Chair row.
        if (! $amenityId) {
            $amenityId = DB::table('tbl_amenity')
                ->join('tbl_amenity_name', 'tbl_amenity.amenity_name_id', '=', 'tbl_amenity_name.amenity_name_id')
                ->where('tbl_amenity_name.amenity_name', $amenityName)
                ->value('tbl_amenity.amenity_id');
        }

        if (! $facilityId || ! $amenityId) {
            return;
        }

        DB::table('tbl_facility_amenities')->updateOrInsert(
            [
                'facility_id' => $facilityId,
                'amenity_id' => $amenityId,
            ],
            [
                'amenity_quantity' => $quantity,
            ]
        );
    }
}
