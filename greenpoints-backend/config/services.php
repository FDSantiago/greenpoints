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

    // School Registry (System 2): student lookup and section rosters over REST/JSON.
    // `enabled` only switches the registration check; the client itself always works.
    'school_registry' => [
        'enabled' => (bool) env('SCHOOL_REGISTRY_ENABLED', false),
        'base_url' => env('SCHOOL_REGISTRY_URL'),
        'read_key' => env('SCHOOL_REGISTRY_KEY'),
        'timeout' => (int) env('SCHOOL_REGISTRY_TIMEOUT', 3),   // seconds, per attempt
        'retries' => (int) env('SCHOOL_REGISTRY_RETRIES', 2),   // extra attempts on connection errors / 5xx
    ],

];
