<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Database\Eloquent\Relations\HasOne;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerVerificationException;
use NotificationChannels\Zapmizer\Models\WhatsappVerified;

/**
 * Trait MustVerifyWhatsapp.
 *
 * Default implementation of the MustVerifyWhatsapp contract. The verification
 * state lives in the package's own `whatsapp_verifieds` table (1:1 with the
 * model), so the host application's `users` table is never touched.
 *
 * The flow: starting a verification creates a hosted page session on the
 * Zapmizer domain — the user opens the wa.me trigger and receives a code on
 * WhatsApp there. The code can be confirmed on the hosted page itself or
 * through confirmWhatsappVerification() if your app owns the code input.
 * Terminal states also arrive via the team's registered webhooks.
 */
trait MustVerifyWhatsapp
{
    /**
     * The verification state record associated with the model.
     */
    public function whatsappVerification(): HasOne
    {
        return $this->hasOne($this->whatsappVerificationModel(), 'user_id');
    }

    /**
     * Determine if the model's WhatsApp number has been verified.
     */
    public function hasVerifiedWhatsapp(): bool
    {
        $verification = $this->whatsappVerification()->first();

        return $verification !== null && $verification->isVerified();
    }

    /**
     * Mark the model's WhatsApp number as verified.
     */
    public function markWhatsappAsVerified(): bool
    {
        $verification = $this->whatsappVerification()->firstOrNew([]);

        return $verification->forceFill([
            'number' => $verification->number ?? $this->getWhatsappNumberForVerification(),
            'status' => WhatsappVerified::STATUS_VERIFIED,
            'verified_at' => $verification->freshTimestamp(),
        ])->save();
    }

    /**
     * Start a new WhatsApp verification through the hosted page.
     *
     * Creates a hosted session on Zapmizer, records the state as "awaiting"
     * and returns the hosted page link to redirect the user to. The model's
     * number (when set) prefills the input there; `zapmizer.from_number`
     * (when set) picks which of the team's WhatsApp numbers receives the
     * verification; $returnUrl (or `zapmizer.return_url`) becomes the
     * page's "back to the site" button.
     *
     * @throws ZapmizerVerificationException
     */
    public function startWhatsappVerification(?string $returnUrl = null): string
    {
        $session = app(VerificationClient::class)->createSession(
            number: $this->getWhatsappNumberForVerification(),
            from: config('zapmizer.from_number'),
            returnUrl: $returnUrl ?? config('zapmizer.return_url'),
        );

        $this->whatsappVerification()->updateOrCreate([], [
            'number' => $this->getWhatsappNumberForVerification(),
            'url' => $session->url,
            'status' => WhatsappVerified::STATUS_AWAITING,
            'verified_at' => null,
        ]);

        return $session->url;
    }

    /**
     * Confirm the code the user received on WhatsApp.
     *
     * Returns true when the code was accepted (the number is then marked as
     * verified, with the canonical number Zapmizer resolved). A wrong code
     * or an exhausted/expired one returns false — inspect the state record,
     * or call the VerificationClient directly if you need the attempts left.
     *
     * @throws ZapmizerVerificationException
     */
    public function confirmWhatsappVerification(string $code): bool
    {
        $record = $this->whatsappVerification()->first();
        $number = $record?->number ?? $this->getWhatsappNumberForVerification();

        if (blank($number)) {
            throw ZapmizerVerificationException::numberNotProvided();
        }

        $result = app(VerificationClient::class)->confirm($number, $code);

        if ($result->isVerified()) {
            $this->whatsappVerification()->updateOrCreate([], [
                'number' => $result->number ?? $number,
                'status' => WhatsappVerified::STATUS_VERIFIED,
                'verified_at' => ($record ?? $this->whatsappVerification()->make())->freshTimestamp(),
            ]);

            return true;
        }

        if ($result->isFailed() && $record !== null && !$record->isVerified()) {
            $record->forceFill(['status' => WhatsappVerified::STATUS_FAILED])->save();
        }

        return false;
    }

    /**
     * Get the WhatsApp number that should be verified.
     *
     * Override this on your model if the number lives in another attribute.
     */
    public function getWhatsappNumberForVerification(): ?string
    {
        return $this->whatsapp_number;
    }

    /**
     * The model class used to store the verification state.
     */
    protected function whatsappVerificationModel(): string
    {
        return config('zapmizer.models.whatsapp_verified', WhatsappVerified::class);
    }
}
