# Zapmizer WhatsApp notification channel for Laravel 8 to 13

> Full guides live in [`docs/`](docs/README.md). The [wiki](https://github.com/zapmizer/laravel-notification-zapmizer/wiki) is a mirror of `docs/` on `main`; edit `docs/`, not the wiki.

Send WhatsApp messages from Laravel through [Zapmizer](https://app.zapmizer.com): a `zapmizer` notification channel and a `ZapmizerMessage` builder for text and files. On top of that, optional pieces you turn on as you need them: each model (a `Team`, a `User`) connects its own WhatsApp number in a hosted popup and receives messages on a signed webhook (`Connectable`), the conversation or the inbox opens embedded in your app (`EmbedSessionResponse`), and users verify their number (`MustVerifyWhatsapp`). Every exception of the package extends `ZapmizerException`, and the sending, connect and embed calls go through Laravel's `Http`, so `Http::fake()` covers them in tests.

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

Upgrading from 0.3? Read the "Upgrade da 0.3" list at the top of the [CHANGELOG](CHANGELOG.md) first: this version breaks the 0.3 API, and a published `config/zapmizer.php` does not get the new keys by itself.

## Setting up your Zapmizer account

1. [Create an API token](https://app.zapmizer.com/user/api-tokens).
2. Set the token and the number that sends:

```env
ZAPMIZER_API_TOKEN="your-api-token"
ZAPMIZER_FROM_NUMBER="558181643260"
```

That is the single-tenant setup: one token and one number for the whole application. To give each model its own number, see the connect guide below.

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
