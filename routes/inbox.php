<?php

use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Http\Controllers\InboxController;

// The embedded inbox (`zapmizer.inbox.enabled`): the session @zapmizer/embed
// asks for, and the keepalive it pings every 15 minutes while the inbox is
// open. Array form: the class is not loaded when the routes register.
Route::post('inbox/session', [InboxController::class, 'session'])->middleware('throttle:30,1')->name('inbox.session');
Route::get('inbox/keepalive', [InboxController::class, 'keepalive'])->name('inbox.keepalive');
