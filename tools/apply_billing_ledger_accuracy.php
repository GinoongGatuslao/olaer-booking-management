<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$servicePath = $projectRoot
    .DIRECTORY_SEPARATOR
    .'app'
    .DIRECTORY_SEPARATOR
    .'Services'
    .DIRECTORY_SEPARATOR
    .'BillingStatementService.php';

$viewPath = $projectRoot
    .DIRECTORY_SEPARATOR
    .'resources'
    .DIRECTORY_SEPARATOR
    .'views'
    .DIRECTORY_SEPARATOR
    .'livewire'
    .DIRECTORY_SEPARATOR
    .'cashier'
    .DIRECTORY_SEPARATOR
    .'billings'
    .DIRECTORY_SEPARATOR
    .'index.blade.php';

foreach ([$servicePath, $viewPath] as $path) {
    if (! is_file($path)) {
        fail("Required project file not found: {$path}");
    }
}

$originals = [
    $servicePath => file_get_contents($servicePath),
    $viewPath => file_get_contents($viewPath),
];

foreach ($originals as $path => $content) {
    if ($content === false) {
        fail("Unable to read project file: {$path}");
    }
}

$service = $originals[$servicePath];
$view = $originals[$viewPath];

$serviceReplacements = [
    [
        'label' => 'billing summary block',
        'old' => <<<'OLD'
        $summary = [
            'count' => (clone $query)->count(),
            'total_amount' => round(
                (float) (clone $query)->sum('amount'),
                2,
            ),
            'total_due' => round(
                (float) (clone $query)->sum('amount_due'),
                2,
            ),
            'paid_count' => (clone $query)
                ->where('payment_status', 'Paid')
                ->count(),
            'unpaid_count' => (clone $query)
                ->where('payment_status', 'Unpaid')
                ->count(),
        ];
OLD,
        'new' => <<<'NEW'
        $bookingSummaryRows = DB::query()
            ->fromSub(
                clone $query,
                'billing_summary_records',
            )
            ->selectRaw('booking_id')
            ->selectRaw(
                'MAX(booking_total) as booking_total',
            )
            ->selectRaw(
                'MAX(booking_amount_due) as booking_amount_due',
            )
            ->groupBy('booking_id');

        $bookingSummary = DB::query()
            ->fromSub(
                $bookingSummaryRows,
                'billing_booking_summary',
            );

        $summary = [
            'count' => (clone $query)->count(),
            'total_amount' => round(
                (float) (clone $bookingSummary)
                    ->sum('booking_total'),
                2,
            ),
            'total_due' => round(
                (float) (clone $bookingSummary)
                    ->sum('booking_amount_due'),
                2,
            ),
            'paid_count' => (clone $bookingSummary)
                ->where(
                    'booking_amount_due',
                    '<=',
                    0,
                )
                ->count(),
            'unpaid_count' => (clone $bookingSummary)
                ->where(
                    'booking_amount_due',
                    '>',
                    0,
                )
                ->count(),
        ];
NEW,
    ],
    [
        'label' => 'legacy amenity payment state',
        'old' => <<<'OLD'
                    'amount_due' => $request->amenity_request_status === 'Awaiting Payment' ? round((float) $request->total_price, 2) : 0.00,
                    'payment_status' => $request->amenity_request_status === 'Awaiting Payment' ? 'Unpaid' : 'Paid',
OLD,
        'new' => <<<'NEW'
                    'amount_due' => round(
                        (float) (
                            $request->booking?->amount_due
                            ?? 0
                        ),
                        2,
                    ),
                    'payment_status' => round(
                        (float) (
                            $request->booking?->amount_due
                            ?? 0
                        ),
                        2,
                    ) <= 0
                        ? 'Paid'
                        : 'Unpaid',
NEW,
    ],
    [
        'label' => 'legacy fine payment state',
        'old' => <<<'OLD'
                    'amount_due' => round((float) ($guestFine->booking?->amount_due ?? 0), 2) > 0 ? round((float) $guestFine->total_charge, 2) : 0.00,
                    'payment_status' => round((float) ($guestFine->booking?->amount_due ?? 0), 2) <= 0 ? 'Paid' : 'Unpaid',
OLD,
        'new' => <<<'NEW'
                    'amount_due' => round(
                        (float) (
                            $guestFine->booking?->amount_due
                            ?? 0
                        ),
                        2,
                    ),
                    'payment_status' => round(
                        (float) (
                            $guestFine->booking?->amount_due
                            ?? 0
                        ),
                        2,
                    ) <= 0
                        ? 'Paid'
                        : 'Unpaid',
NEW,
    ],
    [
        'label' => 'booking ledger summary columns',
        'old' => <<<'OLD'
            ->selectRaw(
                "CASE
                    WHEN billing_booking.amount_due <= 0
                        THEN 'Paid'
                    ELSE 'Unpaid'
                END as payment_status"
            );
OLD,
        'new' => <<<'NEW'
            ->selectRaw(
                "CASE
                    WHEN billing_booking.amount_due <= 0
                        THEN 'Paid'
                    ELSE 'Unpaid'
                END as payment_status"
            )
            ->selectRaw(
                'billing_booking.total_price as booking_total'
            )
            ->selectRaw(
                'billing_booking.amount_due as booking_amount_due'
            );
NEW,
    ],
    [
        'label' => 'amenity ledger payment state',
        'old' => <<<'OLD'
            ->selectRaw(
                "CASE
                    WHEN billing_amenity_request.amenity_request_status
                        = 'Awaiting Payment'
                    THEN billing_amenity_request.total_price
                    ELSE 0
                END as amount_due"
            )
            ->selectRaw(
                "CASE
                    WHEN billing_amenity_request.amenity_request_status
                        = 'Awaiting Payment'
                    THEN 'Unpaid'
                    ELSE 'Paid'
                END as payment_status"
            );
OLD,
        'new' => <<<'NEW'
            ->selectRaw(
                'amenity_booking.amount_due as amount_due'
            )
            ->selectRaw(
                "CASE
                    WHEN amenity_booking.amount_due <= 0
                    THEN 'Paid'
                    ELSE 'Unpaid'
                END as payment_status"
            )
            ->selectRaw(
                'amenity_booking.total_price as booking_total'
            )
            ->selectRaw(
                'amenity_booking.amount_due as booking_amount_due'
            );
NEW,
    ],
    [
        'label' => 'fine ledger payment state',
        'old' => <<<'OLD'
            ->selectRaw(
                "CASE
                    WHEN fine_booking.amount_due > 0
                    THEN billing_guest_fine.total_charge
                    ELSE 0
                END as amount_due"
            )
            ->selectRaw(
                "CASE
                    WHEN fine_booking.amount_due <= 0
                    THEN 'Paid'
                    ELSE 'Unpaid'
                END as payment_status"
            );
OLD,
        'new' => <<<'NEW'
            ->selectRaw(
                'fine_booking.amount_due as amount_due'
            )
            ->selectRaw(
                "CASE
                    WHEN fine_booking.amount_due <= 0
                    THEN 'Paid'
                    ELSE 'Unpaid'
                END as payment_status"
            )
            ->selectRaw(
                'fine_booking.total_price as booking_total'
            )
            ->selectRaw(
                'fine_booking.amount_due as booking_amount_due'
            );
NEW,
    ],
];

foreach ($serviceReplacements as $replacement) {
    $service = replaceOnceOrConfirmApplied(
        $service,
        $replacement['old'],
        $replacement['new'],
        $replacement['label'],
    );
}

$viewReplacements = [
    [
        'old' =>
            'View billed bookings, amenity requests, and fines. Print a statement only after checking the booking balance.',
        'new' =>
            'Review itemized charges while using the booking-wide balance as the settlement source of truth.',
        'label' => 'billing page explanation',
    ],
    [
        'old' => '>Billed Amount<',
        'new' => '>Booking Charges<',
        'label' => 'booking charges card label',
    ],
    [
        'old' => '>Outstanding<',
        'new' => '>Outstanding Balance<',
        'label' => 'outstanding balance card label',
    ],
    [
        'old' => '>Paid Records<',
        'new' => '>Paid Bookings<',
        'label' => 'paid bookings card label',
    ],
    [
        'old' => '>Unpaid Records<',
        'new' => '>Outstanding Bookings<',
        'label' => 'outstanding bookings card label',
    ],
    [
        'old' =>
            "Due {{ \$this->sortIndicator('amount_due') }}",
        'new' =>
            "Booking Due {{ \$this->sortIndicator('amount_due') }}",
        'label' => 'booking due table heading',
    ],
];

foreach ($viewReplacements as $replacement) {
    $view = replaceOnceOrConfirmApplied(
        $view,
        $replacement['old'],
        $replacement['new'],
        $replacement['label'],
    );
}

$updated = [
    $servicePath => $service,
    $viewPath => $view,
];

foreach ($updated as $path => $content) {
    if (file_put_contents($path, $content) === false) {
        rollback($originals);

        fail(
            "Unable to write {$path}. "
            .'All modified files were restored.',
        );
    }
}

foreach (array_keys($updated) as $path) {
    $command = escapeshellarg(PHP_BINARY)
        .' -l '
        .escapeshellarg($path)
        .' 2>&1';

    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        rollback($originals);

        fail(
            "PHP syntax validation failed for {$path}. "
            .'All modified files were restored.'
            .PHP_EOL
            .implode(PHP_EOL, $output),
        );
    }

    $output = [];
}

$verification = [
    $servicePath => [
        'billing_booking_summary',
        'booking_amount_due',
        'amenity_booking.amount_due as amount_due',
        'fine_booking.amount_due as amount_due',
    ],
    $viewPath => [
        'Booking Charges',
        'Outstanding Balance',
        'Paid Bookings',
        'Outstanding Bookings',
        'Booking Due',
    ],
];

foreach ($verification as $path => $fragments) {
    $content = file_get_contents($path);

    foreach ($fragments as $fragment) {
        if (
            $content === false
            || ! str_contains($content, $fragment)
        ) {
            rollback($originals);

            fail(
                "Post-install verification failed for {$path}. "
                .'All modified files were restored.',
            );
        }
    }
}

fwrite(
    STDOUT,
    'Billing ledger accuracy update applied successfully.'
    .PHP_EOL
    .'- app/Services/BillingStatementService.php'
    .PHP_EOL
    .'- resources/views/livewire/cashier/billings/index.blade.php'
    .PHP_EOL,
);

function replaceOnceOrConfirmApplied(
    string $content,
    string $old,
    string $new,
    string $label,
): string {
    if (str_contains($content, $new)) {
        return $content;
    }

    $count = substr_count($content, $old);

    if ($count !== 1) {
        fail(
            "{$label} was expected exactly once, "
            ."but {$count} occurrence(s) were found. "
            .'No files were changed.',
        );
    }

    return str_replace(
        $old,
        $new,
        $content,
        $replacementCount,
    );
}

function rollback(array $originals): void
{
    foreach ($originals as $path => $content) {
        file_put_contents($path, $content);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}
