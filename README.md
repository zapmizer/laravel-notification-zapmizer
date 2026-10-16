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
- [Errors](docs/erros.md)
