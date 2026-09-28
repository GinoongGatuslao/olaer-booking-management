<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequiredFieldMarkerVisualTest extends TestCase
{
    public function test_required_field_components_render_red_asterisks_and_semantic_required_controls(): void
    {
        foreach ([
            'resources/views/components/required-input.blade.php',
            'resources/views/components/required-select.blade.php',
            'resources/views/components/required-textarea.blade.php',
        ] as $relativePath) {
            $content = file_get_contents(base_path($relativePath));

            $this->assertIsString($content);
            $this->assertStringContainsString('text-red-600', $content);
            $this->assertStringContainsString('aria-hidden="true">*</span>', $content);
            $this->assertStringContainsString('required', $content);
            $this->assertStringContainsString('<flux:error', $content);
        }
    }

    public function test_primary_create_workflows_use_required_field_components_instead_of_plain_label_asterisks(): void
    {
        foreach ([
            'resources/views/livewire/guest/facilities/planner.blade.php',
            'resources/views/livewire/cashier/reservations/create.blade.php',
            'resources/views/livewire/cashier/bookings/create.blade.php',
            'resources/views/livewire/admin/facilities/create.blade.php',
            'resources/views/livewire/security/entrance-slips/create.blade.php',
        ] as $relativePath) {
            $content = file_get_contents(base_path($relativePath));

            $this->assertIsString($content);
            $this->assertStringContainsString('x-required-', $content);
            $this->assertDoesNotMatchRegularExpression(
                '/label="[^"]*\*"/',
                $content,
            );
        }
    }
}
