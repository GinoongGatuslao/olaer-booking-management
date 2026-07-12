<?php

namespace App\Services;

use App\Models\EntranceSlip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class SecurityDashboardService
{
    public function overview(int $securityUserId): array
    {
        $todaySlips = EntranceSlip::query()
            ->whereDate('date_created', today());

        $paidToday = (clone $todaySlips)
            ->where('status', 'Paid');

        return [
            'my_slips_today' => (clone $todaySlips)
                ->where('created_by_user_id', $securityUserId)
                ->count(),

            'resort_slips_today' => (clone $todaySlips)->count(),

            'paid_slips_today' => (clone $paidToday)->count(),

            'unpaid_slips_today' => (clone $todaySlips)
                ->where('status', 'Unpaid')
                ->count(),

            'my_unpaid_slips_today' => (clone $todaySlips)
                ->where('created_by_user_id', $securityUserId)
                ->where('status', 'Unpaid')
                ->count(),

            // Only paid entrance slips are counted as admitted guests.
            'admitted_guests_today' => (int) (
                (clone $paidToday)->sum('no_of_adult')
                + (clone $paidToday)->sum('no_of_children')
                + (clone $paidToday)->sum('no_of_PWD_SC')
            ),

            'tourists_today' => (int) (clone $paidToday)->sum('no_of_Tourist'),
        ];
    }

    public function admittedGuestBreakdown(): array
    {
        $paidToday = EntranceSlip::query()
            ->whereDate('date_created', today())
            ->where('status', 'Paid');

        return [
            'adult' => (int) (clone $paidToday)->sum('no_of_adult'),
            'children' => (int) (clone $paidToday)->sum('no_of_children'),
            'pwd_sc' => (int) (clone $paidToday)->sum('no_of_PWD_SC'),
            'male' => (int) (clone $paidToday)->sum('no_of_Male'),
            'female' => (int) (clone $paidToday)->sum('no_of_Female'),
            'tourist' => (int) (clone $paidToday)->sum('no_of_Tourist'),
        ];
    }

    /**
     * Shows today's slips created by the logged-in guard. The cashier may
     * update their payment status while this dashboard is open.
     */
    public function myRecentSlips(int $securityUserId, int $limit = 12): Collection
    {
        return EntranceSlip::query()
            ->with([
                'details.entranceFee',
                'details.discount',
                'handledBy',
            ])
            ->where('created_by_user_id', $securityUserId)
            ->whereDate('date_created', today())
            ->orderByDesc('time_created')
            ->orderByDesc('entrance_slip_id')
            ->limit($limit)
            ->get();
    }

    public function formatSlipNumber(EntranceSlip $slip): string
    {
        $date = $slip->date_created instanceof Carbon
            ? $slip->date_created
            : Carbon::parse($slip->date_created);

        return 'ES-'
            . $date->format('Ymd')
            . '-'
            . str_pad((string) $slip->entrance_slip_id, 5, '0', STR_PAD_LEFT);
    }

    public function formatCreatedTime(?string $time): string
    {
        if (blank($time)) {
            return 'Unknown time';
        }

        return Carbon::parse($time)->format('h:i A');
    }

    public function totalGuests(EntranceSlip $slip): int
    {
        return (int) $slip->no_of_adult
            + (int) $slip->no_of_children
            + (int) $slip->no_of_PWD_SC;
    }
}
