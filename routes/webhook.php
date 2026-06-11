<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\WebhookController;

Route::post('webhook', [WebhookController::class, 'handleWebhook'])->name('webhook');
