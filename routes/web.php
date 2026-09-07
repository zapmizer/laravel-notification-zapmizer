<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;
use NotificationChannels\Zapmizer\Http\Controllers\VerifyNumberController;

Route::get('verify-number', VerifyNumberController::class)->name('verify_number');

// Hosted connect flow (Connectable models): authorization AND pairing happen
// on Zapmizer's page, in a popup. `show?live=1` reaches Zapmizer, hence the
// throttle on it too.
Route::get('connect', [ConnectController::class, 'show'])->middleware('throttle:60,1')->name('connect.show');
Route::delete('connect', [ConnectController::class, 'destroy'])->name('connect.destroy');
Route::post('connect/start', [ConnectController::class, 'start'])->middleware('throttle:10,1')->name('connect.start');
Route::get('connect/callback', [ConnectController::class, 'callback'])->name('connect.callback');
