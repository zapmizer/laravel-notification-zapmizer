<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp;

/**
 * Class VerifyNumberController.
 *
 * Starts a WhatsApp verification for the authenticated user and redirects
 * them straight to the hosted page where it is completed.
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
