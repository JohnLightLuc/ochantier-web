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

    'otp' => [
        // Validité d’un code, en minutes.
        'lifetime' => (int) env('OTP_LIFETIME', 5),
        // Délai avant de pouvoir redemander un code, en secondes.
        'resend_after' => (int) env('OTP_RESEND_AFTER', 45),
        // Essais autorisés par code.
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        // Code fixe hors production (tests, recette), jamais en production.
        'test_code' => env('OTP_TEST_CODE'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    // Encaissement Mobile Money : « simulation » hors production, en attendant le prestataire retenu.
    'payments' => [
        'driver' => env('PAYMENT_DRIVER', 'simulation'),
    ],

];
