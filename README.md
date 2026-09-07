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
