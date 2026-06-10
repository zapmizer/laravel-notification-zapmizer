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

Mirroring Laravel's `MustVerifyEmail`: implement an interface and use a trait on your `User` model, and it gains the verification methods you'd expect. The user verifies through a hosted page on the Zapmizer domain (wa.me link + code happen there) and your app receives the result back via a signed redirect and a signed webhook. State lives in the package's own tables — your `users` table is never touched.

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
