<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;
use NotificationChannels\Zapmizer\Http\Middleware\VerifyWebhookSignature;

Route::post('webhook', [WebhookController::class, 'handleWebhook'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('webhook');
