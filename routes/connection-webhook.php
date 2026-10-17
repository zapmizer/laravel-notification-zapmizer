<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyConnectionWebhookSignature;

Route::post('webhook/{zapmizerWebhookKey}', [WebhookController::class, 'handleWebhook'])
    ->middleware(VerifyConnectionWebhookSignature::class)
    ->name('webhook.connection');
