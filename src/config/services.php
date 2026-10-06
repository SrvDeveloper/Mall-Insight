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

    'crosswalker' => [
        'base_url' => env('CROSSWALKER_BASE_URL'),
        'api_key' => env('CROSSWALKER_API_KEY'),
        // true のときは実APIを呼ばず、database/data/crosswalker-items.json のサンプルを返す
        'mock' => (bool) env('CROSSWALKER_MOCK', false),
        'connect_timeout' => (int) env('CROSSWALKER_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('CROSSWALKER_TIMEOUT', 10),
    ],

    'zerostockview' => [
        'base_url' => env('ZEROSTOCKVIEW_BASE_URL'),
        'api_key' => env('ZEROSTOCKVIEW_API_KEY'),
        // true のときは実APIを呼ばず、CrossWalker のサンプルSKUに対する在庫を生成して返す
        'mock' => (bool) env('ZEROSTOCKVIEW_MOCK', false),
        'connect_timeout' => (int) env('ZEROSTOCKVIEW_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('ZEROSTOCKVIEW_TIMEOUT', 30),
    ],

];
