## Zapmizer (WhatsApp)

- `zapmizer/laravel-notification-zapmizer` sends WhatsApp through Zapmizer: the `zapmizer` notification channel, a WhatsApp number per model (`Connectable` and the hosted connect popup) and the embedded inbox (the `zapmizer.inbox.*` routes plus `@zapmizer/embed` in the front).
- Always activate the `zapmizer-whatsapp` skill when installing or configuring the package, connecting a WhatsApp number, adding the inbox to a screen, or sending WhatsApp messages.
- Install with `php artisan zapmizer:install` and verify with `php artisan zapmizer:check --json`. Never invent `ZAPMIZER_PARTNER_ID` or `ZAPMIZER_PARTNER_SECRET`: ask the human.
