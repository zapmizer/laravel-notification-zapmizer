---
name: zapmizer-whatsapp
description: Install and use zapmizer/laravel-notification-zapmizer (WhatsApp through Zapmizer) in a Laravel app. Activate when installing or configuring the package, connecting a WhatsApp number for a user or team (the connect popup, Connectable), adding the embedded WhatsApp inbox to a screen (InboxScreen, @zapmizer/embed, zapmizer.inbox routes), sending WhatsApp messages or notifications through the zapmizer channel, or when zapmizer:check reports a failure.
license: MIT
metadata:
  author: Zapmizer
---

# Zapmizer WhatsApp

## When to activate

- Installing the package, or "add WhatsApp" / "conectar WhatsApp" / "instalar a zapmizer" in a Laravel app.
- Connecting a WhatsApp number for a user or a team.
- Adding the WhatsApp inbox (caixa de entrada) to a screen.
- Sending a WhatsApp message or a notification through the `zapmizer` channel.
- `php artisan zapmizer:check` fails, or the connect/inbox answers an error code.

The full guide (Portuguese) is `docs/instalar.md` in the package: `vendor/zapmizer/laravel-notification-zapmizer/docs/instalar.md`.

## What only the human has

Ask one thing at a time, and only what the app does not tell you:

1. The API of the partner account (`ZAPMIZER_BASE_URI`): `https://app.zapmizer.com/api/` or `https://app.parlichat.com/api/` (or another one, e.g. local). The account, its credentials and the embed brand belong to one deploy. When the URL is neither of those two, also ask the deploy's brand (`zapmizer` or `parli`): the installer tries to read it from the deploy itself, but only the human knows it when the deploy does not tell.
2. The partner credentials, `ZAPMIZER_PARTNER_ID` (digits only) and `ZAPMIZER_PARTNER_SECRET`. Zapmizer issues them per partner application. Never invent them, never commit them: they go in `.env` only.
3. The app's host allowed on the partner account (it goes in the connect's `redirect_uri` and `webhook_url`, and in the inbox's `parent_origin`). Zapmizer's team allows it. Production needs `https` and a public host.
4. Which model owns the number: the logged-in user (default) or another model such as the current team.

## Install

```bash
composer require "zapmizer/laravel-notification-zapmizer:^2.0.0-beta.6@beta"
php artisan zapmizer:install --base-uri=https://app.parlichat.com/api/ --model=App\\Models\\User --inbox --no-interaction
php artisan migrate
```

- Pass the base URI before anything else (`--base-uri`, or `ZAPMIZER_BASE_URI` already in `.env`): the embed brand comes from it. A value already in `.env` is never overwritten; a different `--base-uri` makes the command fail.
- `zapmizer:install` is idempotent. It publishes `config/zapmizer.php` and the `zapmizer_connections` migration, adds only the missing `ZAPMIZER_*` keys to `.env` and `.env.example` (partner keys empty), makes the model `implements Connectable` + `use ConnectsZapmizer;`, switches on the route groups the flags ask for (`--model`: `ZAPMIZER_ROUTES_CONNECT=true`; `--webhook`: `ZAPMIZER_ROUTES_WEBHOOK=true`; `--inbox`: `ZAPMIZER_ROUTES_INBOX=true` plus the connect), and with `--inbox` copies the Vue components to `resources/js/zapmizer/` (one folder of their own, relative imports between them).
- Follow the "Próximos passos" it prints. If it says it did not recognize the model file, paste the snippet it printed.
- Every package route group is off by default: no flag, no route. Add `--webhook` when the app must receive messages; without it the connections only send (`zapmizer:check` warns). An app that only sends needs no group.
- Drop `--inbox` when the app has no Vue; the guide has the complete Blade path (connect + inbox + logout).
- `@zapmizer/embed/vue` needs Vue >= 3.3.

### A model other than the logged-in user

The connect and the inbox find the owner through `zapmizer.connect.resolver` (default: the logged-in user). For a team:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

class ResolvesCurrentTeam implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        return $request->user()?->currentTeam
            ?? throw ZapmizerConnectException::noConnectable('The user has no current team.');
    }
}
```

Then `'connect' => ['resolver' => App\Zapmizer\ResolvesCurrentTeam::class, ...]` in `config/zapmizer.php`.

## Front: connect + inbox (Inertia + Vue 3)

1. Install the embed with the app's package manager (the install output prints the exact command): `npm install github:zapmizer/embed#v0.1.3 axios lucide-vue-next` (or `bun add` / `pnpm add` / `yarn add`). Always pin the tag.
2. Mount the screen on an authenticated page, inside the app's own layout, with a height. The components live in `resources/js/zapmizer/`, a folder of their own whose files import each other by relative paths: import them the way the app already imports from `resources/js` (`@/zapmizer/...`, `~/zapmizer/...` or a relative path), and do not move them or add an alias for them.

```vue
<script setup lang="ts">
import InboxScreen from '@/zapmizer/InboxScreen.vue';
</script>

<template>
  <div style="height: calc(100vh - 8rem)">
    <InboxScreen />
  </div>
</template>
```

   Without a connection it shows the connect button; once connected, the inbox opens. Do not pass `person`: the screen gets it from the server (`person` of `GET zapmizer.connect.show`, built from the logged-in user's `getAuthIdentifier()` and the model the resolver returns), so it never depends on how the app shares its user (`id`, `user_id`, ...).
3. Logout (Inertia 1+/2; with Inertia 0.x use `import { Inertia } from '@inertiajs/inertia'` and `Inertia.on('before', ...)`): call `listenToZapmizerLogout()` once at app boot and `endZapmizerEmbeds()` before the logout request (both in `resources/js/zapmizer/zapmizerEmbed.ts`). With Inertia:

```ts
import { router } from '@inertiajs/vue3';
import { endZapmizerEmbeds, listenToZapmizerLogout } from '@/zapmizer/zapmizerEmbed';

listenToZapmizerLogout();
router.on('before', (event) => {
  if (event.detail.visit.method === 'post' && event.detail.visit.url.pathname === '/logout') {
    endZapmizerEmbeds();
  }
});
```

4. Build the front (`npm run build` or the app's equivalent) to make sure it compiles. The build bakes in `VITE_ZAPMIZER_BRAND` from `.env`.

### The embed brand

The iframe tags its events with `source: "<brand>-embed"` and `@zapmizer/embed` drops any other, so the front's brand must be the one of the Zapmizer deploy `ZAPMIZER_BASE_URI` points at: `zapmizer` for `app.zapmizer.com` (default), `parli` for `app.parlichat.com`. Any other host (a local server, a staging) is asked for its brand at `{web root}/brand/manifest.json` (the base URI without `/api`; its `name` is `Parli` or `Zapmizer`): the installer writes what it reads to `VITE_ZAPMIZER_BRAND` and `ZAPMIZER_EMBED_BRAND`, and when the deploy does not answer it writes `zapmizer` and tells you to confirm the brand with the human. `zapmizer:install` writes `VITE_ZAPMIZER_BRAND` with the derived brand (only if missing); `ZAPMIZER_EMBED_BRAND` overrides the backend's derivation. A wrong brand shows as an inbox that never gets `ready` (`ready_timeout`); `zapmizer:check` reports it as `embed_brand`. After changing either value, rebuild the front.

## Verify

```bash
php artisan zapmizer:check --json
```

It checks only what is on: the send always (`api_token`, `send_api`, `from_number`, `base_uri`), and the connect, webhook, inbox and verify-number checks only with their route group on. A group that is off shows as `off`, with the hint to switch it on, and is neither a failure nor a warning: an app that only sends passes with every group `off`. Each check has `id`, `status` (`ok`, `fail`, `warn`, `skip`, `off`), `message` and `hint`; the exit code is non-zero only when something is `fail`. The remote calls are read-only: `GET /api/bot-instances` with `ZAPMIZER_API_TOKEN` when it is set (401 = token refused) and, with the connect on, `GET /api/partner/users/zapmizer-check` with the partner key (401 = key refused). `--offline` skips them.

## When to stop and ask the human

- `partner_credentials` fails: ask for the credentials. Never invent them.
- `api_token` or `send_api` fails: ask the human for the team's API token (`ZAPMIZER_API_TOKEN`). Never invent it.
- `partner_api` fails with "a rota de parceiro não existe": `ZAPMIZER_BASE_URI` is not the API (missing `/api`?). Confirm the deploy with the human.
- `partner_api` fails (401) or the connect answers `partner_unauthorized`: the credentials are wrong or from another environment.
- The inbox says the address is not allowed (`origin_not_allowed`), or the connect fails on the host: the host is not on the partner allowlist.
- `embed_brand` fails: set `VITE_ZAPMIZER_BRAND` to the brand the hint gives and rebuild; ask the human only if the `ZAPMIZER_BASE_URI` itself is in doubt.
- `embed_brand` warns that the host is not a known deploy: ask the human the deploy's brand, then set `VITE_ZAPMIZER_BRAND` and `ZAPMIZER_EMBED_BRAND` to it and rebuild.
- The inbox falls back to the connect button ("A conexão com o WhatsApp precisa ser refeita"): the token was revoked, no longer decrypts, or predates the inbox (`missing_ability`, `not_a_partner_connection`, answered as `422 reauth_required`). The user connects again.
- Connecting the number itself (popup, approval and picking or pairing the number) is the human's step. Testing it in development against a production deploy (app.zapmizer.com, app.parlichat.com) needs a public https URL (a tunnel) allowed on the partner account; against a local Zapmizer, http works.

## Sending

```php
// From the number a Connectable model paired:
$user->zapmizerMessage('5511999999999')->text('Olá!')->send();

// In a Notification:
public function via(object $notifiable): array
{
    return ['zapmizer'];
}

public function toZapmizer(object $notifiable)
{
    // From the number the team paired, to the customer's WhatsApp.
    return $this->team->zapmizerMessage($notifiable->whatsapp)->text('Seu pedido saiu.')->send();
}
```

`send()` returns a `Messages\SentMessage`; keep `$sent->widTo` as the contact key. Every exception extends `ZapmizerException`. Tests: `Zapmizer::fake()` (import `NotificationChannels\Zapmizer\Facades\Zapmizer`, not the client class of the same short name) catches every send of the package, the ones through a connected model included, with `assertSent(fn (FakeSend $send) => ...)`, `assertSentTimes()`, `assertNotSent()`, `assertNothingSent()`, `sent()` and `failWith()`/`failWithApiError()` for the error paths; `Http::fake()` covers the other calls. See `docs/envio.md` (Testes).

## Inbox routes (reference)

- `POST zapmizer.inbox.session` (`/zapmizer/inbox/session`, `web` + `auth`, `throttle:30,1`): `200 {url, origin, resume_url, resume_until}`, or `{code}`: `409 not_connected`, `403 no_connectable`, `422 reauth_required` (also for Zapmizer's `403 missing_ability`/`not_a_partner_connection`), `429 rate_limited`, `503 unavailable`, or Zapmizer's own code.
- `GET zapmizer.inbox.keepalive` (`/zapmizer/inbox/keepalive`): `204`.
- Behind a proxy without TrustProxies, set `ZAPMIZER_INBOX_PARENT_ORIGIN=https://your-app.com`.
