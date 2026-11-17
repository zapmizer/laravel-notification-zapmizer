# Zapmizer WhatsApp notification channel for Laravel 8 to 13

## Install with an agent

Prompt for a coding agent (Claude Code, Cursor, Codex...):

```text
Instale a lib zapmizer/laravel-notification-zapmizer neste app, com o connect e a caixa de entrada embutida. Siga o roteiro de docs/instalar.md da lib.
```

The commands, in order (Inertia + Vue 3 app; the guide covers Blade):

```bash
composer require "zapmizer/laravel-notification-zapmizer:^2.0"
php artisan zapmizer:install --base-uri=https://app.zapmizer.com/api/ --model=App\\Models\\User --inbox --no-interaction   # or https://app.parlichat.com/api/
php artisan migrate
npm install github:zapmizer/embed#v0.1.3 axios lucide-vue-next   # or bun/pnpm/yarn add, as the install output says
php artisan zapmizer:check --json
```

Then mount `resources/js/zapmizer/InboxScreen.vue` (all the components sit in that one folder and import each other by relative paths, whatever the app's alias) on an authenticated page and rebuild the front: the embed brand (`VITE_ZAPMIZER_BRAND`, written by the installer) must be the one of the deploy `ZAPMIZER_BASE_URI` points at (`parli` for app.parlichat.com; another host is asked at `/brand/manifest.json`, and if it does not tell, ask the human), or the inbox never loads. Only a human knows the partner account's API (`--base-uri`: app.zapmizer.com or app.parlichat.com), has the partner credentials (`ZAPMIZER_PARTNER_ID`, `ZAPMIZER_PARTNER_SECRET`) and can get the app's host allowed on the partner account: ask, never make them up. The published components come unstyled (only `zapmizer-*` hook classes, no CSS): the app adapts them to its own look with its design system or UI kit, editing the files in `resources/js/zapmizer/` freely (`zapmizer:install --force` overwrites them). Step by step, with what to ask and when to stop: [docs/instalar.md](docs/instalar.md) (Portuguese).

> Full guides live in [`docs/`](docs/README.md). The [wiki](https://github.com/zapmizer/laravel-notification-zapmizer/wiki) is a mirror of `docs/` on `main`; edit `docs/`, not the wiki.

Send WhatsApp messages from Laravel through [Zapmizer](https://app.zapmizer.com): a `zapmizer` notification channel and a `ZapmizerMessage` builder for text and files. On top of that, optional pieces you turn on as you need them: each model (a `Team`, a `User`) connects its own WhatsApp number in a hosted popup and receives messages on a signed webhook (`Connectable`), the conversation or the inbox opens embedded in your app (`EmbedSessionResponse`), and users verify their number (`MustVerifyWhatsapp`). Every exception of the package extends `ZapmizerException`, and the sending, connect and embed calls go through Laravel's `Http`, so `Http::fake()` covers them in tests; for the sends, `NotificationChannels\Zapmizer\Facades\Zapmizer::fake()` catches every one, whatever the path, with `assertSent()` and friends (see [docs/envio.md](docs/envio.md#testes)).

## Requirements

- PHP 8.1 or later.
- Laravel 8.83 to 13 (8.83+, 9.52+, 10, 11, 12 or 13).
- Zapmizer 1.148.10 or later.
- The `curl` extension is suggested: without it the 600 s total limit of media downloads is applied per read.

## Installation

```bash
composer require zapmizer/laravel-notification-zapmizer
```

Publish the config file:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=config
```

Upgrading from 0.x? Read the upgrade lists at the top of the [CHANGELOG](CHANGELOG.md) first ("Upgrade das versões 0.0, 0.1 e 0.2" and "Upgrade da 0.3 e da 0.4"): this version breaks the 0.x API, and a published `config/zapmizer.php` does not get the new keys by itself.

**Routes are opt-in.** The package registers no route until you switch a group on: `ZAPMIZER_ROUTES_CONNECT`, `ZAPMIZER_ROUTES_WEBHOOK`, `ZAPMIZER_ROUTES_INBOX`, `ZAPMIZER_ROUTES_VERIFY_NUMBER` (all `false` by default; `zapmizer:install` switches on what its flags ask for). An app that only sends needs none. The webhook route only receives deliveries; whether the connect asks Zapmizer for a webhook on each user's connection is `ZAPMIZER_CONNECT_WEBHOOK` (unset, it follows the route). See [the route groups](docs/instalar.md#as-rotas-da-lib).

## Setting up your Zapmizer account

1. [Create an API token](https://app.zapmizer.com/user/api-tokens).
2. Set the token and the number that sends:

```env
ZAPMIZER_API_TOKEN="your-api-token"
ZAPMIZER_FROM_NUMBER="558181643260"
```

That is the single-tenant setup: one token and one number for the whole application. To give each model its own number, see the connect guide below.

**API version.** Set `ZAPMIZER_API_VERSION=2025-06-27` to track what you send (`2026-10-20` works the same from Zapmizer 1.148.17 on; an older Zapmizer only knows `2025-06-27` for sending). The routes that read messages do not follow it: `syncMessages()` and `createWebhook()` always ask for the message contract, `2026-10-20`.

**Receiving messages (single-tenant).** Create the webhook on Zapmizer's "Webhooks" screen in version **`2026-10-20`** (the message contract; `2024-02-27` also works, translated into the same `Message`), point it at `https://your-app/zapmizer/webhook`, put its secret in `ZAPMIZER_WEBHOOK_SECRET` and switch the route on with `ZAPMIZER_ROUTES_WEBHOOK=true` (add `ZAPMIZER_CONNECT_WEBHOOK=false` if the app also connects users' numbers and only wants this route for its own webhook, such as the `verify_number.*` events). Received messages fire `MessageReceived`, and what the number sends fires `MessageSent`. See [Receiving messages](docs/connect.md#6-receiving-messages-the-signed-webhook).

## Documentation

The guides live in [docs/](docs/README.md), in the order you will need them:

1. [Sending notifications](docs/envio.md) (Portuguese)
2. [Errors](docs/erros.md) (Portuguese)
3. [Connecting a number per model (multi-tenant)](docs/connect.md)
4. [Embedding the conversation and the inbox](docs/embed.md) (Portuguese)
5. [Number verification](docs/verify-number.md)
6. [HTTP transport, timeouts and tests](docs/transporte.md) (Portuguese)

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
