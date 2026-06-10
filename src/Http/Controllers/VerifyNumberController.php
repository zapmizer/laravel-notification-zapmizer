<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp;

/**
 * Class VerifyNumberController.
 *
 * Starts a WhatsApp verification for the authenticated user and redirects
 * them straight to the wa.me link where they send the opening message.
 * While Zapbot is still resolving the number there's no link yet — the user
 * is sent back to where they came from to retry/poll.
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

        $waLink = $user->startWhatsappVerification();

        if ($waLink === null) {
            return redirect()->back(fallback: '/')->with('zapmizer.resolving', true);
        }

        return redirect()->away($waLink);
    }
}
