<?php

return [
    'app_id' => env('PUSHER_APP_ID'),
    'key' => env('PUSHER_APP_KEY'),
    'secret' => env('PUSHER_APP_SECRET'),
    'cluster' => env('PUSHER_APP_CLUSTER'),
    'host' => env('PUSHER_HOST') ?: 'api-' . env('PUSHER_APP_CLUSTER', 'mt1') . '.pusher.com',
    'port' => env('PUSHER_PORT', 443),
    'scheme' => env('PUSHER_SCHEME', 'https'),
    'encrypted' => true,
    'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',

    // Client events configuration
    'client_events' => [
        'client-send-message',
        'client-typing',
        'client-mark-read',
        'client-driver-location-update',
    ],

    // Webhook configuration for client events
    'webhooks' => [
        'endpoint' => env('APP_URL') . '/api/pusher/webhook',
        'events' => [
            'client-send-message',
            'client-typing',
            'client-mark-read',
            'client-driver-location-update',
        ],
    ],
];
