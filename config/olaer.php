<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reservation no-show release
    |--------------------------------------------------------------------------
    |
    | The service only releases Active reservations when their check-in date
    | is before the processing date and no Verified payment exists.
    |
    | The default schedule runs early the following calendar day, not during
    | the guest's scheduled check-in date.
    |
    */

    'no_show_release_time' => env(
        'OLAER_NO_SHOW_RELEASE_TIME',
        '03:00',
    ),

    'no_show_release_timezone' => env(
        'OLAER_NO_SHOW_RELEASE_TIMEZONE',
        'Asia/Manila',
    ),

    'no_show_release_limit' => max(
        1,
        (int) env(
            'OLAER_NO_SHOW_RELEASE_LIMIT',
            100,
        ),
    ),
];
