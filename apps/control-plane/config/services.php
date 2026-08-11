<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // SPEC.md §11: ACME (Let's Encrypt) automatic issuance. `base_url` is the ACME server's base
    // (not the `/directory` path itself — the client appends that) — point it at Pebble for local
    // verification (docker/acme-verification/), never disable TLS verification to make that work,
    // trust Pebble's test root via the container's system CA store instead.
    'acme' => [
        'base_url' => env('ACME_BASE_URL', 'https://acme-v02.api.letsencrypt.org'),
        'account_key_storage_path' => env('ACME_ACCOUNT_KEY_STORAGE_PATH', 'acme/account-key.pem'),
        'renewal_threshold_days' => (int) env('ACME_RENEWAL_THRESHOLD_DAYS', 30),
        'challenge_propagation_delay_seconds' => (int) env('ACME_CHALLENGE_PROPAGATION_DELAY_SECONDS', 3),
        'acme_poll_max_attempts' => (int) env('ACME_POLL_MAX_ATTEMPTS', 10),
        'acme_poll_interval_seconds' => (int) env('ACME_POLL_INTERVAL_SECONDS', 3),
    ],

];
