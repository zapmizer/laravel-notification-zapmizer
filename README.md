# Zapmizer WhatsApp notification channel for Laravel 11

## Installation

You can install the package via composer:

```bash
composer require zapmizer/laravel-notification-zapmizer
```

Now publish config file
```bash
php artisan vendor:publish --provider="Notification\Zapmizer\ZapmizerServiceProvider" --tag=config --force
```


### Setting up your Zapmizer account
1. [Create a API TOKEN.](https://app.zapmizer.com/user/api-tokens)
2. Paste your API token  in your `zapmizer.php` config file.
3. Add environment viariables with values
```php
    ZAPMIZER_API_TOKEN="your-api-token"
    ZAPMIZER_FROM_NUMBER="558181643260"
    # Verify-number APIs use their own credentials, not the API token:
    ZAPMIZER_SECRET_KEY="sk_your-secret-key"          # hosted page sessions (server-side)
    ZAPMIZER_PUBLISHABLE_KEY="pk_your-publishable-key" # widget API (optional)
    ZAPMIZER_WEBHOOK_SECRET="whsec_your-webhook-secret"
    ZAPMIZER_RETURN_URL="https://your-app.com/whatsapp/verified"
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

Mirroring Laravel's `MustVerifyEmail`, add an interface and a trait to your `User` model and you get the methods you'd expect. The verification state lives in the package's own `whatsapp_verifieds` table (1:1 with the user) — your `users` table is never touched.

First publish and run the migration:

```bash
php artisan vendor:publish --provider="NotificationChannels\Zapmizer\ZapmizerServiceProvider" --tag=migrations
php artisan migrate
```

Then set up your model:

```php
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp as MustVerifyWhatsappContract;
use NotificationChannels\Zapmizer\MustVerifyWhatsapp;

class User extends Authenticatable implements MustVerifyWhatsappContract
{
    use MustVerifyWhatsapp;

    // By default the number is read from the `whatsapp_number` attribute.
    // Override this if it lives somewhere else:
    public function getWhatsappNumberForVerification(): ?string
    {
        return $this->phone;
    }
}
```

The default flow uses the hosted verification page (Stripe billing-portal style): you create a session server-side with the secret key, redirect the user to the hosted page on the Zapmizer domain — where the whole thing happens (wa.me link, code) — and they come back to your `return_url` with signed query params.

```php
// 1. Start a verification: creates a hosted session, records the state as
//    "awaiting" and returns the hosted page link to redirect the user to.
//    The return URL defaults to config('zapmizer.return_url').
$url = $user->startWhatsappVerification();

$user->hasVerifiedWhatsapp(); // false while awaiting

// 2. The user completed the hosted page and came back to your return_url
//    with ?verify_session=...&status=verified&sig=t=...,v1=...
//    Validate the signature with your webhook secret, then mark verified:
use NotificationChannels\Zapmizer\Support\ZapbotSignature;

if (ZapbotSignature::isValidQuery($request->query(), config('zapmizer.webhook_secret'))
    && $request->query('status') === 'verified') {
    $user->markWhatsappAsVerified();
}

$user->hasVerifiedWhatsapp(); // true
```

The same `ZapbotSignature::isValid($header, $rawBody, $secret)` validates the `X-Zapbot-Signature` header of server-to-server webhooks (note: Zapbot only delivers webhooks to public https URLs).

### Confirmation webhook

The package also registers a public, stateless `POST /zapmizer/webhook` route (named `zapmizer.webhook`) that receives Zapbot's server-to-server confirmations — point the verification's webhook URL at it. The endpoint:

- rejects anything not signed with your webhook secret (`X-Zapbot-Signature`);
- correlates the event to the verification by the `client_reference` the package sent when starting it (the user's key) — never by phone number;
- is idempotent: redeliveries of the same `event_id` are acknowledged without applying the effect twice;
- on confirmation, marks the number as verified and fires the `NotificationChannels\Zapmizer\Events\WhatsappVerified` event; failure/expiration events update the state to `failed`.

Listen to the event to react in your app:

```php
use NotificationChannels\Zapmizer\Events\WhatsappVerified;

Event::listen(function (WhatsappVerified $event) {
    $event->verification;        // the WhatsappVerified state record
    $event->verification->user;  // the verified user
});
```

Add a throttle (or anything else) to the webhook route via `zapmizer.routes.webhook_middleware`.

For the embedded-widget flow (pk_ key), the trait also exposes `confirmWhatsappVerification($code)` and `syncWhatsappVerificationStatus()` for polling.

The state record is available through `$user->whatsappVerification()` (a `WhatsappVerified` model with `status`, `verification_id`, `url`, `number` and `verified_at`). To extend the model, subclass `NotificationChannels\Zapmizer\Models\WhatsappVerified` and point the `zapmizer.models.whatsapp_verified` config key at your subclass.

### Built-in verification route

The package auto-registers a named GET route, `zapmizer.verify_number` (at `/zapmizer/verify-number` by default, behind the `web` and `auth` middleware). When an authenticated user hits it, a verification is started — recorded as "awaiting" — and they are redirected straight to the wa.me link (or back to the previous page with a `zapmizer.resolving` flash while the number is still resolving). So in the frontend all you need is a link:

```blade
<a href="{{ route('zapmizer.verify_number') }}">Verify your WhatsApp</a>
```

Prefix and middleware are configurable, and you can disable the routes entirely to mount your own:

```php
// config/zapmizer.php
'routes' => [
    'enabled' => env('ZAPMIZER_ROUTES_ENABLED', true),
    'prefix' => 'zapmizer',
    'middleware' => ['web', 'auth'],
],
```

### Verification client

The package also ships a client for the Zapbot verify-number API. Authentication uses the publishable key (`pk_...`, config `zapmizer.publishable_key` / `ZAPMIZER_PUBLISHABLE_KEY` — a different credential from the messages API token) sent as `X-Publishable-Key`, plus an `Origin` header that must be in the verification's allowed origins on the Zapbot side (defaults to your `app.url`; override with `ZAPMIZER_ORIGIN`).

The client is registered in the container, so you can inject it or resolve it directly:

```php
use NotificationChannels\Zapmizer\VerificationClient;

$client = app(VerificationClient::class);

// Start a verification session for a number (idempotent while active)
$verification = $client->create('5511999999999');

$verification->number;     // normalized number, used as the session identifier
$verification->status;     // resolving | pending | received | verified | expired | failed
$verification->waLink;     // wa.me link the user opens (null while resolving)
$verification->codeLength; // length of the code the user will receive

// Poll the latest session state for a number
$verification = $client->get('5511999999999');

// Confirm the code the user received on WhatsApp
$verification = $client->confirm('5511999999999', '123456');
$verification->isVerified(); // true
```

You can also override the configuration at runtime:

```php
$client = app(VerificationClient::class, ['publishable_key' => $tenant->zapmizer_pk, 'origin' => 'https://tenant.example.com']);
```

### Error handling

Timeouts and error responses from Zapmizer become typed, catchable exceptions — they never bubble up as loose Guzzle errors:

```php
use NotificationChannels\Zapmizer\Exceptions\VerificationConnectionFailed;
use NotificationChannels\Zapmizer\Exceptions\VerificationRequestFailed;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;

try {
    $verification = $client->create('558181643260');
} catch (VerificationRequestFailed $e) {
    // Zapmizer responded with a 4xx/5xx
    $e->getStatusCode();
    $e->getResponseBody();
} catch (VerificationConnectionFailed $e) {
    // Network failure: timeout, DNS error, connection refused...
} catch (ZapmizerVerificationException $e) {
    // Base class — catches both of the above plus missing token / unexpected payloads
}
```
