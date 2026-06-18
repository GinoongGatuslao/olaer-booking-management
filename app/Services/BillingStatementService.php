<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\FacilityPrice;
use App\Models\GuestFine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BillingStatementService
{
    public function records(array $filters = []): Collection
    {
        $transactionType = strtolower((string) ($filters['transaction_type'] ?? 'all'));
        $records = collect();

        if ($transactionType === 'all' || $transactionType === 'booking') {
            $records = $records->merge($this->bookingRecords($filters));
        }

        if ($transactionType === 'all' || $transactionType === 'amenity_request') {
            $records = $records->merge($this->amenityRequestRecords($filters));
        }

        if ($transactionType === 'all' || $transactionType === 'fine') {
            $records = $records->merge($this->fineRecords($filters));
        }

        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        if ($search !== '') {
            $records = $records->filter(function (array $record) use ($search): bool {
                $haystack = strtolower(implode(' ', [
                    $record['reference_no'],
                    $record['booking_ref_no'],
                    $record['guest_name'],
                    $record['description'],
                    $record['transaction_type'],
                ]));

                return str_contains($haystack, $search);
            });
        }

        $paymentStatus = strtolower((string) ($filters['payment_status'] ?? 'all'));
        if ($paymentStatus !== 'all') {
            $records = $records->filter(function (array $record) use ($paymentStatus): bool {
                return strtolower($record['payment_status']) === $paymentStatus;
            });
        }

        return $records
            ->sortByDesc('date')
            ->values();
    }

    public function statementForBooking(int $bookingId): array
    {
        $booking = Booking::query()
            ->with([
                'guest.address',
                'user',
                'reservation',
                'entranceSlip',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'amenityRequests.details.amenity.amenityName',
                'amenityRequests.details.facility',
                'guestFines.fine.amenity.amenityName',
                'guestFines.fine.damageType',
                'guestFines.facility',
                'guestFines.reportedBy',
                'payments.modeOfPayment',
                'payments.user',
            ])
            ->findOrFail($bookingId);

        $verifiedPayments = $booking->payments->filter(function ($payment): bool {
            return strtolower((string) $payment->payment_status) === 'verified';
        });

        $facilityLines = $booking->details->map(function ($detail): array {
            return [
                'facility' => $detail->facility?->facility_name ?? 'Facility unavailable',
                'facility_type' => $detail->facility?->facilityType?->facility_type ?? 'N/A',
                'rate_type' => (string) $detail->rate_type,
                'check_in_date' => optional($detail->check_in_date)->toDateString(),
                'check_out_date' => optional($detail->check_out_date)->toDateString(),
                'status' => (string) $detail->status,
                'base_price' => $this->moneyOrFallback($detail->base_price, $this->currentFacilityRate((int) $detail->facility_id, (string) $detail->rate_type)),
                'discount_amount' => round((float) ($detail->discount_amount ?? 0), 2),
                'extra_guest_fee' => round((float) ($detail->extra_guest_fee ?? 0), 2),
                'line_total' => $this->moneyOrFallback($detail->line_total, null),
                'has_snapshot' => $detail->line_total !== null,
            ];
        })->values();

        $amenityLines = $booking->amenityRequests
            ->filter(function ($request): bool {
                return (string) $request->amenity_request_status !== 'Cancelled';
            })
            ->flatMap(function ($request) {
                return $request->details->map(function ($detail) use ($request): array {
                    $unitPrice = $this->moneyOrFallback($detail->unit_price, (float) ($detail->amenity?->amenity_price ?? 0));
                    $quantity = (int) $detail->amenity_quantity;

                    return [
                        'request_id' => (int) $request->amenity_request_id,
                        'request_status' => (string) $request->amenity_request_status,
                        'date_created' => optional($request->date_created)->toDateString(),
                        'amenity' => $detail->amenity?->amenityName?->amenity_name ?? 'Amenity unavailable',
                        'facility' => $detail->facility?->facility_name ?? 'Facility unavailable',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $this->moneyOrFallback($detail->line_total, round($unitPrice * $quantity, 2)),
                        'has_snapshot' => $detail->line_total !== null,
                    ];
                });
            })
            ->values();

        $fineLines = $booking->guestFines->map(function ($guestFine): array {
            $fine = $guestFine->fine;
            $description = $fine?->fine_type === 'Amenity'
                ? trim(($fine?->amenity?->amenityName?->amenity_name ?? 'Amenity') . ' - ' . ($fine?->damageType?->damage_type ?? 'Damage'))
                : ($fine?->situational_fine ?? 'Situational Fine');

            return [
                'guest_fine_id' => (int) $guestFine->guest_fine_id,
                'description' => $description,
                'facility' => $guestFine->facility?->facility_name ?? 'N/A',
                'quantity' => (int) $guestFine->quantity,
                'total_charge' => round((float) $guestFine->total_charge, 2),
                'date_checked' => optional($guestFine->date_checked)->toDateString(),
                'reported_by' => $guestFine->reportedBy?->full_name ?? $guestFine->reportedBy?->username ?? 'N/A',
            ];
        })->values();

        $paymentLines = $verifiedPayments->map(function ($payment): array {
            return [
                'payment_ref_no' => (string) $payment->p_ref_no,
                'mode' => $payment->modeOfPayment?->mode_of_payment ?? 'N/A',
                'reference_number' => $payment->reference_number,
                'amount_paid' => round((float) $payment->amount_paid, 2),
                'date_paid' => optional($payment->date_paid)->toDateString(),
                'received_by' => $payment->user?->full_name ?? $payment->user?->username ?? 'N/A',
            ];
        })->values();

        return [
            'booking' => $booking,
            'guest_name' => $booking->guest?->full_name ?? 'Guest unavailable',
            'guest_contact' => $booking->guest?->contact_no ?? 'N/A',
            'guest_email' => $booking->guest?->email ?? 'N/A',
            'facility_lines' => $facilityLines,
            'amenity_lines' => $amenityLines,
            'fine_lines' => $fineLines,
            'payment_lines' => $paymentLines,
            'total_price' => round((float) $booking->total_price, 2),
            'total_paid' => round((float) $verifiedPayments->sum('amount_paid'), 2),
            'amount_due' => round((float) $booking->amount_due, 2),
            'payment_status' => round((float) $booking->amount_due, 2) <= 0 ? 'Paid' : 'Unpaid',
            'generated_at' => Carbon::now()->format('Y-m-d h:i A'),
        ];
    }

    private function bookingRecords(array $filters): Collection
    {
        return Booking::query()
            ->with(['guest', 'payments'])
            ->when($this->from($filters), function ($query, string $from): void {
                $query->whereDate('booking_date', '>=', $from);
            })
            ->when($this->to($filters), function ($query, string $to): void {
                $query->whereDate('booking_date', '<=', $to);
            })
            ->get()
            ->map(function (Booking $booking): array {
                return [
                    'transaction_type' => 'Booking',
                    'reference_no' => (string) $booking->b_ref_no,
                    'booking_ref_no' => (string) $booking->b_ref_no,
                    'booking_id' => (int) $booking->booking_id,
                    'guest_name' => $booking->guest?->full_name ?? 'Guest unavailable',
                    'date' => optional($booking->booking_date)->toDateString(),
                    'description' => 'Facility booking',
                    'amount' => round((float) $booking->total_price, 2),
                    'amount_due' => round((float) $booking->amount_due, 2),
                    'payment_status' => round((float) $booking->amount_due, 2) <= 0 ? 'Paid' : 'Unpaid',
                ];
            });
    }

    private function amenityRequestRecords(array $filters): Collection
    {
        return AmenityRequest::query()
            ->with(['booking.guest', 'details.amenity.amenityName'])
            ->where('amenity_request_status', '!=', 'Cancelled')
            ->when($this->from($filters), function ($query, string $from): void {
                $query->whereDate('date_created', '>=', $from);
            })
            ->when($this->to($filters), function ($query, string $to): void {
                $query->whereDate('date_created', '<=', $to);
            })
            ->get()
            ->map(function (AmenityRequest $request): array {
                $names = $request->details->map(function ($detail): string {
                    return $detail->amenity?->amenityName?->amenity_name ?? 'Amenity';
                })->unique()->implode(', ');

                return [
                    'transaction_type' => 'Amenity Request',
                    'reference_no' => 'AR-' . $request->amenity_request_id,
                    'booking_ref_no' => (string) ($request->booking?->b_ref_no ?? 'N/A'),
                    'booking_id' => (int) ($request->booking_id ?? 0),
                    'guest_name' => $request->booking?->guest?->full_name ?? 'Guest unavailable',
                    'date' => optional($request->date_created)->toDateString(),
                    'description' => $names !== '' ? $names : 'Amenity request',
                    'amount' => round((float) $request->total_price, 2),
                    'amount_due' => $request->amenity_request_status === 'Awaiting Payment' ? round((float) $request->total_price, 2) : 0.00,
                    'payment_status' => $request->amenity_request_status === 'Awaiting Payment' ? 'Unpaid' : 'Paid',
                ];
            });
    }

    private function fineRecords(array $filters): Collection
    {
        return GuestFine::query()
            ->with(['booking.guest', 'fine.amenity.amenityName', 'fine.damageType'])
            ->when($this->from($filters), function ($query, string $from): void {
                $query->whereDate('date_checked', '>=', $from);
            })
            ->when($this->to($filters), function ($query, string $to): void {
                $query->whereDate('date_checked', '<=', $to);
            })
            ->get()
            ->map(function (GuestFine $guestFine): array {
                $fine = $guestFine->fine;
                $description = $fine?->fine_type === 'Amenity'
                    ? trim(($fine?->amenity?->amenityName?->amenity_name ?? 'Amenity') . ' - ' . ($fine?->damageType?->damage_type ?? 'Damage'))
                    : ($fine?->situational_fine ?? 'Fine');

                return [
                    'transaction_type' => 'Fine',
                    'reference_no' => 'GF-' . $guestFine->guest_fine_id,
                    'booking_ref_no' => (string) ($guestFine->booking?->b_ref_no ?? 'N/A'),
                    'booking_id' => (int) ($guestFine->booking_id ?? 0),
                    'guest_name' => $guestFine->booking?->guest?->full_name ?? 'Guest unavailable',
                    'date' => optional($guestFine->date_checked)->toDateString(),
                    'description' => $description,
                    'amount' => round((float) $guestFine->total_charge, 2),
                    'amount_due' => round((float) ($guestFine->booking?->amount_due ?? 0), 2) > 0 ? round((float) $guestFine->total_charge, 2) : 0.00,
                    'payment_status' => round((float) ($guestFine->booking?->amount_due ?? 0), 2) <= 0 ? 'Paid' : 'Unpaid',
                ];
            });
    }

    private function from(array $filters): ?string
    {
        $value = trim((string) ($filters['from_date'] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function to(array $filters): ?string
    {
        $value = trim((string) ($filters['to_date'] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function moneyOrFallback(mixed $value, ?float $fallback): float
    {
        if ($value !== null) {
            return round((float) $value, 2);
        }

        return round((float) ($fallback ?? 0), 2);
    }

    private function currentFacilityRate(int $facilityId, string $rateType): ?float
    {
        if ($facilityId < 1 || $rateType === '') {
            return null;
        }

        $price = FacilityPrice::query()
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->value('facility_price');

        return $price !== null ? (float) $price : null;
    }
}
