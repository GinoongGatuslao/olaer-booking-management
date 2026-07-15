<?php

namespace Tests\Unit;

use App\Models\Facility;
use App\Models\FacilityType;
use App\Services\FacilityOccupancyService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FacilityOccupancyServiceTest extends TestCase
{
    private FacilityOccupancyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(
            FacilityOccupancyService::class,
        );
    }

    #[Test]
    public function room_includes_four_and_charges_only_above_four(): void
    {
        $result = $this->service->forFacility(
            $this->facility('Room', '10 pax'),
            7,
        );

        $this->assertSame(10, $result['capacity']);
        $this->assertSame(4, $result['included_guest_count']);
        $this->assertSame(3, $result['paid_extra_guest_count']);
        $this->assertSame(6, $result['max_paid_extra_guests']);
    }

    #[Test]
    public function cottage_uses_capacity_without_paid_extra_guests(): void
    {
        $result = $this->service->forFacility(
            $this->facility('Cottage', '20'),
            20,
        );

        $this->assertSame(20, $result['included_guest_count']);
        $this->assertSame(0, $result['paid_extra_guest_count']);
    }

    #[Test]
    public function function_hall_cannot_exceed_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'can accommodate only 100 guest(s)',
        );

        $this->service->forFacility(
            $this->facility('Function Hall', '100 pax'),
            101,
        );
    }

    #[Test]
    public function capacity_parser_supports_ranges_and_text(): void
    {
        $this->assertSame(
            15,
            $this->service->capacityFor(
                $this->facility('Room', '10-15 guests'),
            ),
        );
    }

    #[Test]
    public function paid_extra_guest_names_must_match_computed_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->assertNamedPaidExtraGuests(
            [['first_name' => 'One']],
            2,
        );
    }

    private function facility(
        string $type,
        string $capacity,
    ): Facility {
        $facility = new Facility([
            'facility_name' => 'Test Facility',
            'capacity' => $capacity,
        ]);
        $facility->facility_id = 1;
        $facility->setRelation(
            'facilityType',
            new FacilityType([
                'facility_type' => $type,
            ]),
        );

        return $facility;
    }
}
