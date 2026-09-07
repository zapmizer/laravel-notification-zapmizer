<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;
use NotificationChannels\Zapmizer\Http\Controllers\VerifyNumberController;

Route::get('verify-number', VerifyNumberController::class)->name('verify_number');

// Hosted connect flow (Connectable models). The throttles follow the
// wizard's 2.5s polling: tight on start, loose on polling.
Route::get('connect', [ConnectController::class, 'show'])->name('connect.show');
Route::delete('connect', [ConnectController::class, 'destroy'])->name('connect.destroy');
Route::post('connect/start', [ConnectController::class, 'start'])->middleware('throttle:10,1')->name('connect.start');
Route::get('connect/callback', [ConnectController::class, 'callback'])->name('connect.callback');
Route::post('connect/instance', [ConnectController::class, 'instance'])->middleware('throttle:120,1')->name('connect.instance');
Route::get('connect/instances', [ConnectController::class, 'instances'])->middleware('throttle:120,1')->name('connect.instances');
Route::get('connect/connection', [ConnectController::class, 'connection'])->middleware('throttle:120,1')->name('connect.connection');
