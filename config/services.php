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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'allowed_domain' => env('GOOGLE_ALLOWED_DOMAIN', 'iedagropivijay.edu.co'),
        'logout_from_browser' => env('GOOGLE_LOGOUT_FROM_BROWSER', false),
        'workspace_admin_email' => env('GOOGLE_WORKSPACE_ADMIN_EMAIL'),
        'workspace_customer' => env('GOOGLE_WORKSPACE_CUSTOMER', 'my_customer'),
        'workspace_type' => env('GOOGLE_DRIVE_TYPE', 'service_account'),
        'workspace_project_id' => env('GOOGLE_DRIVE_PROJECT_ID'),
        'workspace_private_key_id' => env('GOOGLE_DRIVE_PRIVATE_KEY_ID'),
        'workspace_private_key' => env('GOOGLE_DRIVE_PRIVATE_KEY'),
        'workspace_client_email' => env('GOOGLE_DRIVE_CLIENT_EMAIL'),
        'workspace_client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'validate_users_on_create' => env('GOOGLE_WORKSPACE_VALIDATE_USERS_ON_CREATE', true),
    ],

];
