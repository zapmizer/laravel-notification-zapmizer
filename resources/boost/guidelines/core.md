## Zapmizer (WhatsApp)

- `zapmizer/laravel-notification-zapmizer` sends WhatsApp through Zapmizer: the `zapmizer` notification channel, a WhatsApp number per model (`Connectable` and the hosted connect popup) and the embedded inbox (the `zapmizer.inbox.*` routes plus `@zapmizer/embed` in the front).
- Always activate the `zapmizer-whatsapp` skill when installing or configuring the package, connecting a WhatsApp number, adding the inbox to a screen, or sending WhatsApp messages.
- The package routes are opt-in by group (`ZAPMIZER_ROUTES_CONNECT`, `ZAPMIZER_ROUTES_WEBHOOK`, `ZAPMIZER_ROUTES_INBOX`, `ZAPMIZER_ROUTES_VERIFY_NUMBER`, all off by default): an app that only sends gets none. The webhook route only receives deliveries; whether the connect asks for a webhook on each user's connection is `ZAPMIZER_CONNECT_WEBHOOK` (unset, it follows the route).
- The Vue components `zapmizer:install --inbox` publishes to `resources/js/zapmizer/` are unstyled (only `zapmizer-*` hook classes): adapt them to the app's own design system or UI kit; `--force` overwrites them.
- Install with `php artisan zapmizer:install` and verify with `php artisan zapmizer:check --json`. Never invent `ZAPMIZER_PARTNER_ID` or `ZAPMIZER_PARTNER_SECRET`: ask the human.
