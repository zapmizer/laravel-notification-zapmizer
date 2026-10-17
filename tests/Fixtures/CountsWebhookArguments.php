<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records what the route hands the controller: the per-connection route
 * must not add an argument to handleWebhook().
 */
class CountsWebhookArguments extends WebhookController
{
    public static ?int $arguments = null;

    /** @var array<string, mixed> */
    public static array $parameters = [];

    public function handleWebhook(Request $request): Response
    {
        static::$arguments = func_num_args();
        static::$parameters = $request->route()->parameters();

        return parent::handleWebhook($request);
    }
}
