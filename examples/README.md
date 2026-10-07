# Examples

Application code that uses the package, as an app would write it.

- `transport/ZapmizerFakeTest.php` - fake Zapmizer calls with `Http::fake()` (a connect session and a media download) by sending through `LaravelHttpTransport`.
- `transport/TracingTransport.php` - a custom transport that adds a request id header and logs method, URL and status.
- `media/StoreInboundMedia.php` - a `MessageReceived` listener that waits for an inbound message's media and stores it on a filesystem disk.

These files use app namespaces (`App\`, `Tests\`) and are autoloaded and run by this package's test suite (`vendor/bin/phpunit`, suite `Examples`), so they break when the package API changes. Copy them into your app and adjust.
