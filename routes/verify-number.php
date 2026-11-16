<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\VerifyNumberController;

// Number verification (`zapmizer.routes.verify_number`): sends the user to
// Zapmizer's hosted page.
Route::get('verify-number', VerifyNumberController::class)->name('verify_number');
