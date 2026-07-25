<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$reportsServicePath = $projectRoot
    .DIRECTORY_SEPARATOR
    .'app'
    .DIRECTORY_SEPARATOR
    .'Services'
    .DIRECTORY_SEPARATOR
    .'ReportsService.php';

$reportOutputPath = $projectRoot
    .DIRECTORY_SEPARATOR
    .'resources'
    .DIRECTORY_SEPARATOR
    .'views'
    .DIRECTORY_SEPARATOR
    .'livewire'
    .DIRECTORY_SEPARATOR
    .'reports'
    .DIRECTORY_SEPARATOR
    .'partials'
    .DIRECTORY_SEPARATOR
    .'report-output.blade.php';

$targets = [
    $reportsServicePath,
    $reportOutputPath,
];

foreach ($targets as $target) {
    if (! is_file($target)) {
        fail("Required project file not found: {$target}");
    }
}

$originals = [
    $reportsServicePath =>
        file_get_contents($reportsServicePath),
    $reportOutputPath =>
        file_get_contents($reportOutputPath),
];

foreach ($originals as $path => $content) {
    if ($content === false) {
        fail("Unable to read project file: {$path}");
    }
}

$reportsContent = $originals[$reportsServicePath];
$outputContent = $originals[$reportOutputPath];

$newVerifiedFilter = <<<'PHP'
            ->whereRaw(
                'LOWER(payment_status) = ?',
                ['verified'],
            );
PHP;

if (! str_contains($reportsContent, $newVerifiedFilter)) {
    $oldVerifiedFilter = <<<'PHP'
            ->where('payment_status', 'Verified');
PHP;

    assertExactOccurrence(
        $reportsContent,
        $oldVerifiedFilter,
        1,
        'Revenue Verified-payment filter',
    );

    $reportsContent = str_replace(
        $oldVerifiedFilter,
        $newVerifiedFilter,
        $reportsContent,
        $count,
    );

    if ($count !== 1) {
        fail(
            'Unable to normalize the revenue Verified-payment filter.',
        );
    }
}

$newConstructor = <<<'PHP'
class ReportsService
{
    public function __construct(
        private readonly FinancialReportMetricsService $financialMetrics,
    ) {}
PHP;

if (! str_contains($reportsContent, $newConstructor)) {
    $oldConstructor = <<<'PHP'
class ReportsService
{
PHP;

    assertExactOccurrence(
        $reportsContent,
        $oldConstructor,
        1,
        'ReportsService class declaration',
    );

    $reportsContent = str_replace(
        $oldConstructor,
        $newConstructor,
        $reportsContent,
        $count,
    );

    if ($count !== 1) {
        fail(
            'Unable to inject FinancialReportMetricsService into ReportsService.',
        );
    }
}

$newRevenuePreparation = <<<'PHP'
            ->orderBy('date_paid')
            ->orderBy('payment_id');

        $financialMetrics =
            $this->financialMetrics->summary(
                $startDate,
                $endDate,
                $cashierUserId,
            );

        $dailyRevenue =
            $this->financialMetrics
                ->dailyVerifiedRevenue(
                    $startDate,
                    $endDate,
                    $cashierUserId,
                );

        return [
PHP;

if (! str_contains($reportsContent, $newRevenuePreparation)) {
    $oldRevenuePreparation = <<<'PHP'
            ->orderBy('date_paid')
            ->orderBy('payment_id');

        return [
PHP;

    assertExactOccurrence(
        $reportsContent,
        $oldRevenuePreparation,
        1,
        'Revenue report preparation block',
    );

    $reportsContent = str_replace(
        $oldRevenuePreparation,
        $newRevenuePreparation,
        $reportsContent,
        $count,
    );

    if ($count !== 1) {
        fail(
            'Unable to add canonical financial metrics to the revenue report.',
        );
    }
}

$newRevenueReturn = <<<'PHP'
            'total' => $total,
            'by_mode' => $byMode,
            'count' => $count,
            'financial_metrics' =>
                $financialMetrics,
            'daily_revenue' => $dailyRevenue,
            'show_outstanding_metrics' =>
                $cashierUserId === null,
        ];
    }

    public function bookingSummaryReport(
PHP;

if (! str_contains($reportsContent, $newRevenueReturn)) {
    $oldRevenueReturn = <<<'PHP'
            'total' => $total,
            'by_mode' => $byMode,
            'count' => $count,
        ];
    }

    public function bookingSummaryReport(
PHP;

    assertExactOccurrence(
        $reportsContent,
        $oldRevenueReturn,
        1,
        'Revenue report return block',
    );

    $reportsContent = str_replace(
        $oldRevenueReturn,
        $newRevenueReturn,
        $reportsContent,
        $count,
    );

    if ($count !== 1) {
        fail(
            'Unable to expose canonical metrics from ReportsService.',
        );
    }
}

$newRevenueView = <<<'BLADE'
    @if ($reportType === 'revenue')
        @php
            $metrics = $report['financial_metrics'] ?? [];
            $showOutstandingMetrics =
                (bool) ($report['show_outstanding_metrics'] ?? false);
        @endphp

        <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Verified Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['verified_revenue'] ?? $report['total']) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Verified Payments</p>
                <p class="text-xl font-bold">
                    {{ $metrics['verified_payment_count'] ?? $report['count'] }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Booking Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['booking_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Reservation Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['reservation_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Entrance Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['entrance_revenue'] ?? 0) }}
                </p>
            </div>
        </div>

        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Cash</p>
                <p class="text-lg font-semibold">
                    {{ $this->money($metrics['cash_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">GCash</p>
                <p class="text-lg font-semibold">
                    {{ $this->money($metrics['gcash_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Other Modes</p>
                <p class="text-lg font-semibold">
                    {{ $this->money($metrics['other_mode_revenue'] ?? 0) }}
                </p>
            </div>
        </div>

        @if ($showOutstandingMetrics)
            <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        Booking Balance
                    </p>
                    <p class="text-lg font-semibold">
                        {{ $this->money($metrics['outstanding_booking_balance'] ?? 0) }}
                    </p>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        Reservation Balance
                    </p>
                    <p class="text-lg font-semibold">
                        {{ $this->money($metrics['outstanding_reservation_balance'] ?? 0) }}
                    </p>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        Entrance Balance
                    </p>
                    <p class="text-lg font-semibold">
                        {{ $this->money($metrics['outstanding_entrance_balance'] ?? 0) }}
                    </p>
                </div>

                <div class="rounded-lg border border-amber-300 bg-amber-100 p-4 dark:border-amber-800 dark:bg-amber-950/50">
                    <p class="text-xs font-medium text-amber-800 dark:text-amber-200">
                        Total Outstanding
                    </p>
                    <p class="text-lg font-bold">
                        {{ $this->money($metrics['total_outstanding_balance'] ?? 0) }}
                    </p>
                </div>
            </div>
        @endif

        @if (trim($reportSearch) !== '')
            <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                The summary cards cover all Verified payments in the selected date range.
                The table search currently matches {{ $report['count'] }} payment(s)
                totaling {{ $this->money($report['total']) }}.
            </p>
        @endif

        <div class="overflow-x-auto">
BLADE;

if (! str_contains($outputContent, $newRevenueView)) {
    $oldRevenueView = <<<'BLADE'
    @if ($reportType === 'revenue')
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Total Revenue</p>
                <p class="text-xl font-bold">{{ $this->money($report['total']) }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Payment Count</p>
                <p class="text-xl font-bold">{{ $report['count'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Payment Modes</p>

                <p class="text-sm">
                    @forelse ($report['by_mode'] as $mode => $amount)
                        {{ $mode }}: {{ $this->money($amount) }}@if (! $loop->last), @endif
                    @empty
                        No verified payment
                    @endforelse
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
BLADE;

    assertExactOccurrence(
        $outputContent,
        $oldRevenueView,
        1,
        'Revenue report summary view',
    );

    $outputContent = str_replace(
        $oldRevenueView,
        $newRevenueView,
        $outputContent,
        $count,
    );

    if ($count !== 1) {
        fail(
            'Unable to update the shared revenue summary view.',
        );
    }
}

$updatedFiles = [
    $reportsServicePath => $reportsContent,
    $reportOutputPath => $outputContent,
];

foreach ($updatedFiles as $path => $content) {
    if (file_put_contents($path, $content) === false) {
        rollback($originals);

        fail(
            "Unable to write {$path}. All modified files were restored.",
        );
    }
}

foreach (array_keys($updatedFiles) as $path) {
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
    $reportsServicePath => [
        'FinancialReportMetricsService $financialMetrics',
        "LOWER(payment_status) = ?",
        "'financial_metrics' =>",
        "'daily_revenue' =>",
        "'show_outstanding_metrics' =>",
    ],
    $reportOutputPath => [
        'Verified Revenue',
        'Booking Revenue',
        'Total Outstanding',
        'The summary cards cover all Verified payments',
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
    'Financial metrics report integration applied successfully.'
    .PHP_EOL
    .'- app/Services/ReportsService.php'.PHP_EOL
    .'- resources/views/livewire/reports/partials/report-output.blade.php'
    .PHP_EOL,
);

function assertExactOccurrence(
    string $content,
    string $fragment,
    int $expected,
    string $label,
): void {
    $count = substr_count($content, $fragment);

    if ($count !== $expected) {
        fail(
            "{$label} was expected {$expected} time(s), found {$count}. "
            .'No files were changed.',
        );
    }
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
