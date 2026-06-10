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
    # Verify-number API uses its own credential (pk_...), not the API token:
    ZAPMIZER_PUBLISHABLE_KEY="pk_your-publishable-key"
    ZAPMIZER_WEBHOOK_SECRET="your-webhook-secret"
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

The flow is inverted compared to e-mail verification: the user opens a wa.me link, sends the opening message, receives a code on WhatsApp and types it back into your app.

```php
// 1. Start a verification: records the state as "awaiting" and returns the
//    wa.me link the user must open. While Zapbot is still resolving the
//    number this returns null — call syncWhatsappVerificationStatus()
//    shortly after to pick the link up from the state record.
$waLink = $user->startWhatsappVerification();

$user->hasVerifiedWhatsapp(); // false while awaiting

// 2. The user sent the message and received a code on WhatsApp.
//    A wrong code raises VerificationRequestFailed (422).
$user->confirmWhatsappVerification($request->input('code')); // true when verified

// Polling alternative — converge the local state with Zapbot (useful when
// webhooks can't reach you, e.g. local development):
$user->syncWhatsappVerificationStatus(); // 'awaiting' | 'verified' | 'failed' | null

// Or, from a webhook handler you trust:
$user->markWhatsappAsVerified();

$user->hasVerifiedWhatsapp(); // true
```

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
