<?php

namespace Tests\Feature;

use App\FacilityProductCode;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\FacilityScheduleBlock;
use App\Models\Role;
use App\Models\User;
use App\Services\CashierReservationWorkflowService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BatchThreeMySqlConcurrencyTest extends TestCase
{
    public function test_overlapping_mysql_transactions_serialize_and_only_one_owner_wins(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL concurrency evidence runs only on the disposable MySQL validation database.');
        }

        $database = (string) DB::connection()->getDatabaseName();

        if (! str_starts_with($database, 'olaer_batch3_validation_')) {
            $this->markTestSkipped('Refusing to run concurrency writes outside a disposable Batch 3 validation database.');
        }

        $product = FacilityProduct::query()
            ->where('product_code', FacilityProductCode::RoomStandard)
            ->firstOrFail();
        $facility = Facility::query()->create([
            'facility_name' => 'Batch 3 concurrency '.uniqid(),
            'facility_type_id' => $product->facility_type_id,
            'facility_product_id' => $product->facility_product_id,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '10',
        ]);
        $cashier = User::factory()->create([
            'role_id' => Role::query()->where('role_name', 'Cashier')->valueOrFail('role_id'),
        ]);
        $workflow = app(CashierReservationWorkflowService::class);
        $first = $workflow->create($this->reservationPayload($cashier, $facility, '2035-01-01', '2035-01-02'));
        $second = $workflow->create($this->reservationPayload($cashier, $facility, '2035-01-03', '2035-01-04'));
        $target = $this->reschedulePayload($cashier, $facility, '2035-01-05', '2035-01-06');
        $marker = tempnam(sys_get_temp_dir(), 'olaer-batch3-');

        $this->assertNotFalse($marker);
        DB::beginTransaction();

        try {
            $workflow->reschedule($first->reservation_id, $target);

            $process = new Process([
                PHP_BINARY,
                '-r',
                $this->childProcessCode(
                    $second->reservation_id,
                    $target,
                    $marker,
                ),
            ], base_path());
            $process->setTimeout(15);
            $process->start();

            $readyDeadline = microtime(true) + 5;

            while (file_get_contents($marker) !== 'ready' && $process->isRunning() && microtime(true) < $readyDeadline) {
                usleep(10_000);
            }

            $this->assertSame('ready', file_get_contents($marker));
            $blockedAt = microtime(true);
            $observationDeadline = $blockedAt + 0.25;

            while ($process->isRunning() && microtime(true) < $observationDeadline) {
                usleep(10_000);
            }

            $this->assertTrue($process->isRunning(), 'The second transaction did not wait on the locked facility row.');

            DB::commit();
            $process->wait();
            $elapsedWhileBlocked = microtime(true) - $blockedAt;

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertGreaterThanOrEqual(0.20, $elapsedWhileBlocked);
            fwrite(STDOUT, sprintf("MySQL competing transaction blocked for %.3f seconds.\n", $elapsedWhileBlocked));
            $this->assertSame(1, FacilityScheduleBlock::query()
                ->where('facility_id', $facility->facility_id)
                ->whereDate('service_date', '2035-01-05')
                ->where('slot', 'overnight')
                ->count());
            $this->assertSame(
                (int) $first->details()->value('reservation_details_id'),
                FacilityScheduleBlock::query()
                    ->where('facility_id', $facility->facility_id)
                    ->whereDate('service_date', '2035-01-05')
                    ->value('reservation_detail_id'),
            );
            $this->assertSame('2035-01-03', substr((string) $second->details()->value('check_in_date'), 0, 10));
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }

            if (file_exists($marker)) {
                unlink($marker);
            }
        }
    }

    /** @return array<string, mixed> */
    private function reservationPayload(User $cashier, Facility $facility, string $checkIn, string $checkOut): array
    {
        return [
            'user_id' => $cashier->user_id,
            'first_name' => 'MySQL',
            'last_name' => 'Concurrency',
            'contact_no' => '09171234567',
            'email' => uniqid().'@example.test',
            'city' => 'Tacurong City',
            'province' => 'Sultan Kudarat',
            'facility_type_id' => $facility->facility_type_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'discount_id' => null,
            'total_guest_count' => 4,
            'extra_guests' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function reschedulePayload(User $cashier, Facility $facility, string $checkIn, string $checkOut): array
    {
        return [
            'user_id' => $cashier->user_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'discount_id' => null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function childProcessCode(int $reservationId, array $payload, string $marker): string
    {
        return strtr(<<<'PHP'
            require __AUTOLOAD__;
            $app = require __BOOTSTRAP__;
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            file_put_contents(__MARKER__, 'ready');

            try {
                app(App\Services\CashierReservationWorkflowService::class)->reschedule(__RESERVATION_ID__, __PAYLOAD__);
                fwrite(STDERR, 'The overlapping reschedule unexpectedly succeeded.');
                exit(2);
            } catch (InvalidArgumentException $exception) {
                if (str_contains($exception->getMessage(), 'no longer available')) {
                    exit(0);
                }

                fwrite(STDERR, $exception->getMessage());
                exit(3);
            }
            PHP, [
            '__AUTOLOAD__' => var_export(base_path('vendor/autoload.php'), true),
            '__BOOTSTRAP__' => var_export(base_path('bootstrap/app.php'), true),
            '__MARKER__' => var_export($marker, true),
            '__RESERVATION_ID__' => (string) $reservationId,
            '__PAYLOAD__' => var_export($payload, true),
        ]);

    }
}
