<?php

return [
    // Single-tenant credentials: one team token and one sender number for
    // the whole application. Multi-tenant apps use the Connectable trait
    // instead (each model connects its own number) — both coexist.
    'api_token' => env('ZAPMIZER_API_TOKEN'),
    'base_uri' => env('ZAPMIZER_BASE_URI', 'https://app.zapmizer.com/api/'),
    'from_number' => env('ZAPMIZER_FROM_NUMBER'),
    // Zapmizer versions its contract by header. Sent as `api-version` by
    // every client (single-tenant and connected models) when set; leave
    // null for the legacy shape.
    'api_version' => env('ZAPMIZER_API_VERSION'),
    // Country code prepended to numbers typed in national format (10 or 11
    // digits). The Brazilian ninth-digit tolerance only applies to 55.
    'default_country_code' => env('ZAPMIZER_DEFAULT_COUNTRY_CODE', '55'),
    // "Back to the site" button on the hosted verification page. Plain
    // navigation — it does not prove the outcome (webhook/confirm do).
    'return_url' => env('ZAPMIZER_RETURN_URL'),

    // Partner credentials for the hosted connect flow (X-Partner-Key). Issued
    // by Zapmizer per partner application — not per team.
    'partner' => [
        'id' => env('ZAPMIZER_PARTNER_ID'),
        'secret' => env('ZAPMIZER_PARTNER_SECRET'),
    ],

    'connect' => [
        // Who is being connected on a given request. Must implement
        // NotificationChannels\Zapmizer\Contracts\ResolvesConnectable — the
        // default returns the authenticated user; point it at the current
        // team (or anything else using the Connectable trait) as needed.
        'resolver' => \NotificationChannels\Zapmizer\Connect\ResolvesAuthenticatedUser::class,
        // How long the authorization popup has to come back with a code.
        'state_ttl_minutes' => (int) env('ZAPMIZER_CONNECT_STATE_TTL', 10),
    ],

    'webhook' => [
        // Every Zapmizer webhook has a secret, and every bot event (`message`,
        // `qr`, ...) is signed with it: HMAC-SHA256 over "{timestamp}.{raw
        // body}" in X-Zapmizer-Signature. This is the secret of the webhook
        // registered by hand for the single-tenant setup (the "Webhooks"
        // screen shows it once, on creation). Connections made through the
        // connect flow store their own secret; both are tested on delivery.
        // Only `verify_number.*` events arrive unsigned — Zapmizer sends
        // those outside the bot, without a signature — and only those are
        // accepted without one.
        'secret' => env('ZAPMIZER_WEBHOOK_SECRET'),
        // Accepted drift between X-Zapmizer-Timestamp and now, in seconds.
        // Closes replay of an old delivery without breaking legitimate retries.
        'tolerance' => (int) env('ZAPMIZER_WEBHOOK_TOLERANCE', 300),
    ],

    'models' => [
        'whatsapp_verified' => \NotificationChannels\Zapmizer\Models\WhatsappVerified::class,
        'connection' => \NotificationChannels\Zapmizer\Models\ZapmizerConnection::class,
    ],
    'routes' => [
        'enabled' => env('ZAPMIZER_ROUTES_ENABLED', true),
        'prefix' => 'zapmizer',
        'middleware' => ['web', 'auth'],
        // The webhook route is public and stateless. The package verifies the
        // delivery signature itself; add e.g. a throttle here. If you rely on
        // `verify_number.*` (unsigned by construction), an IP allowlist here
        // is the only thing that stops a forged confirmation.
        'webhook_middleware' => [],
    ],
];
