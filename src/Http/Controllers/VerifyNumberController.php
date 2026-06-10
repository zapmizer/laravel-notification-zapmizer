<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp;

/**
 * Class VerifyNumberController.
 *
 * Starts a WhatsApp verification for the authenticated user and redirects
 * them straight to the hosted verification page, where the whole flow
 * happens. When it completes, Zapbot sends the user back to the configured
 * zapmizer.return_url with signed query params.
 */
class VerifyNumberController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof MustVerifyWhatsapp,
            403,
            'The authenticated user model must implement the MustVerifyWhatsapp contract.'
        );

        return redirect()->away($user->startWhatsappVerification());
    }
}
