<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Public resort information
    |--------------------------------------------------------------------------
    |
    | These details are shown across the customer website. Keeping them here
    | provides one source of truth for the public layout and visit information.
    |
    */

    'public' => [
        'address' => 'Purok Olaer, General Santos City (Dadiangas), 9500 South Cotabato',
        'phones' => [
            [
                'display' => '09279435323',
                'href' => 'tel:09279435323',
            ],
            [
                'display' => '0967 217 4485',
                'href' => 'tel:09672174485',
            ],
        ],
        'email' => 'olaermarketing@gmail.com',
        'hours' => 'Open 24 hours',
        'map_url' => 'https://maps.app.goo.gl/vf7jnCaVwTEhDfEw8',
        'facebook_url' => 'https://www.facebook.com/OlaerSwimmingResort',
    ],

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
