<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$targetPath = $projectRoot
    .DIRECTORY_SEPARATOR
    .'resources'
    .DIRECTORY_SEPARATOR
    .'views'
    .DIRECTORY_SEPARATOR
    .'livewire'
    .DIRECTORY_SEPARATOR
    .'cashier'
    .DIRECTORY_SEPARATOR
    .'reservations'
    .DIRECTORY_SEPARATOR
    .'index.blade.php';

if (! is_file($targetPath)) {
    fwrite(
        STDERR,
        "Target file not found: {$targetPath}".PHP_EOL,
    );

    exit(1);
}

$content = file_get_contents($targetPath);

if ($content === false) {
    fwrite(
        STDERR,
        "Unable to read: {$targetPath}".PHP_EOL,
    );

    exit(1);
}

$newImport = <<<'PHP'
use App\Services\StaffReservationCancellationService;
PHP;

$newSignature = <<<'PHP'
    public function cancelReservation(
        StaffReservationCancellationService $workflow,
    ): void {
PHP;

if (
    str_contains($content, $newImport)
    && str_contains($content, $newSignature)
) {
    fwrite(
        STDOUT,
        'Cashier reservation cancellation integration is already applied.'
        .PHP_EOL,
    );

    exit(0);
}

$oldImport = <<<'PHP'
use App\Services\ReservationQuoteService;
PHP;

$replacementImport = <<<'PHP'
use App\Services\ReservationQuoteService;
use App\Services\StaffReservationCancellationService;
PHP;

$oldMethod = <<<'PHP'
    public function cancelReservation(): void
    {
        $validated = $this->validate([
            'cancelReservationId' => ['required', 'exists:tbl_reservation,reservation_id'],
            'cancellationReason' => ['required', 'string', 'max:255'],
        ]);

        $reservation = Reservation::query()->findOrFail((int) $validated['cancelReservationId']);

        if ($reservation->status !== 'Active') {
            session()->flash('error', 'Only active reservations can be cancelled.');
            return;
        }

        $reservation->update([
            'status' => 'Cancelled',
            'amount_due' => 0,
            'cancellation_reason' => trim($validated['cancellationReason']),
            'cancelled_at' => now()->toDateString(),
        ]);

        $this->cancelReservationId = null;
        $this->cancellationReason = '';
        session()->flash('success', 'Reservation cancelled successfully.');
    }
PHP;

$newMethod = <<<'PHP'
    public function cancelReservation(
        StaffReservationCancellationService $workflow,
    ): void {
        $validated = $this->validate([
            'cancelReservationId' => [
                'required',
                'integer',
                'exists:tbl_reservation,reservation_id',
            ],
            'cancellationReason' => [
                'required',
                'string',
                'max:500',
            ],
        ]);

        try {
            $workflow->cancel(
                (int) $validated['cancelReservationId'],
                (string) $validated['cancellationReason'],
                (int) auth()->id(),
            );

            $this->cancelReservationId = null;
            $this->cancellationReason = '';

            session()->flash(
                'success',
                'Reservation cancelled successfully.',
            );
        } catch (\Throwable $exception) {
            session()->flash(
                'error',
                $exception->getMessage(),
            );
        }
    }
PHP;

if (substr_count($content, $oldImport) !== 1) {
    fwrite(
        STDERR,
        'Expected ReservationQuoteService import was not found exactly once.'
        .PHP_EOL,
    );

    exit(1);
}

if (substr_count($content, $oldMethod) !== 1) {
    fwrite(
        STDERR,
        'Expected legacy cancellation method was not found exactly once. '
        .'The file may have changed; no modification was made.'
        .PHP_EOL,
    );

    exit(1);
}

$updated = str_replace(
    $oldImport,
    $replacementImport,
    $content,
    $importReplacements,
);

$updated = str_replace(
    $oldMethod,
    $newMethod,
    $updated,
    $methodReplacements,
);

if (
    $importReplacements !== 1
    || $methodReplacements !== 1
) {
    fwrite(
        STDERR,
        'Unable to apply the cancellation integration safely.'
        .PHP_EOL,
    );

    exit(1);
}

if (file_put_contents($targetPath, $updated) === false) {
    fwrite(
        STDERR,
        "Unable to write: {$targetPath}".PHP_EOL,
    );

    exit(1);
}

$command = escapeshellarg(PHP_BINARY)
    .' -l '
    .escapeshellarg($targetPath)
    .' 2>&1';

exec($command, $syntaxOutput, $syntaxCode);

if ($syntaxCode !== 0) {
    file_put_contents($targetPath, $content);

    fwrite(
        STDERR,
        'The integration produced invalid PHP and was rolled back.'
        .PHP_EOL
        .implode(PHP_EOL, $syntaxOutput)
        .PHP_EOL,
    );

    exit(1);
}

fwrite(
    STDOUT,
    'Cashier reservation cancellation integration applied successfully.'
    .PHP_EOL
    .implode(PHP_EOL, $syntaxOutput)
    .PHP_EOL,
);
