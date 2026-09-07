<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Support\Str;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\ServiceProvider;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Contracts\Foundation\Application;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;

class ZapmizerServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register()
    {
        $this->app->bind(Zapmizer::class, fn (Application $app, $config) => new Zapmizer(
            Arr::get($config, 'api_token', config('zapmizer.api_token')),
            app(HttpClient::class),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri')),
            Arr::get($config, 'api_version', config('zapmizer.api_version'))
        ));

        $this->app->bind(VerificationClient::class, fn (Application $app, $config) => new VerificationClient(
            Arr::get($config, 'api_token', config('zapmizer.api_token')),
            app(HttpClient::class),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri')),
            Arr::get($config, 'api_version', config('zapmizer.api_version'))
        ));

        // Connect flow: the partner client speaks for the application, the
        // instance client for one connected model (token passed at resolve
        // time — see ZapmizerConnection::instanceClient()).
        $this->app->bind(PartnerClient::class, fn (Application $app, $config) => new PartnerClient(
            Arr::get($config, 'partner_id', config('zapmizer.partner.id')),
            Arr::get($config, 'partner_secret', config('zapmizer.partner.secret')),
            app(HttpClient::class),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri'))
        ));

        // No fallback to the single-tenant token here: the instance client
        // always acts for ONE connection (ZapmizerConnection::instanceClient()).
        $this->app->bind(InstanceClient::class, fn (Application $app, $config) => new InstanceClient(
            Arr::get($config, 'api_token') ?? throw new ZapmizerUnauthorizedException(
                'InstanceClient needs the connection token — resolve it through ZapmizerConnection::instanceClient().'
            ),
            app(HttpClient::class),
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
        }
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
     * Register the package routes.
     *
     * Disable via `zapmizer.routes.enabled` to mount your own instead.
     *
     * @return void
     */
    public function registerRoutes()
    {
        if (config('zapmizer.routes.enabled', true) === false) {
            return;
        }

        Route::group([
            'prefix' => config('zapmizer.routes.prefix', 'zapmizer'),
            'middleware' => config('zapmizer.routes.middleware', ['web', 'auth']),
            'as' => 'zapmizer.',
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        });

        // The webhook is public and stateless — registered outside the
        // user-facing middleware on purpose. The signature check is on the
        // route itself (routes/webhook.php), not left to the config.
        Route::group([
            'prefix' => config('zapmizer.routes.prefix', 'zapmizer'),
            'middleware' => config('zapmizer.routes.webhook_middleware', []),
            'as' => 'zapmizer.',
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');
        });
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
            // `migrations` still publishes both (compat).
            $this->publishes([
                __DIR__ . '/../database/migrations/create_whatsapp_verifieds_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_whatsapp_verifieds_table.php'),
            ], ['migrations', 'zapmizer-migrations-verify']);

            $this->publishes([
                __DIR__ . '/../database/migrations/create_zapmizer_connections_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_zapmizer_connections_table.php'),
            ], ['migrations', 'zapmizer-migrations-connect']);

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/zapmizer'),
            ], 'views');

            // The Inertia + Vue connect components (button + panel),
            // Jetstream-style: copied into the app to be owned and restyled
            // there. The tag keeps its name from the wizard days.
            $this->publishes([
                __DIR__ . '/../stubs/inertia-vue' => resource_path('js'),
            ], 'zapmizer-wizard');
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
        ]);
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
