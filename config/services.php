<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

  	'razorpay' => [
    'key_id' => env('RAZORPAY_KEY_ID'),
    'key_secret' => env('RAZORPAY_KEY_SECRET'),
    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
	],
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
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
    |--------------------------------------------------------------------------
    | SMS delivery (BD-8)
    |--------------------------------------------------------------------------
    |
    | 'driver' selects which App\Contracts\SmsAdapter implementation
    | AppServiceProvider::register() binds. Defaults to 'log' (the existing
    | dev/QA-only LogSmsAdapter) so nothing changes until an operator
    | deliberately sets SMS_DRIVER + real credentials in a real environment
    | -- this file intentionally does NOT pick a vendor (see
    | KNOWN_RISKS_AND_DECISIONS.md item 8 / PHASE_21_RELEASE_CANDIDATE_BACKLOG.md
    | BD-8 for why: the vendor choice is a business decision, not made here).
    | Both msg91 and gatewayapi adapters exist and are equally ready --
    | picking one over the other is deliberately left to whoever sets this
    | env var, not defaulted by this file.
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log|msg91|gatewayapi

        'msg91' => [
            'auth_key' => env('MSG91_AUTH_KEY'),
            'sender_id' => env('MSG91_SENDER_ID'),
            // India DLT/TRAI regulations require SMS routes/templates to be
            // pre-registered with the telecom regulator before real
            // transactional/OTP SMS can be sent on Indian numbers -- this
            // route id and any template id are real production
            // prerequisites this repo cannot supply (see BD-8 documentation).
            'route' => env('MSG91_ROUTE', '4'),
        ],

        'gatewayapi' => [
            'token' => env('GATEWAYAPI_TOKEN'),
            'sender' => env('GATEWAYAPI_SENDER'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push delivery (BD-8)
    |--------------------------------------------------------------------------
    |
    | Same pattern as 'sms' above. Firebase Cloud Messaging is the one real
    | historical-precedent provider for push (see BD-8 documentation) --
    | unlike SMS, this is not a "pick one of several" choice, but it still
    | defaults to 'log' until real Firebase project credentials exist.
    */
    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'), // log|fcm

        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID'),
            // Path to a Firebase service-account JSON file (never committed).
            'credentials_path' => env('FCM_CREDENTIALS_PATH'),
            // Alternative to the path above: the service-account JSON
            // supplied directly as an env value, for hosts without a
            // reliable persistent file path for secrets.
            'credentials_json' => env('FCM_CREDENTIALS_JSON'),
        ],
    ],

];
