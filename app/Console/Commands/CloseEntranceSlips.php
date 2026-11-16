<?php

namespace App\Console\Commands;

use App\Services\EntranceSlipWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CloseEntranceSlips extends Command
{
    protected $signature = 'olaer:close-entrance-slips
        {--date= : Close unpaid slips made before this date (YYYY-MM-DD). Defaults to today.}';

    protected $description =
        'Mark unpaid entrance slips from earlier days as Closed. Closed slips can only be viewed.';

    public function handle(EntranceSlipWorkflowService $service): int
    {
        $date = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : null;

        $count = $service->closePastUnpaid($date);

        $this->info(
            $count === 0
                ? 'No unpaid entrance slips from earlier days needed closing.'
                : "Closed {$count} unpaid entrance slip(s) from earlier days.",
        );

        return self::SUCCESS;
    }
}
