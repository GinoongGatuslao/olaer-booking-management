<?php

namespace App\Providers;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\DamageType;
use App\Models\Discount;
use App\Models\EntranceFee;
use App\Models\EntranceSlip;
use App\Models\Facility;
use App\Models\FacilityAmenity;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionRequest;
use App\Models\FacilityPrice;
use App\Models\Fine;
use App\Models\GuestFine;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $auditedModels = [
            User::class,
            EntranceFee::class,
            Discount::class,
            Facility::class,
            FacilityPrice::class,
            FacilityAmenity::class,
            Amenity::class,
            Fine::class,
            DamageType::class,
            Reservation::class,
            Booking::class,
            EntranceSlip::class,
            Payment::class,
            AmenityRequest::class,
            FacilityInspectionRequest::class,
            FacilityInspection::class,
            GuestFine::class,
        ];

        foreach ($auditedModels as $modelClass) {
            $modelClass::observe(AuditObserver::class);
        }
    }
}
