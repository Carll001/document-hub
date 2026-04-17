<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional file to locate the various service credentials.
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

    'email_sync' => [
        'host' => env('IMAP_HOST', 'imap.gmail.com'),
        'port' => (int) env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
        'username' => env('IMAP_USERNAME', env('MAIL_USERNAME')),
        'password' => env('IMAP_PASSWORD', env('MAIL_PASSWORD')),
        'mailbox' => env('IMAP_MAILBOX', 'INBOX'),
        'fetch_limit' => (int) env('IMAP_FETCH_LIMIT', 25),
        'validate_certificate' => filter_var(env('IMAP_VALIDATE_CERT', true), FILTER_VALIDATE_BOOL),
    ],

    'document_generator' => [
        'libreoffice_binary' => env('LIBREOFFICE_BINARY', 'libreoffice'),
        'signature_enabled' => filter_var(env('DOCUMENT_GENERATOR_SIGNATURE_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

];
