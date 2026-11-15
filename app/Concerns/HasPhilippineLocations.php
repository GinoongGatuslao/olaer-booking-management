<?php

namespace App\Concerns;

use App\Services\PhilippineLocationService;

/**
 * Cascading Region -> Province -> City/Municipality -> Barangay dropdowns for Livewire/Volt
 * components that have public string $province, $city and $barangay properties.
 * The region is only a helper for narrowing the list; it is not saved anywhere.
 */
trait HasPhilippineLocations
{
    public string $region = '';

    /** A new region clears everything beneath it. */
    public function updatedRegion(): void
    {
        $this->province = '';
        $this->city = '';
        $this->barangay = '';
        $this->resetValidation(['province', 'city', 'barangay']);
    }

    /** A new province clears the city and barangay, which no longer apply. */
    public function updatedProvince(): void
    {
        $this->city = '';
        $this->barangay = '';
        $this->resetValidation(['city', 'barangay']);

        $region = app(PhilippineLocationService::class)->regionOfProvince((string) $this->province);

        if ($region !== null) {
            $this->region = $region;
        }
    }

    public function updatedCity(): void
    {
        $this->barangay = '';
        $this->resetValidation('barangay');
    }

    /** @return array<int, string> */
    public function regionOptions(): array
    {
        $locations = app(PhilippineLocationService::class);

        // A province that was filled in elsewhere (saved user, returning guest, default)
        // decides the region shown.
        if ((string) $this->province !== '') {
            $region = $locations->regionOfProvince((string) $this->province);

            if ($region !== null) {
                $this->region = $region;
            }
        }

        return $locations->regions();
    }

    /** @return array<int, string> */
    public function provinceOptions(): array
    {
        return app(PhilippineLocationService::class)->provinces((string) $this->region, (string) $this->province);
    }

    /** @return array<int, string> */
    public function cityOptions(): array
    {
        return app(PhilippineLocationService::class)->cities((string) $this->province, (string) $this->city);
    }

    /** @return array<int, string> */
    public function barangayOptions(): array
    {
        return app(PhilippineLocationService::class)->barangays((string) $this->province, (string) $this->city, (string) $this->barangay);
    }
}
