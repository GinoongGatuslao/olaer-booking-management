<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\GcashProofStorageService;
use Illuminate\Console\Command;

class MigrateGcashProofsToPrivateStorage extends Command
{
    protected $signature = 'olaer:migrate-gcash-proofs-private
        {--dry-run : Show what would be moved without changing files}';

    protected $description =
        'Move legacy GCash proof files from public to private storage.';

    public function handle(
        GcashProofStorageService $proofStorage,
    ): int {
        $moved = 0;
        $missing = 0;
        $invalid = 0;
        $alreadyPrivate = 0;

        Payment::query()
            ->whereNotNull('proof_of_payment_path')
            ->orderBy('payment_id')
            ->chunkById(
                100,
                function ($payments) use (
                    $proofStorage,
                    &$moved,
                    &$missing,
                    &$invalid,
                    &$alreadyPrivate,
                ): void {
                    foreach ($payments as $payment) {
                        $path = $proofStorage->normalize(
                            (string) $payment->proof_of_payment_path,
                        );

                        if ($path === null) {
                            $invalid++;
                            $this->warn(
                                "Payment {$payment->payment_id}: invalid proof path.",
                            );

                            continue;
                        }

                        $disk = $proofStorage->diskContaining($path);

                        if ($disk === 'local') {
                            $alreadyPrivate++;
                            continue;
                        }

                        if ($disk === null) {
                            $missing++;
                            $this->warn(
                                "Payment {$payment->payment_id}: proof file is missing.",
                            );

                            continue;
                        }

                        if ($this->option('dry-run')) {
                            $this->line(
                                "Would move payment {$payment->payment_id}: {$path}",
                            );
                            $moved++;

                            continue;
                        }

                        if ($proofStorage->moveLegacyPublicFile($path)) {
                            $moved++;
                            $this->info(
                                "Moved payment {$payment->payment_id}: {$path}",
                            );
                        } else {
                            $missing++;
                            $this->error(
                                "Could not move payment {$payment->payment_id}: {$path}",
                            );
                        }
                    }
                },
                'payment_id',
            );

        $this->newLine();
        $this->table(
            ['Result', 'Count'],
            [
                ['Moved / would move', $moved],
                ['Already private', $alreadyPrivate],
                ['Missing / failed', $missing],
                ['Invalid path', $invalid],
            ],
        );

        return ($missing > 0 || $invalid > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
