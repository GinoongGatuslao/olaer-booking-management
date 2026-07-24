<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$patches = [
    [
        'path' => 'app/Models/BookingDetail.php',
        'replacements' => [
            [
                'old' => <<<'OLD'
    public $timestamps = false;

    protected $fillable = [
OLD,
                'new' => <<<'NEW'
    public $timestamps = false;

    /**
     * Booking stay fields are database DATE columns.
     *
     * Using a date-only persistence format keeps SQLite test storage aligned
     * with MySQL DATE storage instead of serializing casts as midnight
     * datetimes such as 2026-07-29 00:00:00.
     */
    protected $dateFormat = 'Y-m-d';

    protected $fillable = [
NEW,
            ],
        ],
    ],
    [
        'path' => 'resources/views/layouts/auth/simple.blade.php',
        'replacements' => [
            [
                'old' => <<<'OLD'
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
OLD,
                'new' => <<<'NEW'
                <a href="{{ route('guest.home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
NEW,
            ],
        ],
    ],
    [
        'path' => 'resources/views/livewire/cashier/gcash-verifications/index.blade.php',
        'replacements' => [
            [
                'old' => <<<'OLD'
                            Match this reference and amount with the uploaded proof before reviewing.
OLD,
                'new' => <<<'NEW'
                            Match this with the uploaded proof before verifying.
NEW,
            ],
        ],
    ],
    [
        'path' => 'tests/Feature/AmenityRequestStateHardeningTest.php',
        'replacements' => [
            [
                'old' => <<<'OLD'
    public function test_cancel_unpaid_request_blocks_inconsistent_balance(): void
OLD,
                'new' => <<<'NEW'
    public function test_cancel_pending_request_blocks_inconsistent_balance(): void
NEW,
            ],
            [
                'old' => <<<'OLD'
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Awaiting Payment',
            totalPrice: 100.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'booking balance is inconsistent',
        );
OLD,
                'new' => <<<'NEW'
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 100.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'booking balance is inconsistent',
        );
NEW,
            ],
        ],
    ],
    [
        'path' => 'tests/Feature/PaymentTargetStateHardeningTest.php',
        'replacements' => [
            [
                'old' => <<<'OLD'
    public function test_checked_in_booking_payment_releases_paid_amenity_request(): void
OLD,
                'new' => <<<'NEW'
    public function test_checked_in_booking_payment_does_not_control_amenity_request_state(): void
NEW,
            ],
            [
                'old' => <<<'OLD'
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Awaiting Payment',
            totalPrice: 300.00,
        );
OLD,
                'new' => <<<'NEW'
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 300.00,
        );
NEW,
            ],
        ],
    ],
];

$originals = [];
$updatedFiles = [];

foreach ($patches as $patch) {
    $relativePath = $patch['path'];
    $absolutePath = $projectRoot
        .DIRECTORY_SEPARATOR
        .str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath,
        );

    if (! is_file($absolutePath)) {
        fail("Required project file not found: {$relativePath}");
    }

    $content = file_get_contents($absolutePath);

    if ($content === false) {
        fail("Unable to read project file: {$relativePath}");
    }

    $originals[$absolutePath] = $content;
    $updated = $content;

    foreach ($patch['replacements'] as $replacement) {
        $old = $replacement['old'];
        $new = $replacement['new'];

        if (str_contains($updated, $new)) {
            continue;
        }

        $count = substr_count($updated, $old);

        if ($count !== 1) {
            fail(
                "Expected source fragment was not found exactly once in "
                .$relativePath
                .". Found {$count}; no files were changed.",
            );
        }

        $updated = str_replace(
            $old,
            $new,
            $updated,
            $replacementCount,
        );

        if ($replacementCount !== 1) {
            fail(
                "Unable to apply a verified replacement in "
                .$relativePath
                ."; no files were changed.",
            );
        }
    }

    $updatedFiles[$absolutePath] = [
        'relative' => $relativePath,
        'content' => $updated,
    ];
}

foreach ($updatedFiles as $absolutePath => $file) {
    if (
        file_put_contents(
            $absolutePath,
            $file['content'],
        ) === false
    ) {
        rollback($originals);

        fail(
            'Unable to write '
            .$file['relative']
            .'. All modified files were restored.',
        );
    }
}

$lintFailures = [];

foreach ($updatedFiles as $absolutePath => $file) {
    $command = escapeshellarg(PHP_BINARY)
        .' -l '
        .escapeshellarg($absolutePath)
        .' 2>&1';

    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        $lintFailures[] = $file['relative']
            .PHP_EOL
            .implode(PHP_EOL, $output);
    }

    $output = [];
}

if ($lintFailures !== []) {
    rollback($originals);

    fail(
        "PHP syntax validation failed. All modified files were restored."
        .PHP_EOL
        .implode(PHP_EOL.PHP_EOL, $lintFailures),
    );
}

$requiredFinalFragments = [
    'app/Models/BookingDetail.php' =>
        "protected \$dateFormat = 'Y-m-d';",
    'resources/views/layouts/auth/simple.blade.php' =>
        "route('guest.home')",
    'resources/views/livewire/cashier/gcash-verifications/index.blade.php' =>
        'Match this with the uploaded proof before verifying.',
    'tests/Feature/AmenityRequestStateHardeningTest.php' =>
        "test_cancel_pending_request_blocks_inconsistent_balance",
    'tests/Feature/PaymentTargetStateHardeningTest.php' =>
        "test_checked_in_booking_payment_does_not_control_amenity_request_state",
];

foreach ($requiredFinalFragments as $relativePath => $fragment) {
    $absolutePath = $projectRoot
        .DIRECTORY_SEPARATOR
        .str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath,
        );

    $content = file_get_contents($absolutePath);

    if (
        $content === false
        || ! str_contains($content, $fragment)
    ) {
        rollback($originals);

        fail(
            'Post-install verification failed for '
            .$relativePath
            .'. All modified files were restored.',
        );
    }
}

fwrite(
    STDOUT,
    'Full test-suite stabilization phase 1 applied successfully.'
    .PHP_EOL,
);

foreach ($updatedFiles as $file) {
    fwrite(
        STDOUT,
        '- '.$file['relative'].' (syntax passed)'.PHP_EOL,
    );
}

fwrite(
    STDOUT,
    PHP_EOL
    .'Next run:'.PHP_EOL
    .'php artisan optimize:clear'.PHP_EOL
    .'php artisan test --filter=AmenityRequestStateHardeningTest'.PHP_EOL
    .'php artisan test --filter=PaymentTargetStateHardeningTest'.PHP_EOL
    .'php artisan test --filter=PasswordConfirmationTest'.PHP_EOL
    .'php artisan test --filter=PasswordResetTest'.PHP_EOL
    .'php artisan test --filter=CashierGcashVerificationReferenceTest'.PHP_EOL
    .'php artisan test --filter=CheckInAndReservationConversionHardeningTest'.PHP_EOL
    .'php artisan test'.PHP_EOL,
);

function rollback(array $originals): void
{
    foreach ($originals as $absolutePath => $content) {
        file_put_contents($absolutePath, $content);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}
