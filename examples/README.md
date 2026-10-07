# Examples

Application code that uses the package, as an app would write it.

- `transport/ZapmizerFakeTest.php` - fake Zapmizer calls with `Http::fake()` (a message send, a refused send, a connect session and a media download) by sending through `LaravelHttpTransport`.
- `transport/TracingTransport.php` - a custom transport that adds a request id header and logs method, URL and status.
- `media/QueueInboundMedia.php` - a `MessageReceived` listener that, for a message with media, dispatches the job below. No HTTP: the webhook answers at once.
- `media/StoreInboundMedia.php` - the queued job: asks Zapmizer for the media once, releases itself while it is `downloading` or rate limited, and stores the file on a filesystem disk.

These files use app namespaces (`App\`, `Tests\`) and are autoloaded and run by this package's test suite (`vendor/bin/phpunit`, suite `Examples`), so they break when the package API changes. Copy them into your app and adjust.
