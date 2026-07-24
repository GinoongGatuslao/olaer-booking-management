<?php

namespace App\Console\Commands;

use App\Services\ReservationNoShowReleaseService;
use Illuminate\Console\Command;

class ReleaseNoShowReservations extends Command
{
    protected $signature = 'olaer:release-no-show-reservations
        {--date= : Date boundary in YYYY-MM-DD format. Defaults to today.}
        {--limit=100 : Maximum reservations to release in one run.}';

    protected $description =
        'Mark past unpaid active reservations as No-show to release held facilities.';

    public function handle(
        ReservationNoShowReleaseService $service,
    ): int {
        $date = $this->option('date') !== null
            ? (string) $this->option('date')
            : null;

        $limit = (int) $this->option('limit');

        $count = $service->expirePastUnpaidReservations(
            $date,
            $limit,
        );

        if ($count === 0) {
            $this->info(
                'No unpaid past reservations were eligible for no-show release.',
            );

            return self::SUCCESS;
        }

        $this->info(
            "Released {$count} unpaid past reservation(s) as No-show.",
        );

        return self::SUCCESS;
    }
}
