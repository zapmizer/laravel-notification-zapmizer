<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Http\Controllers\ConnectController;

/**
 * A subclass that kept the map of the 0.3/0.4, with `qr_unavailable`, a
 * status the package's result() no longer has a message for.
 */
class LegacyPopupErrorsConnectController extends ConnectController
{
    protected const POPUP_ERRORS = [
        'access_denied' => 'denied',
        'plan_limit' => 'plan_limit',
        'qr_unavailable' => 'qr_unavailable',
    ];
}
