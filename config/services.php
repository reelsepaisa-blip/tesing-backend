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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'razorpay' => [
        'key_id' => '', // Temporarily removed for security
        'key_secret' => '', // Temporarily removed for security
        'webhook_secret' => '', // Temporarily removed for security
        'enabled' => false, // Temporarily disabled
        'fee' => 0,
    ],

    'stripe' => [
        'key' => '', // Temporarily removed for security
        'secret' => '', // Temporarily removed for security
        'webhook_secret' => '', // Temporarily removed for security
        'enabled' => false, // Temporarily disabled
        'fee' => 0,
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'enabled' => env('TWILIO_ENABLED', false),
    ],

    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'template_id' => env('MSG91_TEMPLATE_ID'),
        'sender_id' => env('MSG91_SENDER_ID'),
        'enabled' => env('MSG91_ENABLED', true),
    ],

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'twilio'), // twilio, msg91
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'enabled' => env('GOOGLE_MAPS_ENABLED', true),
    ],

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'project_id' => env('FCM_PROJECT_ID'),
        'messaging_sender_id' => env('FCM_MESSAGING_SENDER_ID'),
        'service_account_path' => base_path('public/e-taxi-649bb-firebase-adminsdk-fbsvc.json'),
        'enabled' => env('FCM_ENABLED', true),
    ],

    'firebase' => [
        'credentials' => base_path('public/e-taxi-649bb-firebase-adminsdk-fbsvc.json'),
        'database_url' => env('FIREBASE_DATABASE_URL', 'https://e-taxi-649bb.firebaseio.com'),
    ],

    /*
    | Optional: PEM public key (or absolute path to a .pem file) to verify update packages.
    | When set, the main update ZIP must include update.manifest.json and update.sig;
    | openssl_verify is applied to the manifest body (SHA256), then each listed file's sha256 is checked.
    */
    'system_update' => [
        'package_public_key_pem' => env('SYSTEM_UPDATE_PACKAGE_PUBLIC_KEY_PEM'),
    ],

];
