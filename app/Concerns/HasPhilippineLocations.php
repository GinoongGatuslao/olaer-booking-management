<?php

namespace App\Concerns;

use App\Services\PhilippineLocationService;

/**
 * Cascading Province -> City/Municipality -> Barangay dropdowns for Livewire/Volt
 * components that have public string $province, $city and $barangay properties.
 */
trait HasPhilippineLocations
{
    /** A new province clears the city and barangay, which no longer apply. */
    public function updatedProvince(): void
    {
        $this->city = '';
        $this->barangay = '';
        $this->resetValidation(['city', 'barangay']);
    }

    public function updatedCity(): void
    {
        $this->barangay = '';
        $this->resetValidation('barangay');
    }

    /** @return array<int, string> */
    public function provinceOptions(): array
    {
        return app(PhilippineLocationService::class)->provinces((string) $this->province);
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
