<?php

return [
    'api_token' => env('ZAPMIZER_API_TOKEN'),
    'base_uri' => env('ZAPMIZER_BASE_URI', 'https://app.zapmizer.com/api/'),
    'from_number' => env('ZAPMIZER_FROM_NUMBER'),
    // Publishable key (pk_...) for the widget verify-number API — not the
    // same credential as the messages API token above.
    'publishable_key' => env('ZAPMIZER_PUBLISHABLE_KEY'),
    // Secret key (sk_...) for creating hosted verification page sessions
    // server-side. Yet another credential — never expose it to the browser.
    'secret_key' => env('ZAPMIZER_SECRET_KEY'),
    'webhook_secret' => env('ZAPMIZER_WEBHOOK_SECRET'),
    // Where the user lands after completing the hosted verification page
    // (signed with the webhook secret — see ZapbotSignature::isValidQuery()).
    'return_url' => env('ZAPMIZER_RETURN_URL'),
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
        // The webhook route is public and stateless; add e.g. a throttle here.
        'webhook_middleware' => [],
    ],
];
