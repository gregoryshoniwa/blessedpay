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

    /*
    | StewardPay / CyberPay — TN CyberTech Bank's payment gateway. BLESSLUXE is
    | the merchant. Live: https://api.stewardpay.co.zw/v1 (tokens last 5 min).
    | The bank's test environment is https://api.stewardpay.co.zw/lab/v2 and
    | needs its own credentials — live ones are rejected there.
    */
    'stewardpay' => [
        'base_url' => rtrim((string) env('STEWARDPAY_BASE_URL', 'https://api.stewardpay.co.zw/v1'), '/'),
        'client_id' => env('STEWARDPAY_CLIENT_ID'),
        'client_secret' => env('STEWARDPAY_CLIENT_SECRET'),
        'encryption_key' => env('STEWARDPAY_ENCRYPTION_KEY'),
        'sandbox' => (bool) env('STEWARDPAY_SANDBOX', false),
        'timeout' => (int) env('STEWARDPAY_TIMEOUT', 25),
    ],

];
