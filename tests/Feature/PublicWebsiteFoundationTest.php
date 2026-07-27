<?php

namespace Tests\Feature;

use App\Models\EntranceFee;
use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicWebsiteFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_public_destination_renders_with_the_shared_layout(): void
    {
        foreach ([
            'guest.home',
            'guest.reservations.create',
            'guest.reservations.success',
            'guest.bookings.create',
            'guest.bookings.success',
            'guest.reservations.manage',
            'guest.confirmations.lookup',
        ] as $routeName) {
            $response = $this->get(route($routeName));

            $this->assertSame(
                200,
                $response->getStatusCode(),
                sprintf(
                    'The public destination [%s] failed to render.',
                    $routeName,
                ),
            );

            $response->assertSee('Olaer Spring Resort');
            $response->assertSee('Reserve a Facility');
        }
    }

    public function test_homepage_presents_database_backed_facility_and_entrance_rates(): void
    {
        $facilityType = FacilityType::query()->create([
            'facility_type' => 'Garden Cottage',
        ]);

        $firstFacility = Facility::query()->create([
            'facility_name' => 'Garden Cottage 1',
            'facility_type_id' => $facilityType->facility_type_id,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '6',
        ]);

        Facility::query()->create([
            'facility_name' => 'Garden Cottage 2',
            'facility_type_id' => $facilityType->facility_type_id,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '6',
        ]);

        FacilityPrice::query()->create([
            'facility_id' => $firstFacility->facility_id,
            'rate_type' => 'Day Rate',
            'facility_price' => 850,
        ]);

        FacilityPrice::query()->create([
            'facility_id' => $firstFacility->facility_id,
            'rate_type' => 'Night Rate',
            'facility_price' => 1100,
        ]);

        EntranceFee::query()->create([
            'entrance_fee_name' => 'Adult Day Entrance',
            'entrance_fee_price' => 100,
        ]);

        $response = $this->get(route('guest.home'));

        $response
            ->assertOk()
            ->assertSee('Garden Cottage')
            ->assertSee('2 facilities')
            ->assertSee('Day Rate')
            ->assertSee('Night Rate')
            ->assertSee('₱850.00')
            ->assertSee('Adult Day Entrance')
            ->assertSee('₱100.00');
    }

    public function test_homepage_keeps_reservation_primary_and_direct_booking_secondary(): void
    {
        $response = $this->get(route('guest.home'));

        $response
            ->assertOk()
            ->assertSee('Reserve a Facility')
            ->assertSee('Direct booking')
            ->assertSee(route('guest.reservations.create'), false)
            ->assertSee(route('guest.bookings.create'), false);
    }

    public function test_direct_booking_page_uses_global_exceptions_without_ineffective_imports(): void
    {
        $bookingPage = file_get_contents(
            resource_path('views/livewire/guest/bookings/create.blade.php'),
        );

        $this->assertIsString($bookingPage);
        $this->assertStringNotContainsString('use Throwable;', $bookingPage);
        $this->assertStringContainsString(
            'catch (\InvalidArgumentException $exception)',
            $bookingPage,
        );
        $this->assertStringContainsString(
            'catch (\Throwable $exception)',
            $bookingPage,
        );
    }

    public function test_public_contact_details_match_the_resort_configuration(): void
    {
        $resort = config('olaer.public');

        $this->assertSame(
            'Purok Olaer, General Santos City (Dadiangas), 9500 South Cotabato',
            $resort['address'],
        );
        $this->assertSame('09279435323', $resort['phones'][0]['display']);
        $this->assertSame('0967 217 4485', $resort['phones'][1]['display']);
        $this->assertSame('olaermarketing@gmail.com', $resort['email']);
        $this->assertSame('Open 24 hours', $resort['hours']);
        $this->assertSame(
            'https://maps.app.goo.gl/vf7jnCaVwTEhDfEw8',
            $resort['map_url'],
        );
        $this->assertSame(
            'https://www.facebook.com/OlaerSwimmingResort',
            $resort['facebook_url'],
        );

        $this->get(route('guest.home'))
            ->assertOk()
            ->assertSee($resort['address'])
            ->assertSee($resort['phones'][0]['display'])
            ->assertSee($resort['phones'][1]['display'])
            ->assertSee($resort['email']);
    }

    public function test_public_visual_foundation_uses_first_party_assets_and_live_values(): void
    {
        foreach ([
            'logo.png',
            'hero-spring.webp',
            'spring-day.webp',
            'entrance-night.webp',
            'aerial-pools.webp',
            'resort-grounds.webp',
            'olaer-sign.webp',
            'pools-aerial.webp',
            'family-spring.webp',
        ] as $asset) {
            $this->assertFileExists(
                public_path('images/olaer/'.$asset),
            );
        }

        $layout = file_get_contents(
            resource_path('views/layouts/public.blade.php'),
        );
        $homepage = file_get_contents(
            resource_path('views/livewire/guest/home.blade.php'),
        );

        $this->assertIsString($layout);
        $this->assertIsString($homepage);

        $this->assertStringContainsString(
            '<x-public-navigation />',
            $layout,
        );
        $this->assertStringContainsString(
            '<x-public-footer />',
            $layout,
        );
        $this->assertStringContainsString(
            'FacilityType::query()',
            $homepage,
        );
        $this->assertStringContainsString(
            'EntranceFee::query()',
            $homepage,
        );

        foreach ([
            'Tacurong City, Sultan Kudarat',
            'Unsplash',
            '<div class="text-3xl font-bold">132</div>',
            '<div class="text-3xl font-bold">12</div>',
            '<div class="text-3xl font-bold">2</div>',
        ] as $staleContent) {
            $this->assertStringNotContainsString(
                $staleContent,
                $homepage,
            );
        }
    }
}
