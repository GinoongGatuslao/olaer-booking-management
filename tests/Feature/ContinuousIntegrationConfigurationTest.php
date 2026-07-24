<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContinuousIntegrationConfigurationTest extends TestCase
{
    public function test_laravel_ci_workflow_contains_required_quality_gates(): void
    {
        $path = base_path(
            '.github/workflows/laravel-ci.yml',
        );

        $this->assertFileExists($path);

        $content = file_get_contents($path);

        $this->assertIsString($content);

        foreach ([
            'push:',
            'pull_request:',
            'workflow_dispatch:',
            'permissions:',
            'contents: read',
            "php-version: '8.3'",
            "node-version: '22'",
            'composer install --no-interaction',
            'npm ci',
            'npm run build',
            'php artisan migrate:fresh',
            'php artisan route:list',
            'php artisan schedule:list',
            'php artisan test --colors=always',
        ] as $requiredFragment) {
            $this->assertStringContainsString(
                $requiredFragment,
                $content,
            );
        }
    }

    public function test_ci_workflow_uses_safe_pull_request_permissions(): void
    {
        $content = file_get_contents(
            base_path(
                '.github/workflows/laravel-ci.yml',
            ),
        );

        $this->assertIsString($content);

        $this->assertStringNotContainsString(
            'pull_request_target:',
            $content,
        );

        $this->assertStringNotContainsString(
            'secrets.',
            $content,
        );

        $this->assertStringNotContainsString(
            'contents: write',
            $content,
        );

        $this->assertStringContainsString(
            'cancel-in-progress: true',
            $content,
        );
    }

    public function test_ci_uses_sqlite_without_changing_the_project_test_configuration(): void
    {
        $workflow = file_get_contents(
            base_path(
                '.github/workflows/laravel-ci.yml',
            ),
        );

        $phpunit = file_get_contents(
            base_path('phpunit.xml'),
        );

        $this->assertIsString($workflow);
        $this->assertIsString($phpunit);

        $this->assertStringContainsString(
            'DB_CONNECTION=sqlite',
            $workflow,
        );

        $this->assertStringContainsString(
            'database/ci.sqlite',
            $workflow,
        );

        $this->assertStringContainsString(
            '<env name="DB_CONNECTION" value="sqlite"/>',
            $phpunit,
        );

        $this->assertStringContainsString(
            '<env name="DB_DATABASE" value=":memory:"/>',
            $phpunit,
        );
    }
}
