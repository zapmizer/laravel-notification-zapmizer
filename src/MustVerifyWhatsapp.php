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
     * Start a new WhatsApp verification.
     *
     * Asks Zapmizer for a verification of the model's number, records the
     * state as "awaiting" and returns the hosted page link where the user
     * completes the verification.
     *
     * @throws ZapmizerVerificationException
     */
    public function startWhatsappVerification(): string
    {
        $number = $this->getWhatsappNumberForVerification();

        if (blank($number)) {
            throw ZapmizerVerificationException::numberNotProvided();
        }

        $verification = app(VerificationClient::class)->create($number);

        if (blank($verification->url)) {
            throw ZapmizerVerificationException::unexpectedResponse('missing hosted page link');
        }

        $this->whatsappVerification()->updateOrCreate([], [
            'number' => $number,
            'verification_id' => $verification->id,
            'url' => $verification->url,
            'status' => WhatsappVerified::STATUS_AWAITING,
            'verified_at' => null,
        ]);

        return $verification->url;
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
