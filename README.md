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

And use it:

```php
// Start a verification: records the state as "awaiting" and
// returns the hosted page link where the user completes it.
$url = $user->startWhatsappVerification();

$user->hasVerifiedWhatsapp(); // false while awaiting

// Typically called from the webhook that confirms the verification:
$user->markWhatsappAsVerified();

$user->hasVerifiedWhatsapp(); // true
```

The state record is available through `$user->whatsappVerification()` (a `WhatsappVerified` model with `status`, `verification_id`, `url`, `number` and `verified_at`). To extend the model, subclass `NotificationChannels\Zapmizer\Models\WhatsappVerified` and point the `zapmizer.models.whatsapp_verified` config key at your subclass.

### Built-in verification route

The package auto-registers a named GET route, `zapmizer.verify_number` (at `/zapmizer/verify-number` by default, behind the `web` and `auth` middleware). When an authenticated user hits it, a verification is started — recorded as "awaiting" — and they are redirected straight to the hosted page. So in the frontend all you need is a link:

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

The package also ships a client for the Zapmizer verification API. It can request a new verification for a phone number (returning the verification identifier, the hosted page link and the initial state) and fetch the state of an existing verification.

The client is registered in the container, so you can inject it or resolve it directly:

```php
use NotificationChannels\Zapmizer\VerificationClient;

$client = app(VerificationClient::class);

// Request a new verification for a number
$verification = $client->create('558181643260');

$verification->id;     // verification identifier
$verification->url;    // hosted page link to complete the verification
$verification->status; // initial state, e.g. "pending"

// Check the state of an existing verification
$verification = $client->get($verification->id);
$verification->status; // e.g. "verified"
```

You can also override the configuration at runtime:

```php
$client = app(VerificationClient::class, ['api_token' => $tenant->zapmizer_token]);
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
