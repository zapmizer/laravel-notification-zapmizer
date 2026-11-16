<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Support\Str;
use InvalidArgumentException;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\ServiceProvider;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Contracts\Foundation\Application;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Support\ConnectionWebhook;
use NotificationChannels\Zapmizer\Support\RouteGroups;
use Illuminate\Contracts\Foundation\CachesConfiguration;

class ZapmizerServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register()
    {
        // `app(Zapmizer::class, [...])` takes `api_token`, `base_uri` and
        // `api_version`, and for the HTTP side `timeout` / `connect_timeout`
        // (seconds, over `zapmizer.http.*`) or a ready `transport`.
        $this->app->bind(Zapmizer::class, fn (Application $app, $config) => new Zapmizer(
            Arr::get($config, 'api_token', config('zapmizer.api_token')),
            $this->transportFor($app, is_array($config) ? $config : []),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri')),
            Arr::get($config, 'api_version', config('zapmizer.api_version'))
        ));

        $this->app->bind(VerificationClient::class, fn (Application $app, $config) => new VerificationClient(
            Arr::get($config, 'api_token', config('zapmizer.api_token')),
            app(HttpClient::class),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri')),
            Arr::get($config, 'api_version', config('zapmizer.api_version'))
        ));

        $this->app->bindIf(Transport::class, fn (Application $app) => $this->makeTransport($app));

        $this->app->bind(PartnerClient::class, fn (Application $app, $config) => new PartnerClient(
            Arr::get($config, 'partner_id', config('zapmizer.partner.id')),
            Arr::get($config, 'partner_secret', config('zapmizer.partner.secret')),
            $app->make(Transport::class),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri'))
        ));

        $this->app->bind(InstanceClient::class, fn (Application $app, $config) => new InstanceClient(
            Arr::get($config, 'api_token') ?? throw ZapmizerUnauthorizedException::withoutResponse(
                'InstanceClient needs the connection token — resolve it through ZapmizerConnection::instanceClient().'
            ),
            $app->make(Transport::class),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri')),
            Arr::get($config, 'api_version', config('zapmizer.api_version'))
        ));

        $this->app->bind(ResolvesConnectable::class, fn (Application $app) => $app->make(
            config('zapmizer.connect.resolver', Connect\ResolvesAuthenticatedUser::class)
        ));

        Notification::resolved(static function (ChannelManager $service) {
            $service->extend('zapmizer', static fn ($app) => $app->make(ZapmizerChannel::class));
        });

        if ($this->app->runningInConsole()) {
            $this->registerResources();
            $this->registerCommands();
        }

        if ($this->isLumen() === false) {
            $this->mergeConfigFrom(__DIR__ . '/../config/zapmizer.php', 'zapmizer');
            $this->mergeRouteGroups();
        }
    }

    /**
     * The config merge is shallow: a `config/zapmizer.php` published before
     * the route groups has `routes` without their keys. Fill in the missing
     * ones from the package's (with their env), keeping the app's.
     */
    protected function mergeRouteGroups(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $routes = config('zapmizer.routes');
        $defaults = (require __DIR__ . '/../config/zapmizer.php')['routes'];

        config(['zapmizer.routes' => array_replace($defaults, is_array($routes) ? $routes : [])]);
    }

    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'zapmizer');

        $this->registerRoutes();
    }

    /**
     * Register the package routes, group by group: each only when switched
     * on in `zapmizer.routes.{group}` (all off by default), and none with
     * `zapmizer.routes.enabled` false (mount your own). See
     * Support\RouteGroups.
     *
     * @return void
     */
    public function registerRoutes()
    {
        $prefix = config('zapmizer.routes.prefix', 'zapmizer');
        $middleware = config('zapmizer.routes.middleware', ['web', 'auth']);

        if (RouteGroups::enabled(RouteGroups::VERIFY_NUMBER)) {
            Route::group(['prefix' => $prefix, 'middleware' => $middleware, 'as' => 'zapmizer.'], function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/verify-number.php');
            });
        }

        if (RouteGroups::enabled(RouteGroups::CONNECT)) {
            Route::group(['prefix' => $prefix, 'middleware' => $middleware, 'as' => 'zapmizer.'], function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
            });
        }

        if (RouteGroups::enabled(RouteGroups::INBOX)) {
            Route::group([
                'prefix' => $prefix,
                'middleware' => config('zapmizer.inbox.middleware', ['web', 'auth']),
                'as' => 'zapmizer.',
            ], function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/inbox.php');
            });
        }

        // The webhook is public and stateless — registered outside the
        // user-facing middleware on purpose. The signature check is on the
        // route itself (routes/webhook.php), not left to the config. The
        // per-connection route (`zapmizer.webhook.per_connection`) joins it,
        // same group.
        if (RouteGroups::enabled(RouteGroups::WEBHOOK)) {
            Route::group([
                'prefix' => $prefix,
                'middleware' => config('zapmizer.routes.webhook_middleware', []),
                'as' => 'zapmizer.',
            ], function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');

                if (ConnectionWebhook::enabled()) {
                    $this->loadRoutesFrom(__DIR__ . '/../routes/connection-webhook.php');
                }
            });
        }
    }

    /**
     * Register resources.
     *
     * @return void
     */
    public function registerResources()
    {
        if ($this->isLumen() === false) {
            $this->publishes([
                __DIR__ . '/../config/zapmizer.php' => config_path('zapmizer.php'),
            ], 'config');

            // One tag per flow, so an app publishes only the table it uses;
            // `migrations` still publishes both (compat). The per-connection
            // webhook key is optional and has only its own tag.
            $this->publishes([
                __DIR__ . '/../database/migrations/create_whatsapp_verifieds_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_whatsapp_verifieds_table.php'),
            ], ['migrations', 'zapmizer-migrations-verify']);

            $this->publishes([
                __DIR__ . '/../database/migrations/create_zapmizer_connections_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_zapmizer_connections_table.php'),
            ], ['migrations', 'zapmizer-migrations-connect']);

            $this->publishes([
                __DIR__ . '/../database/migrations/add_webhook_key_to_zapmizer_connections_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 2) . '_add_webhook_key_to_zapmizer_connections_table.php'),
            ], 'zapmizer-migrations-webhook-key');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/zapmizer'),
            ], 'views');

            // The Inertia + Vue connect components (button + panel),
            // Jetstream-style: copied into the app to be owned and restyled
            // there. The tag keeps its name from the wizard days. All in one
            // folder of their own, importing each other by relative paths: no
            // alias of the app and no clash with its components/ folder.
            $this->publishes([
                __DIR__ . '/../stubs/inertia-vue' => resource_path('js/zapmizer'),
            ], 'zapmizer-wizard');

            // The inbox screen (InboxScreen.vue + zapmizerEmbed.ts) on its
            // own tag: it imports @zapmizer/embed, which an app with only
            // the connect does not have. It builds on the wizard's files.
            $this->publishes([
                __DIR__ . '/../stubs/inertia-vue-inbox' => resource_path('js/zapmizer'),
            ], 'zapmizer-inbox');
        }
    }

    /**
     * Register commands.
     *
     * @return void
     */
    public function registerCommands()
    {
        $this->commands([
            Console\SendMessage::class,
            Console\InstallCommand::class,
            Console\CheckCommand::class,
        ]);
    }

    /**
     * The transport of a client made with parameters: the `transport` given,
     * or the configured one with the `timeout` / `connect_timeout` given in
     * place of `zapmizer.http.*`. Without either, the container's.
     */
    protected function transportFor(Application $app, array $config): Transport
    {
        $transport = $config['transport'] ?? null;

        if ($transport instanceof Transport) {
            return $transport;
        }

        if ($transport !== null) {
            throw new InvalidArgumentException(sprintf(
                'The transport parameter must be an instance of %s, got %s.',
                Transport::class,
                get_debug_type($transport),
            ));
        }

        $timeouts = array_filter([
            'connectTimeout' => $this->seconds($config['connect_timeout'] ?? null),
            'timeout' => $this->seconds($config['timeout'] ?? null),
        ], fn ($value) => $value !== null);

        return $timeouts === [] ? $app->make(Transport::class) : $this->makeTransport($app, $timeouts);
    }

    /**
     * @param array<string, float> $timeouts over the config's, by the
     *                                       constructor's parameter names
     */
    protected function makeTransport(Application $app, array $timeouts = []): Transport
    {
        $class = config('zapmizer.http.transport');

        if ($class === null || $class === '') {
            $class = LaravelHttpTransport::class;
        }

        if (!is_string($class) || $class === Transport::class || !is_a($class, Transport::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'zapmizer.http.transport must name a class implementing %s, got %s.',
                Transport::class,
                is_string($class) ? $class : get_debug_type($class),
            ));
        }

        if ($timeouts === [] && $app->bound($class)) {
            return $app->make($class);
        }

        return $app->make($class, array_replace(array_filter([
            'connectTimeout' => $this->seconds(config('zapmizer.http.connect_timeout')),
            'timeout' => $this->seconds(config('zapmizer.http.timeout')),
        ], fn ($value) => $value !== null), $timeouts));
    }

    protected function seconds(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * Check if package is running under Lumen app
     *
     * @return bool
     */
    protected function isLumen()
    {
        return Str::contains($this->app->version(), 'Lumen') === true;
    }
}
