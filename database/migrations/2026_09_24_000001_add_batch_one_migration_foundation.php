<?php

use App\Models\BookingDetail;
use App\Models\ReservationDetail;
use App\Services\FacilityScheduleBlockService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->removeManagerRoleSafely();
        $this->addNumericFacilityCapacities();
        $this->createTransactionLedgerFoundation();
        $this->createInspectionDraftFineFoundation();
        $this->addEntranceSlipVoidMetadata();
        $this->backfillActiveScheduleBlocks();
    }

    public function down(): void
    {
        Schema::table('tbl_entrance_slip', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_entrance_slip', 'replacement_entrance_slip_id')) {
                $table->dropForeign(['replacement_entrance_slip_id']);
                $table->dropColumn('replacement_entrance_slip_id');
            }
            foreach (['voided_by_user_id'] as $column) {
                if (Schema::hasColumn('tbl_entrance_slip', $column)) {
                    $table->dropForeign([$column]);
                    $table->dropColumn($column);
                }
            }
            foreach (['voided_at', 'void_reason'] as $column) {
                if (Schema::hasColumn('tbl_entrance_slip', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('tbl_inspection_draft_fines');
        Schema::dropIfExists('tbl_transaction_credit_allocations');
        Schema::dropIfExists('tbl_transaction_credits');
        Schema::dropIfExists('tbl_transaction_adjustments');

        Schema::table('tbl_facility', function (Blueprint $table) {
            foreach (['min_capacity', 'max_capacity'] as $column) {
                if (Schema::hasColumn('tbl_facility', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function removeManagerRoleSafely(): void
    {
        if (! Schema::hasTable('tbl_role') || ! Schema::hasTable('tbl_user')) {
            return;
        }

        $managerRole = DB::table('tbl_role')->where('role_name', 'Manager')->first();

        if ($managerRole === null) {
            return;
        }

        $managerUsers = DB::table('tbl_user')
            ->where('role_id', $managerRole->role_id)
            ->orderBy('user_id')
            ->get(['user_id', 'username', 'email']);

        if ($managerUsers->isNotEmpty()) {
            $identities = $managerUsers
                ->map(fn (object $user): string => sprintf(
                    '#%d %s <%s>',
                    $user->user_id,
                    $user->username,
                    $user->email,
                ))
                ->implode(', ');

            throw new RuntimeException(
                'Manager role removal requires explicit reassignment before migration. '
                .'Reassign these account(s) to an approved role, then rerun migration: '
                .$identities
            );
        }

        DB::table('tbl_role')->where('role_id', $managerRole->role_id)->delete();
    }

    private function addNumericFacilityCapacities(): void
    {
        if (! Schema::hasTable('tbl_facility')) {
            return;
        }

        Schema::table('tbl_facility', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_facility', 'min_capacity')) {
                $table->unsignedSmallInteger('min_capacity')->nullable()->after('capacity');
            }
            if (! Schema::hasColumn('tbl_facility', 'max_capacity')) {
                $table->unsignedSmallInteger('max_capacity')->nullable()->after('min_capacity');
            }
        });

        if (! Schema::hasTable('tbl_facility_product')) {
            return;
        }

        $facilities = DB::table('tbl_facility as f')
            ->leftJoin('tbl_facility_product as p', 'p.facility_product_id', '=', 'f.facility_product_id')
            ->select([
                'f.facility_id',
                'f.capacity',
                'p.capacity_policy',
                'p.suggested_minimum',
                'p.suggested_maximum',
                'p.included_guest_count',
                'p.strict_maximum',
            ])
            ->orderBy('f.facility_id')
            ->get();

        foreach ($facilities as $facility) {
            $min = $facility->suggested_minimum;
            $max = $facility->strict_maximum ?? $facility->suggested_maximum;

            if ($facility->capacity_policy === 'strict') {
                $min = $facility->included_guest_count ?? 1;
            }

            if ($min === null || $max === null) {
                [$legacyMin, $legacyMax] = $this->parseLegacyCapacity((string) $facility->capacity);
                $min ??= $legacyMin;
                $max ??= $legacyMax;
            }

            if ($max === null || $max < 1) {
                throw new RuntimeException(
                    "Unable to derive a numeric maximum capacity for facility ID {$facility->facility_id}."
                );
            }

            $min ??= $max;

            if ($min < 1 || $min > $max) {
                throw new RuntimeException(
                    "Invalid numeric capacity range for facility ID {$facility->facility_id}."
                );
            }

            DB::table('tbl_facility')
                ->where('facility_id', $facility->facility_id)
                ->update([
                    'min_capacity' => (int) $min,
                    'max_capacity' => (int) $max,
                ]);
        }
    }

    /** @return array{0: ?int, 1: ?int} */
    private function parseLegacyCapacity(string $capacity): array
    {
        preg_match_all('/\d+/', $capacity, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        return match (count($numbers)) {
            0 => [null, null],
            1 => [$numbers[0], $numbers[0]],
            default => [$numbers[0], max($numbers)],
        };
    }

    private function createTransactionLedgerFoundation(): void
    {
        if (! Schema::hasTable('tbl_transaction_credits')) {
            Schema::create('tbl_transaction_credits', function (Blueprint $table) {
                $table->id('transaction_credit_id');
                $table->foreignId('reservation_id')->nullable()
                    ->constrained('tbl_reservation', 'reservation_id')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('booking_id')->nullable()
                    ->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('source_type', 50);
                $table->unsignedBigInteger('source_id')->nullable();
                $table->decimal('original_amount', 12, 2);
                $table->decimal('remaining_amount', 12, 2);
                $table->string('status', 20)->default('Available');
                $table->string('reason', 255);
                $table->foreignId('created_by_user_id')->nullable()
                    ->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->index(['reservation_id', 'status']);
                $table->index(['booking_id', 'status']);
            });
        }

        if (! Schema::hasTable('tbl_transaction_credit_allocations')) {
            Schema::create('tbl_transaction_credit_allocations', function (Blueprint $table) {
                $table->id('transaction_credit_allocation_id');
                $table->foreignId('transaction_credit_id')
                    ->constrained('tbl_transaction_credits', 'transaction_credit_id')
                    ->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('target_type', 50);
                $table->unsignedBigInteger('target_id')->nullable();
                $table->decimal('amount', 12, 2);
                $table->foreignId('applied_by_user_id')->nullable()
                    ->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('applied_at');
                $table->timestamps();
                $table->index(['target_type', 'target_id']);
            });
        }

        if (! Schema::hasTable('tbl_transaction_adjustments')) {
            Schema::create('tbl_transaction_adjustments', function (Blueprint $table) {
                $table->id('transaction_adjustment_id');
                $table->foreignId('reservation_id')->nullable()
                    ->constrained('tbl_reservation', 'reservation_id')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('booking_id')->nullable()
                    ->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('entrance_slip_id')->nullable()
                    ->constrained('tbl_entrance_slip', 'entrance_slip_id')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('direction', 10); // Debit / Credit
                $table->decimal('amount', 12, 2);
                $table->string('reason', 255);
                $table->foreignId('created_by_user_id')->nullable()
                    ->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->index(['reservation_id', 'created_at']);
                $table->index(['booking_id', 'created_at']);
                $table->index(['entrance_slip_id', 'created_at']);
            });
        }
    }

    private function createInspectionDraftFineFoundation(): void
    {
        if (Schema::hasTable('tbl_inspection_draft_fines')) {
            return;
        }

        Schema::create('tbl_inspection_draft_fines', function (Blueprint $table) {
            $table->id('inspection_draft_fine_id');
            $table->unsignedBigInteger('facility_inspection_request_id');
            $table->foreign('facility_inspection_request_id', 'fk_inspection_draft_request')
                ->references('facility_inspection_request_id')
                ->on('tbl_facility_inspection_request')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('booking_details_id')
                ->constrained('tbl_booking_details', 'booking_details_id')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('fine_id')
                ->constrained('tbl_fine', 'fine_id')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('item_source', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('unit_charge_snapshot', 12, 2);
            $table->decimal('total_charge', 12, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_user_id')
                ->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
            $table->index(['facility_inspection_request_id', 'booking_details_id'], 'idx_inspection_draft_owner');
        });
    }

    private function addEntranceSlipVoidMetadata(): void
    {
        if (! Schema::hasTable('tbl_entrance_slip')) {
            return;
        }

        Schema::table('tbl_entrance_slip', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_entrance_slip', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('admitted_at');
            }
            if (! Schema::hasColumn('tbl_entrance_slip', 'voided_by_user_id')) {
                $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')
                    ->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            }
            if (! Schema::hasColumn('tbl_entrance_slip', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('voided_by_user_id');
            }
            if (! Schema::hasColumn('tbl_entrance_slip', 'replacement_entrance_slip_id')) {
                $table->foreignId('replacement_entrance_slip_id')->nullable()->after('void_reason')
                    ->constrained('tbl_entrance_slip', 'entrance_slip_id')
                    ->cascadeOnUpdate()->nullOnDelete();
            }
        });
    }

    private function backfillActiveScheduleBlocks(): void
    {
        if (! Schema::hasTable('tbl_facility_schedule_blocks')) {
            return;
        }

        DB::transaction(function (): void {
            $service = app(FacilityScheduleBlockService::class);

            ReservationDetail::query()
                ->whereNotNull('facility_id')
                ->whereNotNull('schedule_policy')
                ->whereNotNull('rate_code')
                ->whereHas('reservation', fn ($query) => $query->whereNotIn(
                    'status',
                    ['Cancelled', 'Converted', 'No-show'],
                ))
                ->orderBy('reservation_details_id')
                ->chunkById(100, function ($details) use ($service): void {
                    foreach ($details as $detail) {
                        $service->acquireForReservationDetail($detail);
                    }
                }, 'reservation_details_id');

            BookingDetail::query()
                ->whereNotNull('facility_id')
                ->whereNotNull('schedule_policy')
                ->whereNotNull('rate_code')
                ->whereNotIn('status', ['Cancelled', 'Checked-out', 'Payment Rejected'])
                ->whereHas('booking', fn ($query) => $query->whereNotIn(
                    'status',
                    ['Cancelled', 'Checked-out', 'Payment Rejected'],
                ))
                ->orderBy('booking_details_id')
                ->chunkById(100, function ($details) use ($service): void {
                    foreach ($details as $detail) {
                        $service->acquireForBookingDetail($detail);
                    }
                }, 'booking_details_id');
        }, attempts: 1);
    }
};
