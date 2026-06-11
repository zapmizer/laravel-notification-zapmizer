<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\VerifyNumberController;

Route::get('verify-number', VerifyNumberController::class)->name('verify_number');
