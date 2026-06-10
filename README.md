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
