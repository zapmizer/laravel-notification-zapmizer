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
    // null for the legacy shape. It decides the shape of sending
    // (2025-06-27 to track it; 2026-10-20 too, from Zapmizer 1.148.17) and
    // of the calls that do not read messages: syncMessages() and
    // createWebhook() always ask for the message contract (2026-10-20).
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

    'http' => [
        'transport' => \NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport::class,
        'connect_timeout' => env('ZAPMIZER_HTTP_CONNECT_TIMEOUT'),
        'timeout' => env('ZAPMIZER_HTTP_TIMEOUT'),
    ],

    'connect' => [
        // Who is being connected on a given request. Must implement
        // NotificationChannels\Zapmizer\Contracts\ResolvesConnectable — the
        // default returns the authenticated user; point it at the current
        // team (or anything else using the Connectable trait) as needed.
        'resolver' => \NotificationChannels\Zapmizer\Connect\ResolvesAuthenticatedUser::class,
        // How long the popup has to come back, in minutes. The hosted page may take
        // the customer through checkout, so keep it generous. Also sent as the
        // session's expires_in (Zapmizer's floor is 15 minutes).
        'state_ttl_minutes' => (int) env('ZAPMIZER_CONNECT_STATE_TTL', 30),
        // Revoke the token a connection held before when a new connect replaces it
        // (DELETE /connect/token), so the model keeps a single grant on Zapmizer.
        'revoke_previous_token' => env('ZAPMIZER_CONNECT_REVOKE_PREVIOUS_TOKEN', false),
        // The version of the webhook the connect creates, sent as the
        // session's `webhook_version` (Zapmizer 1.148.17 or later; an older
        // one ignores it). 2026-10-20 delivers messages in Zapmizer's message
        // contract; 2024-02-27 (deprecated) still works, translated. Empty
        // leaves it to the partner account's default; any other value throws
        // (Zapmizer would refuse it with a 422). A published
        // config/zapmizer.php must get this key added by hand.
        'webhook_version' => env('ZAPMIZER_WEBHOOK_VERSION', '2026-10-20'),
        // Whether the connect asks Zapmizer for a webhook on each user's
        // connection (the session's webhook_url). null follows routes.webhook
        // (the behaviour before 2.1). false: the connections made from then on
        // send, but receive nothing, while routes.webhook can stay on to
        // receive the app's own deliveries (verify_number.*). A value that is
        // not a boolean word reads as null. true with routes.webhook off: the app
        // mounts the zapmizer.webhook route itself. A published
        // config/zapmizer.php must get this key added by hand.
        'webhook' => env('ZAPMIZER_CONNECT_WEBHOOK'),
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
        // Give each connection made through the connect flow its own webhook URL
        // ({prefix}/webhook/{key}): the owner is found by the path, only its secret
        // is tested, and events of another number are discarded. Needs the
        // zapmizer-migrations-webhook-key migration, and is meant to run with
        // connect.revoke_previous_token = true (otherwise the old webhooks keep
        // delivering until revoked on Zapmizer). Connections made before keep using
        // the shared route. Do not turn it off while any connection uses its own
        // URL: its deliveries would get 404. A published config/zapmizer.php must
        // get this key added by hand.
        'per_connection' => env('ZAPMIZER_WEBHOOK_PER_CONNECTION', false),
        // Drop the repeats of a delivery by the envelope `id` (Zapmizer retries
        // with the same one): a repeat gets 200 and fires nothing. Uses the
        // Laravel cache (`deduplicate_store`, null = the default store). The id
        // is held for 120 s while the delivery runs, then for `deduplicate_ttl`
        // seconds (empty or <= 0: 21600) once it answered without a 5xx; a
        // failure (exception or 5xx) frees it for the retry. With the cache down
        // the delivery is processed and a warning logged. A published
        // config/zapmizer.php must get these keys added by hand.
        'deduplicate' => env('ZAPMIZER_WEBHOOK_DEDUPLICATE', false),
        'deduplicate_ttl' => (int) env('ZAPMIZER_WEBHOOK_DEDUPLICATE_TTL', 21600),
        'deduplicate_store' => env('ZAPMIZER_WEBHOOK_DEDUPLICATE_STORE'),
    ],

    'models' => [
        'whatsapp_verified' => \NotificationChannels\Zapmizer\Models\WhatsappVerified::class,
        'connection' => \NotificationChannels\Zapmizer\Models\ZapmizerConnection::class,
    ],
    // The package routes come in groups, each registered only when switched
    // on: an app that only sends gets no route. `zapmizer:install` switches
    // on the groups the app asked for. `enabled` is the master switch
    // (false: no package route, mount your own). A published
    // config/zapmizer.php without the group keys still gets them (the
    // provider fills `routes` in from here).
    'routes' => [
        'enabled' => env('ZAPMIZER_ROUTES_ENABLED', true),
        // Connect and reconnect, authenticated (`zapmizer.connect.*`).
        'connect' => env('ZAPMIZER_ROUTES_CONNECT', false),
        // The public, signed webhook (`zapmizer.webhook`, and the
        // per-connection one with `webhook.per_connection`). Off, the connect
        // creates connections without a webhook: they send, but receive
        // nothing. On, `webhook.secret` (ZAPMIZER_WEBHOOK_SECRET) signs it.
        'webhook' => env('ZAPMIZER_ROUTES_WEBHOOK', false),
        // The embedded inbox (`zapmizer.inbox.*`, see `inbox` below). Needs
        // the connect group.
        'inbox' => env('ZAPMIZER_ROUTES_INBOX', false),
        // The start of the number verification (`zapmizer.verify_number`).
        'verify_number' => env('ZAPMIZER_ROUTES_VERIFY_NUMBER', false),
        'prefix' => 'zapmizer',
        'middleware' => ['web', 'auth'],
        // The webhook route is public and stateless. The package verifies the
        // delivery signature itself; add e.g. a throttle here. If you rely on
        // `verify_number.*` (unsigned by construction), an IP allowlist here
        // is the only thing that stops a forged confirmation.
        'webhook_middleware' => [],
    ],

    // The embedded inbox, ready-made: two authenticated web routes under the
    // package prefix, POST {prefix}/inbox/session (`zapmizer.inbox.session`,
    // the `openSession` of @zapmizer/embed) and GET {prefix}/inbox/keepalive
    // (`zapmizer.inbox.keepalive`, 204). The session opens for the connection
    // of the model `connect.resolver` returns, with the logged-in user as the
    // attendant. Switched on by `routes.inbox` (ZAPMIZER_ROUTES_INBOX; `php
    // artisan zapmizer:install --inbox` turns it on). A published
    // config/zapmizer.php without this key still gets it (top-level merge).
    'inbox' => [
        'middleware' => ['web', 'auth'],
        // The page's public origin, sent as `parent_origin`. Empty: read from
        // the request (scheme, host and port). Set it when the app sits behind
        // a proxy it does not trust (no TrustProxies): the request then sees
        // http:// or the internal host, and Zapmizer answers
        // `origin_not_allowed`.
        'parent_origin' => env('ZAPMIZER_INBOX_PARENT_ORIGIN'),
        // The `brand` of @zapmizer/embed: the iframe tags its events with
        // `source: "{brand}-embed"` and each Zapmizer deploy has its own
        // (`parli` on app.parlichat.com). Empty: derived from the base_uri
        // host (Support\EmbedBrand). The front reads it at build time from
        // VITE_ZAPMIZER_BRAND, which must match; zapmizer:check compares.
        'brand' => env('ZAPMIZER_EMBED_BRAND'),
    ],
];
