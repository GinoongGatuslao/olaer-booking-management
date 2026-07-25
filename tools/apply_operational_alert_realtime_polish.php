<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$targets = [
    'resources/views/livewire/cashier/action-center/index.blade.php',
    'resources/views/livewire/cashier/notifications/index.blade.php',
    'resources/views/livewire/maintenance/action-center/index.blade.php',
    'resources/views/livewire/maintenance/notifications/index.blade.php',
    'README.md',
];

$originals = [];

foreach ($targets as $relativePath) {
    $absolutePath = path($root, $relativePath);

    if (! is_file($absolutePath)) {
        fail(
            "Required project file not found: {$relativePath}",
        );
    }

    $content = file_get_contents($absolutePath);

    if ($content === false) {
        fail(
            "Unable to read project file: {$relativePath}",
        );
    }

    $originals[$absolutePath] = $content;
}

$updates = [];

$cashierAction = $originals[
    path(
        $root,
        'resources/views/livewire/cashier/action-center/index.blade.php',
    )
];

$cashierAction = replaceOnceOrConfirmApplied(
    $cashierAction,
    '<div wire:poll.10s="refreshActionCenter" class="space-y-6">',
    '<div wire:poll.10s.visible="refreshActionCenter" class="space-y-6">',
    'Cashier Action Center visible polling',
);

$updates[
    path(
        $root,
        'resources/views/livewire/cashier/action-center/index.blade.php',
    )
] = $cashierAction;

$cashierNotifications = $originals[
    path(
        $root,
        'resources/views/livewire/cashier/notifications/index.blade.php',
    )
];

$cashierNotifications = replaceOnceOrConfirmApplied(
    $cashierNotifications,
    '<div class="space-y-6">',
    '<div wire:poll.15s.visible="refreshAlerts" class="space-y-6">',
    'Cashier Notifications polling',
);

$cashierNotifications = replaceOnceOrConfirmApplied(
    $cashierNotifications,
    'Live alerts for pending GCash proofs, upcoming bookings, cottage end-time reminders, and unpaid check-out balances.',
    'Live alerts for pending GCash proofs, upcoming bookings, cottage end-time reminders, and unpaid check-out balances. This page refreshes automatically while visible.',
    'Cashier Notifications polling explanation',
);

$updates[
    path(
        $root,
        'resources/views/livewire/cashier/notifications/index.blade.php',
    )
] = $cashierNotifications;

$maintenanceAction = $originals[
    path(
        $root,
        'resources/views/livewire/maintenance/action-center/index.blade.php',
    )
];

$maintenanceAction = replaceOnceOrConfirmApplied(
    $maintenanceAction,
    '<div class="space-y-6" wire:poll.10s>',
    '<div class="space-y-6" wire:poll.10s.visible>',
    'Maintenance Action Center visible polling',
);

$updates[
    path(
        $root,
        'resources/views/livewire/maintenance/action-center/index.blade.php',
    )
] = $maintenanceAction;

$maintenanceNotifications = $originals[
    path(
        $root,
        'resources/views/livewire/maintenance/notifications/index.blade.php',
    )
];

$maintenanceNotifications = replaceOnceOrConfirmApplied(
    $maintenanceNotifications,
    '<div class="space-y-6">',
    '<div wire:poll.15s.visible="refreshAlerts" class="space-y-6">',
    'Maintenance Notifications polling',
);

$maintenanceNotifications = replaceOnceOrConfirmApplied(
    $maintenanceNotifications,
    'Live alerts for pending amenity requests and checked-in facilities that still need inspection.',
    'Live alerts for pending amenity requests and cashier-created checkout inspection requests. This page refreshes automatically while visible.',
    'Maintenance Notifications scope explanation',
);

$maintenanceNotifications = replaceOnceOrConfirmApplied(
    $maintenanceNotifications,
    "where('type', 'inspection_needed')",
    "where('type', 'inspection_request')",
    'Maintenance inspection alert counter',
);

$maintenanceNotifications = replaceOnceOrConfirmApplied(
    $maintenanceNotifications,
    'Pending amenity requests appear after payment is completed. Inspection alerts disappear once maintenance records the facility checklist.',
    'Pending amenity requests appear immediately and may be delivered before payment; their charges remain on the booking bill. Inspection alerts appear only after Cashier sends a checkout inspection request.',
    'Maintenance notification business-rule note',
);

$updates[
    path(
        $root,
        'resources/views/livewire/maintenance/notifications/index.blade.php',
    )
] = $maintenanceNotifications;

$readmePath = path($root, 'README.md');
$readme = $originals[$readmePath];

$readme = replaceOnceOrConfirmApplied(
    $readme,
    '- Accept paid amenity delivery requests.',
    '- Accept pending amenity delivery requests without requiring advance payment.',
    'README Maintenance amenity responsibility',
);

$readme = replaceOnceOrConfirmApplied(
    $readme,
    '- Cancellation of paid amenity requests.',
    '- Refund processing or cancellation after an amenity has been delivered or settled.',
    'README amenity refund scope',
);

$oldAmenityRule = <<<'OLD'
```text
Cashier creates request
→ charge added to booking
→ Awaiting Payment
→ booking balance reaches zero
→ Pending
→ Maintenance accepts
→ Delivering
→ assigned staff delivers
→ Delivered
```

Only rentable amenities with a valid positive price can be requested.

An unpaid amenity request may be edited or cancelled. Once paid and released to Maintenance, cancellation is blocked because refunds are out of scope.
OLD;

$newAmenityRule = <<<'NEW'
```text
Cashier creates request
→ charge added to booking total and amount due
→ Pending
→ Maintenance accepts
→ Delivering
→ assigned staff delivers
→ Delivered
→ charge is settled during checkout or final billing
```

Only rentable amenities with a valid positive price can be requested.

A pending request may be edited or cancelled before Maintenance accepts it. Delivery does not require advance payment. Once assigned, delivering, or delivered, cancellation is blocked because refund processing is out of scope.
NEW;

$readme = replaceOnceOrConfirmApplied(
    $readme,
    $oldAmenityRule,
    $newAmenityRule,
    'README canonical amenity rule',
);

$oldAmenityFlow = <<<'OLD'
```mermaid
flowchart TD
    A[Confirmed booking with zero balance] --> B[Cashier checks in facility]
    B --> C[Facility becomes Occupied]
    C --> D[Guest requests rentable amenity]
    D --> E[Cashier creates billable request]
    E --> F[Charge added to booking balance]
    F --> G[Request: Awaiting Payment]
    G --> H[Cashier records payment]
    H --> I[Booking balance reaches zero]
    I --> J[Request: Pending]
    J --> K[Maintenance accepts]
    K --> L[Request: Delivering]
    L --> M[Assigned maintenance staff delivers]
    M --> N[Request: Delivered]
```
OLD;

$newAmenityFlow = <<<'NEW'
```mermaid
flowchart TD
    A[Confirmed booking with zero balance] --> B[Cashier checks in facility]
    B --> C[Facility becomes Occupied]
    C --> D[Guest requests rentable amenity]
    D --> E[Cashier creates billable request]
    E --> F[Charge added to booking total and amount due]
    F --> G[Request: Pending]
    G --> H[Maintenance accepts]
    H --> I[Request: Delivering]
    I --> J[Assigned maintenance staff delivers]
    J --> K[Request: Delivered]
    K --> L[Cashier settles amenity charge during final billing]
```
NEW;

$readme = replaceOnceOrConfirmApplied(
    $readme,
    $oldAmenityFlow,
    $newAmenityFlow,
    'README amenity delivery flow',
);

$oldLifecycle = <<<'OLD'
```text
Awaiting Payment
├── Cancelled
└── Pending
    └── Delivering
        └── Delivered
```
OLD;

$newLifecycle = <<<'NEW'
```text
Pending
├── Cancelled
└── Delivering
    └── Delivered
```
NEW;

$readme = replaceOnceOrConfirmApplied(
    $readme,
    $oldLifecycle,
    $newLifecycle,
    'README amenity status lifecycle',
);

$updates[$readmePath] = $readme;

foreach ($updates as $absolutePath => $content) {
    if (
        file_put_contents(
            $absolutePath,
            $content,
        ) === false
    ) {
        rollback($originals);

        fail(
            "Unable to write {$absolutePath}. "
            .'All modified files were restored.',
        );
    }
}

foreach (array_keys($updates) as $absolutePath) {
    if (
        ! str_ends_with(
            $absolutePath,
            '.php',
        )
        && ! str_ends_with(
            $absolutePath,
            '.blade.php',
        )
    ) {
        continue;
    }

    $command = escapeshellarg(PHP_BINARY)
        .' -l '
        .escapeshellarg($absolutePath)
        .' 2>&1';

    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        rollback($originals);

        fail(
            "PHP syntax validation failed for {$absolutePath}. "
            .'All modified files were restored.'
            .PHP_EOL
            .implode(PHP_EOL, $output),
        );
    }

    $output = [];
}

$verification = [
    path(
        $root,
        'resources/views/livewire/cashier/action-center/index.blade.php',
    ) => [
        'wire:poll.10s.visible="refreshActionCenter"',
    ],
    path(
        $root,
        'resources/views/livewire/cashier/notifications/index.blade.php',
    ) => [
        'wire:poll.15s.visible="refreshAlerts"',
    ],
    path(
        $root,
        'resources/views/livewire/maintenance/action-center/index.blade.php',
    ) => [
        'wire:poll.10s.visible',
    ],
    path(
        $root,
        'resources/views/livewire/maintenance/notifications/index.blade.php',
    ) => [
        'wire:poll.15s.visible="refreshAlerts"',
        "where('type', 'inspection_request')",
        'may be delivered before payment',
        'only after Cashier sends',
    ],
    $readmePath => [
        'charge is settled during checkout or final billing',
        'Delivery does not require advance payment.',
    ],
];

foreach ($verification as $absolutePath => $fragments) {
    $content = file_get_contents($absolutePath);

    foreach ($fragments as $fragment) {
        if (
            $content === false
            || ! str_contains(
                $content,
                $fragment,
            )
        ) {
            rollback($originals);

            fail(
                "Post-install verification failed for {$absolutePath}. "
                .'All modified files were restored.',
            );
        }
    }
}

if (
    str_contains(
        file_get_contents($readmePath) ?: '',
        'Awaiting Payment',
    )
) {
    rollback($originals);

    fail(
        'README still contains the obsolete Awaiting Payment amenity state. '
        .'All modified files were restored.',
    );
}

fwrite(
    STDOUT,
    'Operational alert real-time polish applied successfully.'
    .PHP_EOL,
);

foreach (array_keys($updates) as $absolutePath) {
    fwrite(
        STDOUT,
        '- '.relative($root, $absolutePath).PHP_EOL,
    );
}

function path(
    string $root,
    string $relativePath,
): string {
    return $root
        .DIRECTORY_SEPARATOR
        .str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath,
        );
}

function relative(
    string $root,
    string $absolutePath,
): string {
    return str_replace(
        DIRECTORY_SEPARATOR,
        '/',
        ltrim(
            str_replace(
                $root,
                '',
                $absolutePath,
            ),
            DIRECTORY_SEPARATOR,
        ),
    );
}

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
    foreach ($originals as $absolutePath => $content) {
        file_put_contents(
            $absolutePath,
            $content,
        );
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}
