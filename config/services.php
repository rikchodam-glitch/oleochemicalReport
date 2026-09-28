<?php

return [

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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'oleochemical_bot'),
    ],

    'google_drive' => [
        'enabled'                  => env('GOOGLE_DRIVE_ENABLED', false),
        'credentials_path'         => env('GOOGLE_DRIVE_CREDENTIALS_PATH'),
        'finding_photos_folder_id' => env('GOOGLE_DRIVE_FINDING_PHOTOS_FOLDER_ID'),
        'make_public'              => env('GOOGLE_DRIVE_MAKE_PUBLIC', true),
    ],

];
