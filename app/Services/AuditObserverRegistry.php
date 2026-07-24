<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingDetail;
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
use App\Models\ReservationDetail;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Database\Eloquent\Model;

class AuditObserverRegistry
{
    /**
     * ActivityLog itself is intentionally absent to prevent recursive logs.
     *
     * @var array<int, class-string<Model>>
     */
    private const AUDITED_MODELS = [
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
        ReservationDetail::class,
        Booking::class,
        BookingDetail::class,
        EntranceSlip::class,
        Payment::class,
        AmenityRequest::class,
        FacilityInspectionRequest::class,
        FacilityInspection::class,
        GuestFine::class,
    ];

    public function register(): void
    {
        foreach (self::AUDITED_MODELS as $modelClass) {
            if (
                ! class_exists($modelClass)
                || ! is_subclass_of(
                    $modelClass,
                    Model::class,
                )
            ) {
                continue;
            }

            $modelClass::observe(
                AuditObserver::class,
            );
        }
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public function models(): array
    {
        return self::AUDITED_MODELS;
    }
}
