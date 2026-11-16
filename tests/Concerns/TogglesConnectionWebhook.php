<?php

namespace NotificationChannels\Zapmizer\Test\Concerns;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use NotificationChannels\Zapmizer\ZapmizerServiceProvider;

/**
 * The per-connection webhook in tests: its optional column, and the
 * package routes registered again after a config change (they are read at
 * boot, before a test body runs).
 */
trait TogglesConnectionWebhook
{
    protected function addWebhookKeyColumn(): void
    {
        (include __DIR__ . '/../../database/migrations/add_webhook_key_to_zapmizer_connections_table.php.stub')->up();
    }

    protected function reloadPackageRoutes(): void
    {
        $this->app['router']->setRoutes(new RouteCollection());
        $this->app->getProvider(ZapmizerServiceProvider::class)->registerRoutes();
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    protected function enablePerConnection(): void
    {
        config()->set('zapmizer.webhook.per_connection', true);
        $this->reloadPackageRoutes();
    }

    protected function watchQueries(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }

    /**
     * @return array<int, array{query: string, bindings: array<int, mixed>, time: float|null}>
     */
    protected function connectionQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn (array $entry) => str_contains($entry['query'], 'zapmizer_connections')));
    }
}
