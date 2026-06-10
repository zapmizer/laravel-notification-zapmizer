<?php

return [
    'api_token' => env('ZAPMIZER_API_TOKEN'),
    'base_uri' => env('ZAPMIZER_BASE_URI', 'https://app.zapmizer.com/api/'),
    'from_number' => env('ZAPMIZER_FROM_NUMBER'),
    'models' => [
        'whatsapp_verified' => \NotificationChannels\Zapmizer\Models\WhatsappVerified::class,
        'webhook_event' => \NotificationChannels\Zapmizer\Models\WebhookEvent::class,
    ],
    'routes' => [
        'enabled' => env('ZAPMIZER_ROUTES_ENABLED', true),
        'prefix' => 'zapmizer',
        'middleware' => ['web', 'auth'],
        // The webhook route is public and stateless, and Zapmizer does not
        // sign deliveries — add e.g. a throttle or an IP allowlist here.
        'webhook_middleware' => [],
    ],
];
