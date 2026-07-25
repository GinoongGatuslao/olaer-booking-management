<?php

namespace Tests\Feature;

use Tests\TestCase;

class OperationalTableVisualFoundationTest extends TestCase
{
    public function test_selected_operational_routes_use_the_live_list_pages(): void
    {
        $routes = file_get_contents(
            base_path('routes/web.php'),
        );

        $this->assertIsString($routes);

        $this->assertStringContainsString(
            "Volt::route('/cashier/bookings', 'cashier.bookings.index')",
            $routes,
        );

        $this->assertStringContainsString(
            "Volt::route('/cashier/reservations', 'cashier.reservations.index')",
            $routes,
        );

        $this->assertStringContainsString(
            "Volt::route('/cashier/check-outs', 'cashier.check-outs.index')",
            $routes,
        );

        $this->assertStringContainsString(
            "Volt::route('/maintenance/amenity-requests', 'maintenance.amenity-requests.index')",
            $routes,
        );

        $this->assertStringContainsString(
            "Volt::route('/maintenance/facility-inspections', 'maintenance.facility-inspections.index')",
            $routes,
        );
    }

    public function test_operational_list_foundation_is_presentation_only(): void
    {
        $components = [
            resource_path(
                'views/components/staff-filter-panel.blade.php',
            ),
            resource_path(
                'views/components/staff-table-shell.blade.php',
            ),
        ];

        foreach ($components as $componentPath) {
            $content = file_get_contents(
                $componentPath,
            );

            $this->assertIsString($content);
            $this->assertStringContainsString(
                '@props([',
                $content,
            );
            $this->assertStringContainsString(
                '$attributes->class',
                $content,
            );
            $this->assertStringNotContainsString(
                '::query()',
                $content,
            );
            $this->assertStringNotContainsString(
                'DB::',
                $content,
            );
            $this->assertStringNotContainsString(
                'Auth::',
                $content,
            );
            $this->assertStringNotContainsString(
                '<script',
                $content,
            );
            $this->assertStringNotContainsString(
                '<style',
                $content,
            );
        }
    }

    public function test_selected_operational_pages_share_the_visual_foundation_and_keep_livewire_contracts(): void
    {
        $pages = [
            [
                'path' =>
                    'views/livewire/cashier/reservations/index.blade.php',
                'aliases' => [
                    "#[Url(as: 'q', except: '')]",
                    "#[Url(as: 'status', except: 'Active')]",
                    "#[Url(as: 'sort', except: 'reservation_id')]",
                    "#[Url(as: 'direction', except: 'desc')]",
                    "#[Url(as: 'per_page', except: 10)]",
                ],
                'clearAction' =>
                    'wire:click="clearListFilters"',
                'pagination' =>
                    '$reservations->links()',
                'workflowAction' =>
                    'wire:click="beginReschedule({{ $reservation->reservation_id }})"',
                'statusPresentation' =>
                    '<x-status-badge',
            ],
            [
                'path' =>
                    'views/livewire/cashier/bookings/index.blade.php',
                'aliases' => [
                    "#[Url(as: 'q', except: '')]",
                    "#[Url(as: 'booking_status', except: '')]",
                    "#[Url(as: 'facility_status', except: '')]",
                    "#[Url(as: 'sort', except: 'booking_id')]",
                    "#[Url(as: 'direction', except: 'desc')]",
                    "#[Url(as: 'per_page', except: 10)]",
                ],
                'clearAction' =>
                    'wire:click="clearListFilters"',
                'pagination' =>
                    '$bookings->links()',
                'workflowAction' =>
                    'wire:click="openReschedule({{ $booking->booking_details_id }})"',
                'statusPresentation' =>
                    '<x-status-badge',
            ],
            [
                'path' =>
                    'views/livewire/cashier/check-outs/index.blade.php',
                'aliases' => [
                    "#[Url(as: 'q', except: '')]",
                    "#[Url(as: 'list', except: 'eligible')]",
                    "#[Url(as: 'stage', except: 'all')]",
                    "#[Url(as: 'departure', except: 'all')]",
                    "#[Url(as: 'sort', except: 'check_out_date')]",
                    "#[Url(as: 'direction', except: 'asc')]",
                    "#[Url(as: 'per_page', except: 10)]",
                ],
                'clearAction' =>
                    'wire:click="clearFilters"',
                'pagination' =>
                    '$bookingDetails->links()',
                'workflowAction' =>
                    'wire:click="selectCheckOut({{ $detail->booking_details_id }})"',
                'statusPresentation' =>
                    'workflowStageColor($stage)',
            ],
            [
                'path' =>
                    'views/livewire/maintenance/amenity-requests/index.blade.php',
                'aliases' => [
                    "#[Url(as: 'q', except: '')]",
                    "#[Url(as: 'status', except: '')]",
                    "#[Url(as: 'assignment', except: '')]",
                    "#[Url(as: 'sort', except: 'amenity_request_id')]",
                    "#[Url(as: 'direction', except: 'desc')]",
                    "#[Url(as: 'per_page', except: 10)]",
                ],
                'clearAction' =>
                    'wire:click="clearFilters"',
                'pagination' =>
                    '$requests->links()',
                'workflowAction' =>
                    'wire:confirm="Accept this amenity request for delivery?"',
                'statusPresentation' =>
                    '<x-status-badge',
            ],
            [
                'path' =>
                    'views/livewire/maintenance/facility-inspections/index.blade.php',
                'aliases' => [
                    "#[Url(as: 'q', except: '')]",
                    "#[Url(as: 'status', except: 'active')]",
                    "#[Url(as: 'assignment', except: '')]",
                    "#[Url(as: 'departure', except: 'all')]",
                    "#[Url(as: 'sort', except: 'requested_at')]",
                    "#[Url(as: 'direction', except: 'asc')]",
                    "#[Url(as: 'per_page', except: 10)]",
                ],
                'clearAction' =>
                    'wire:click="clearFilters"',
                'pagination' =>
                    '$inspectionRequests->links()',
                'workflowAction' =>
                    'wire:click="selectRequest({{ $request->facility_inspection_request_id }})"',
                'statusPresentation' =>
                    '<x-status-badge',
            ],
        ];

        foreach ($pages as $page) {
            $content = file_get_contents(
                resource_path(
                    $page['path'],
                ),
            );

            $this->assertIsString($content);

            foreach (
                [
                    '<x-staff-page-header',
                    '<x-staff-table-shell',
                    '<x-staff-filter-panel',
                    '<x-dashboard-empty-state',
                    $page['statusPresentation'],
                    'use Livewire\WithPagination;',
                    'wire:model.live.debounce.300ms="search"',
                    'public function sortBy(string $field): void',
                    '$this->resetPage();',
                    $page['clearAction'],
                    $page['pagination'],
                    $page['workflowAction'],
                ] as $requiredContent
            ) {
                $this->assertStringContainsString(
                    $requiredContent,
                    $content,
                );
            }

            foreach ($page['aliases'] as $alias) {
                $this->assertStringContainsString(
                    $alias,
                    $content,
                );
            }
        }
    }

    public function test_reservation_registry_prepares_its_paginator_outside_blade(): void
    {
        $content = file_get_contents(
            resource_path(
                'views/livewire/cashier/reservations/index.blade.php',
            ),
        );

        $this->assertIsString($content);
        $this->assertStringContainsString(
            'use Illuminate\Pagination\LengthAwarePaginator;',
            $content,
        );
        $this->assertStringContainsString(
            'public function with(): array',
            $content,
        );
        $this->assertStringContainsString(
            "'reservations' => \$this->reservations(),",
            $content,
        );
        $this->assertStringContainsString(
            'public function reservations(): LengthAwarePaginator',
            $content,
        );
        $this->assertStringNotContainsString(
            '@php($reservations = $this->reservations())',
            $content,
        );
    }

    public function test_cashier_checkout_queue_keeps_polling_deep_link_and_workflow_contracts(): void
    {
        $content = file_get_contents(
            resource_path(
                'views/livewire/cashier/check-outs/index.blade.php',
            ),
        );

        $this->assertIsString($content);

        foreach (
            [
                'wire:poll.10s.visible="refreshSelectedState"',
                "\$bookingId = request()->integer('booking');",
                '$checkedInDetails->count() === 1',
                '$this->selectCheckOut((int) $checkedInDetails->first()->booking_details_id);',
                'wire:key="check-out-detail-{{ $detail->booking_details_id }}"',
                "route('cashier.bookings.show', \$detail->booking_id)",
                'wire:click="selectCheckOut({{ $detail->booking_details_id }})"',
                'wire:click="sendInspectionRequest"',
                "route('cashier.payments.index', ['booking' => \$selectedBookingId])",
                '$selectedBookingAmountDue > 0',
                "\$selectedInspectionRequest->status === 'Completed'",
                '$selectedInspection !== null',
                '$selectedBookingAmountDue <= 0',
                'wire:click="confirmCheckOut"',
                'wire:confirm="Confirm this facility check-out?"',
                'CheckOutInspectionRequestService $inspectionRequestService',
                '$inspectionRequestService->requestInspection(',
                'CheckOutWorkflowService $checkOutWorkflow',
                '$checkOutWorkflow->checkOutBookingDetail(',
            ] as $requiredContent
        ) {
            $this->assertStringContainsString(
                $requiredContent,
                $content,
            );
        }

        $this->assertStringNotContainsString(
            'wire:poll.10s="refreshSelectedState"',
            $content,
        );
    }

    public function test_facility_inspection_queue_keeps_polling_deep_link_and_ownership_contracts(): void
    {
        $content = file_get_contents(
            resource_path(
                'views/livewire/maintenance/facility-inspections/index.blade.php',
            ),
        );

        $this->assertIsString($content);

        foreach (
            [
                'wire:poll.15s.visible',
                "\$requestId = request()->integer('request');",
                '$this->selectRequest($requestId);',
                'wire:key="inspection-request-{{ $request->facility_inspection_request_id }}"',
                'Accept & Inspect',
                'Continue',
                'View',
                'assigned_to_user_id',
                'Auth::id()',
                'wire:click="markNoDamage"',
                'wire:click="addFine"',
            ] as $requiredContent
        ) {
            $this->assertStringContainsString(
                $requiredContent,
                $content,
            );
        }

        $this->assertStringNotContainsString(
            'wire:poll.15s>',
            $content,
        );
        $this->assertStringNotContainsString(
            "route('cashier.bookings.show'",
            $content,
        );
    }
}
