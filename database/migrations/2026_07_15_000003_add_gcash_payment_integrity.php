<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
//use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        $normalizedReferences = [];

        DB::table('tbl_payment')
            ->select([
                'payment_id',
                'reference_number',
            ])
            ->whereNotNull('reference_number')
            ->orderBy('payment_id')
            ->get()
            ->each(function ($payment) use (
                &$normalizedReferences,
            ): void {
                $normalized = strtoupper(
                    preg_replace(
                        '/\s+/',
                        '',
                        trim((string) $payment->reference_number),
                    ) ?? '',
                );

                if ($normalized === '') {
                    return;
                }

                if (isset($normalizedReferences[$normalized])) {
                    throw new RuntimeException(
                        'Duplicate payment reference number detected: '
                        .$normalized
                        .'. Resolve duplicate payment references before rerunning this migration.',
                    );
                }

                $normalizedReferences[$normalized] =
                    (int) $payment->payment_id;
            });

        foreach ($normalizedReferences as $reference => $paymentId) {
            DB::table('tbl_payment')
                ->where('payment_id', $paymentId)
                ->update([
                    'reference_number' => $reference,
                ]);
        }

        Schema::table(
            'tbl_payment',
            function (Blueprint $table): void {
                $table->string(
                    'rejection_reason',
                    500,
                )->nullable();

                $table->unique(
                    'reference_number',
                    'uq_payment_reference_number',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::table(
            'tbl_payment',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'uq_payment_reference_number',
                );

                $table->dropColumn(
                    'rejection_reason',
                );
            },
        );
    }
};
