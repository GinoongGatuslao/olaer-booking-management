<?php

namespace Tests\Unit;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilitySchedulePolicy;
use App\Models\Facility;
use App\Models\FacilityProduct;
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
    public function cottage_allows_estimates_above_recommended_capacity_without_paid_extra_guests(): void
    {
        $result = $this->service->forFacility(
            $this->facility('Cottage', '4-6'),
            20,
        );

        $this->assertSame(6, $result['suggested_maximum']);
        $this->assertNull($result['included_guest_count']);
        $this->assertSame(0, $result['paid_extra_guest_count']);
    }

    #[Test]
    public function facility_estimate_cannot_exceed_parent_unique_party(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'cannot exceed',
        );

        $this->service->forFacility(
            $this->facility('Function Hall', '25'),
            30,
            20,
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
        $facility->facility_type_id = 1;
        $facility->setRelation(
            'facilityType',
            new FacilityType([
                'facility_type' => $type,
            ]),
        );
        $isRoom = $type === 'Room';
        $product = new FacilityProduct([
            'product_code' => $isRoom
                ? FacilityProductCode::RoomStandard
                : ($type === 'Cottage'
                    ? FacilityProductCode::CottageSmall
                    : FacilityProductCode::FunctionHall1),
            'facility_type_id' => 1,
            'display_name' => $type,
            'schedule_policy' => $isRoom
                ? FacilitySchedulePolicy::Overnight
                : ($type === 'Cottage'
                    ? FacilitySchedulePolicy::DatedSlots
                    : FacilitySchedulePolicy::WholeCalendarDay),
            'capacity_policy' => $isRoom
                ? FacilityCapacityPolicy::Strict
                : FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => $type === 'Cottage' ? 4 : null,
            'suggested_maximum' => $isRoom ? null : ($type === 'Cottage' ? 6 : 25),
            'included_guest_count' => $isRoom ? 4 : null,
            'strict_maximum' => $isRoom ? 10 : null,
            'is_active' => true,
        ]);
        $product->facility_product_id = 1;
        $product->setRelation('productRates', collect());
        $facility->facility_product_id = 1;
        $facility->setRelation('facilityProduct', $product);

        return $facility;
    }
}
