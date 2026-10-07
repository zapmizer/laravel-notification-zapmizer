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


## Usage

In every Notification you wish to notify via WhatsApp, you must add a toZapmizer function and add 'zapmizer' drive into via's array:
```php
    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'zapmizer'];
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toZapmizer(object $notifiable)
    {
        $message = 'This is a message!' . PHP_EOL;

        //WID must follow the WhatsApp pattern, example: 558181643260; 558181643260@c.us 128172192@g.us(groups)

        return ZapmizerMessage::create(from: config('zapmizer.from_number'), to: $notifiable->wid)->type('chat')->text($message)->send();
    }
```

## Number verification

Mirroring Laravel's `MustVerifyEmail`: implement an interface and use a trait on your `User` model, and it gains the verification methods you'd expect. The user verifies through a hosted page on the Zapmizer domain — they message the team's WhatsApp number via wa.me and type back the code the bot replies with. Your app confirms the code (hosted page or your own input) and/or receives the terminal state on the team's webhooks. State lives in the package's own tables — your `users` table is never touched.

```php
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp as MustVerifyWhatsappContract;
use NotificationChannels\Zapmizer\MustVerifyWhatsapp;

class User extends Authenticatable implements MustVerifyWhatsappContract
{
    use MustVerifyWhatsapp;
}
```

```blade
<a href="{{ route('zapmizer.verify_number') }}">Verify your WhatsApp</a>
```

```php
$user->hasVerifiedWhatsapp(); // true after the user completes the hosted page
```

**See the full setup guide — credentials, env vars, migrations, User model, signed return URL, confirmation webhook and events — in [docs/verify-number.md](docs/verify-number.md).**

## Connecting a number per team (multi-tenant)

Mirroring Cashier's `Billable`: put the `Connectable` trait on the model that owns a WhatsApp number (a `Team`, a `User`, ...) and it gets its own Zapmizer connection. The whole flow happens in one hosted popup on Zapmizer — authorization, QR code pairing, webhook registration — and the callback lands with everything: no wizard, no QR code, no polling on your side. A connect button and a connection panel ship as publishable Inertia + Vue stubs. Inbound messages arrive signed on the package's webhook and fire `MessageReceived` with the connection that received them.

```php
use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;
use NotificationChannels\Zapmizer\Contracts\Connectable;

class Team extends Model implements Connectable
{
    use ConnectsZapmizer;
}

$team->zapmizerMessage('5511999999999')->text('Hello')->send();
```

```php
Event::listen(function (MessageReceived $event) {
    $event->message->fromPhone;       // who wrote
    $event->message->body;
    $event->connection?->connectable; // the Team — null when the delivery was
                                      // signed with the single-tenant secret
});
```

Bot events (`message`, ...) are only accepted signed. Single-tenant applications set `ZAPMIZER_WEBHOOK_SECRET` to the secret of the webhook they registered on Zapmizer; connected models store their own.

**See [docs/connect.md](docs/connect.md) for the setup: partner credentials, the resolver, routes and statuses, the Vue stubs, the signed webhook and secret rotation. Requires Zapmizer 1.149.0 or later (hosted pairing); receiving media (`$connection->media($message)`) requires 1.150.0 or later.**

## Examples

See [examples/](examples/README.md) for app code that uses the package: faking calls in tests, a custom transport and storing inbound media. The examples run in this package's test suite.

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

## HTTP transport

`PartnerClient`, `InstanceClient` and the messages client (`Zapmizer`, which `ZapmizerMessage::send()` uses) send their requests through a transport chosen in `config/zapmizer.php`:

```php
'http' => [
    'transport' => \NotificationChannels\Zapmizer\Connect\Transports\GuzzleTransport::class,
    'connect_timeout' => env('ZAPMIZER_HTTP_CONNECT_TIMEOUT'),
    'timeout' => env('ZAPMIZER_HTTP_TIMEOUT'),
],
```

The default is `GuzzleTransport`. Point it at `LaravelHttpTransport::class` to send through Laravel's `Http` client, so `Http::fake()`, `Http::preventStrayRequests()` (Laravel 9 and later) and `Http::globalMiddleware()` (Laravel 10 and later) see the calls.

### Timeouts

Both default to `null`: no limit with Guzzle, the `Http` client default with Laravel. Only a numeric value greater than zero counts; empty, `0` or non-numeric text counts as no value, that is, `null`. Do not use `0` for "no limit". We recommend `ZAPMIZER_HTTP_CONNECT_TIMEOUT=10` and `ZAPMIZER_HTTP_TIMEOUT=30`. These config values win over the timeout of a `GuzzleHttp\Client` you registered in the container.

Sending a message with a file (`sendMessageWithFile()`, `ZapmizerMessage::image()`/`document()`) ignores them: the upload uses 60 s to connect and 600 s in total (`Zapmizer::UPLOAD_CONNECT_TIMEOUT` / `UPLOAD_TIMEOUT`), so a short timeout meant for the JSON calls does not cut it. A text message follows the config.

### Media downloads

Media downloads ignore those timeouts: they use fixed limits of 60 s to connect and 600 s in total (`InstanceClient::MEDIA_CONNECT_TIMEOUT` / `MEDIA_TIMEOUT`). Without the `curl` extension, Guzzle falls back to its stream handler and the `timeout` applies per read instead. The body is written straight to a file (`sink`), so Telescope and any `ResponseReceived` listener that calls `body()` will load the whole media into memory. To change these limits, extend the transport and adjust the options it receives; the media call is the one that carries `sink`, or the URL `/whatsapp-messages/media`.

If the temporary directory is not writable and `LaravelHttpTransport` has a global middleware that reads the body, the media `202`/`404` answers become `unexpectedResponse` and the `422` comes out with an empty reason. This is rare.

### Your own transport

Implement `NotificationChannels\Zapmizer\Contracts\Transport`:

```php
public function send(string $method, string $url, array $options = []): ResponseInterface;
```

The rules:

- Options use Guzzle names: `headers`, `json`, `query`, `form_params`, `multipart`, `sink`, `stream`, `timeout`, `connect_timeout`. Sending a text message uses `form_params`; sending a file uses `multipart`, with the file as an open resource.
- Honor `sink` whenever it comes.
- Never follow redirects. With a refused token Zapmizer redirects to the login page; followed, that becomes an HTML `200` and a message lost in silence.
- Return any status; do not throw on 4xx or 5xx.
- Throw `ZapmizerUnavailableException` when there is no response at all.
- The constructor parameters `?float $connectTimeout` and `?float $timeout` receive the config values.

Set the class in `zapmizer.http.transport`. If you register the class in the container yourself, the timeout config does not apply: whoever registered it defines its timeouts. Binding `Contracts\Transport` directly works too.

An app that already published `config/zapmizer.php` with an `http` key and wants to switch transport must add `'transport' => ...` inside it: the package merges only the first level of the config, so the new key does not arrive by itself. Without it, the default `GuzzleTransport` is used.

An invalid `zapmizer.http.transport` throws `InvalidArgumentException` when the transport is resolved: a class that does not implement `Contracts\Transport`, the interface itself, or a value that is not a string. `null` or an empty string fall back to `GuzzleTransport`.

`GuzzleTransport` and `LaravelHttpTransport` are not `final`; extend either one.

### Building a client by hand

Resolve the clients from the container (`app(PartnerClient::class)`, `$connection->instanceClient()`, `app(Zapmizer::class)`, `$connection->zapmizer()`) to get the configured transport. Built by hand, they take the transport as an argument; without one they use a plain `GuzzleTransport` and ignore the config:

```php
new PartnerClient($partnerId, $partnerSecret, $transport, $apiBaseUri);
new InstanceClient($token, $transport, $apiBaseUri, $apiVersion);
new Zapmizer($token, $transport, $apiBaseUri, $apiVersion);
```
