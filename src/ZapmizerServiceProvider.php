<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Support\Str;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\ServiceProvider;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;

class ZapmizerServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register()
    {
        $this->app->bind(Zapmizer::class, static fn () => new Zapmizer(
            config('zapmizer.api_token'),
            app(HttpClient::class),
            config('zapmizer.base_uri')
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
