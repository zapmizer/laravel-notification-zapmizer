<?php

return [
    'api_token' => env('ZAPMIZER_API_TOKEN'),
    'base_uri' => env('ZAPMIZER_BASE_URI', 'https://app.zapmizer.com/api/'),
    'from_number' => env('ZAPMIZER_FROM_NUMBER'),
    'webhook_secret' => env('ZAPMIZER_WEBHOOK_SECRET'),
    // Origin sent on verify-number API calls; must be in the verification's
    // allowed origins on the Zapbot side. Defaults to the app URL.
    'origin' => env('ZAPMIZER_ORIGIN'),
    'models' => [
        'whatsapp_verified' => \NotificationChannels\Zapmizer\Models\WhatsappVerified::class,
    ],
    'routes' => [
        'enabled' => env('ZAPMIZER_ROUTES_ENABLED', true),
        'prefix' => 'zapmizer',
        'middleware' => ['web', 'auth'],
    ],
];
