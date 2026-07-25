<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$noShowReleaseTime = (string) config(
    'olaer.no_show_release_time',
    '03:00',
);

if (
    preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        $noShowReleaseTime,
    ) !== 1
) {
    $noShowReleaseTime = '03:00';
}

$noShowReleaseTimezone = (string) config(
    'olaer.no_show_release_timezone',
    'Asia/Manila',
);

$noShowReleaseLimit = max(
    1,
    (int) config(
        'olaer.no_show_release_limit',
        100,
    ),
);

Schedule::command(
    'olaer:release-no-show-reservations'
    ." --limit={$noShowReleaseLimit}",
)
    ->dailyAt($noShowReleaseTime)
    ->timezone($noShowReleaseTimezone)
    ->withoutOverlapping();
