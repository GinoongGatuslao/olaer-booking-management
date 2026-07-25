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
            "Volt::route('/maintenance/amenity-requests', 'maintenance.amenity-requests.index')",
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
                    '<x-status-badge',
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
}
