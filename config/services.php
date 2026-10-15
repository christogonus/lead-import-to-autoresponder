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

    'aweber' => [
        'client_id' => env('AWEBER_CLIENT_ID'),
        'client_secret' => env('AWEBER_CLIENT_SECRET'),
    ],

    'gotowebinar' => [
        'client_id' => env('GOTOWEBINAR_CLIENT_ID'),
        'client_secret' => env('GOTOWEBINAR_CLIENT_SECRET'),
    ],

    'zoho_campaigns' => [
        'client_id' => env('ZOHO_CAMPAIGNS_CLIENT_ID'),
        'client_secret' => env('ZOHO_CAMPAIGNS_CLIENT_SECRET'),
        // The Zoho data centre the account lives in: com, eu, in, com.au or jp.
        // The OAuth app must be registered in that region's API console.
        'region' => env('ZOHO_CAMPAIGNS_REGION', 'com'),
    ],

    /*
    | The two Paddle prices behind the single Lead2Crm plan. Create one product
    | in Paddle with a $24/month price and a $204/year price, then paste their
    | pri_… IDs here. The amounts the site advertises live in
    | App\Enums\BillingPlan, so keep the two in step.
    */
    'paddle' => [
        'prices' => [
            'monthly' => env('PADDLE_PRICE_MONTHLY'),
            'yearly' => env('PADDLE_PRICE_YEARLY'),
        ],
    ],

];
