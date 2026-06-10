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
            Arr::get($config, 'api_version')
        ));

        $this->app->bind(VerificationClient::class, fn (Application $app, $config) => new VerificationClient(
            Arr::get($config, 'api_token', config('zapmizer.api_token')),
            app(HttpClient::class),
            Arr::get($config, 'base_uri', config('zapmizer.base_uri')),
            Arr::get($config, 'api_version')
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

        // The webhook is public (Zapbot signs it; the controller validates) and
        // stateless — registered outside the user-facing middleware on purpose.
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

            $this->publishes([
                __DIR__ . '/../database/migrations/create_whatsapp_verifieds_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_whatsapp_verifieds_table.php'),
            ], 'migrations');
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
