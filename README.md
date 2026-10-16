# Zapmizer WhatsApp notification channel for Laravel 8 to 13

## Installation

You can install the package via composer:

```bash
composer require zapmizer/laravel-notification-zapmizer
```

Now publish config file
```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=config --force
```


### Setting up your Zapmizer account
1. [Create a API TOKEN.](https://app.zapmizer.com/user/api-tokens)
2. Paste your API token  in your `zapmizer.php` config file.
3. Add environment viariables with values
```php
    ZAPMIZER_API_TOKEN="your-api-token"
    ZAPMIZER_FROM_NUMBER="558181643260"
```


## Documentation

The guides live in [docs/](docs/README.md):

- [Sending notifications](docs/envio.md)
- [Number verification](docs/verify-number.md)
- [Connecting a number per team (multi-tenant)](docs/connect.md): requires Zapmizer 1.149.0 or later; receiving media requires 1.150.0 or later
- [HTTP transport, timeouts and tests](docs/transporte.md)

## Errors

Every exception the package throws extends `NotificationChannels\Zapmizer\Exceptions\ZapmizerException`, so one `catch` takes them all:

```
ZapmizerException
├─ ZapmizerConnectException              connect flow and sending
│  ├─ ZapmizerApiException               Zapmizer refused the call (4xx)
│  │  ├─ ZapmizerRateLimitedException    429, retryAfter()
│  │  │  └─ MediaRateLimitedException
│  │  ├─ ZapmizerUnauthorizedException   401, or no token to send
│  │  ├─ PartnerCredentialsException     partner key refused (401/403)
│  │  ├─ MediaRejectedException          media request refused (422)
│  │  └─ InstanceGoneException           instance deleted (404)
│  ├─ ZapmizerUnavailableException       timeout, 5xx, no answer at all
│  └─ NoConnectableException             the resolver has nothing to connect
└─ ZapmizerVerificationException         verify-number
```

`ZapmizerApiException` carries what Zapmizer answered:

```php
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;

try {
    $team->zapmizerMessage('5511999999999')->text('Hello')->send();
} catch (ZapmizerUnauthorizedException $e) {
    // 401: the token was revoked, or there is none. Ask for a new connection.
} catch (ZapmizerRateLimitedException $e) {
    // 429: try again in $e->retryAfter() seconds (null when Zapmizer sent no Retry-After).
} catch (ZapmizerApiException $e) {
    if ($e->error() === ErrorCode::WINDOW_CLOSED) {
        $closedAt = $e->payload()['window_expires_at'] ?? null;
    }

    Log::warning($e->getMessage(), ['status' => $e->status(), 'errors' => $e->errors()]);
} catch (ZapmizerUnavailableException $e) {
    // Timeout or 5xx: try again later.
}
```

- `status()`: the HTTP status.
- `error()`: Zapmizer's code (`window_closed`, `bot_offline`, `recipient_not_found`, ...) or `null`. It is a plain string, so a code Zapmizer adds later reaches you without a new release; `ErrorCode` has a constant for each code the API documents.
- `reason()`: Zapmizer's `message` followed by the first error of each field; the raw body, cut at 500 characters, when there is neither.
- `errors()`: the validation errors by field, `array<string, string[]>`.
- `payload()`: the decoded JSON body, with the extra fields (`window_expires_at`, ...).
- `getMessage()`: `Zapmizer refused the request (HTTP 409, window_closed): <reason>.`

Failures that are not an answer from Zapmizer keep their own class and are not wrapped: a malformed `base_uri`, an invalid multipart field, `Http::preventStrayRequests()` in a test, or an exception thrown by your own transport.

None of these exceptions renders a response. The package's connect routes answer their JSON codes themselves (see `docs/connect.md`); anywhere else your exception handler decides, through `renderable()` (or `withExceptions()` from Laravel 11 on).
