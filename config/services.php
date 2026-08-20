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
    | dev/QA-only LogSmsAdapter) so nothing changes in any environment that
    | hasn't deliberately set SMS_DRIVER + real credentials.
    |
    | The vendor decision itself (open as of KNOWN_RISKS_AND_DECISIONS.md
    | item 8 / PHASE_21_RELEASE_CANDIDATE_BACKLOG.md BD-8) is no longer
    | undecided for this business: 'arkesel' is the SMS gateway CONFIRMED
    | actually configured (real credentials populated) in the real Glover
    | 1.8.5 reference deployment's own environment -- see
    | ArkeselSmsAdapter's docblock for the exact evidence. msg91 and
    | gatewayapi remain equally-ready alternative adapters (built during
    | BD-8's technical-preparation pass) but were never confirmed live for
    | this business; they're kept, not removed, in case a future
    | environment needs them.
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log|arkesel|msg91|gatewayapi

        'arkesel' => [
            'api_key' => env('ARKESEL_API_KEY'),
            'sender' => env('ARKESEL_SENDER'),
        ],

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

    /*
    |--------------------------------------------------------------------------
    | AI narrative phrasing (Admin Polish + AI session, Part 2 item 4)
    |--------------------------------------------------------------------------
    |
    | Same pattern as 'sms'/'push' above. Only ever used to REPHRASE facts
    | already computed by real queries (App\Services\Operations\
    | OperationalInsightsService, App\Services\Ai\BookingNaturalLanguageFilter)
    | into prose or to resolve free-text filter intent — never to decide
    | what the facts/filters themselves are. Defaults to 'log' (returns
    | null, caller falls back to displaying the real data/deterministic
    | parse directly) so nothing changes in any environment that hasn't
    | deliberately set AI_DRIVER=anthropic + a real key. No key is
    | populated anywhere in this repo's own .env.example.
    */
    'ai' => [
        'driver' => env('AI_DRIVER', 'log'), // log|anthropic

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
            'timeout' => env('ANTHROPIC_TIMEOUT_SECONDS', 10),
        ],
    ],

];
