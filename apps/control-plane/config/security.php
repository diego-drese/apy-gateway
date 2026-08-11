<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Admin notification e-mail
    |--------------------------------------------------------------------------
    |
    | IP access approval links are sent here, never to the requester's own
    | e-mail (SPEC.md §6.1) — this is the only "admin" concept the system has.
    |
    */

    'admin_notification_email' => env('ADMIN_NOTIFICATION_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | TTLs
    |--------------------------------------------------------------------------
    */

    'ip_access_request_ttl_hours' => (int) env('IP_ACCESS_REQUEST_TTL_HOURS', 24),

    'two_factor_code_ttl_minutes' => (int) env('TWO_FACTOR_CODE_TTL_MINUTES', 5),

];
