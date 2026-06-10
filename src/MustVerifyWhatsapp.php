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
 * The Zapbot flow is inverted: starting a verification yields a wa.me link
 * the user opens to send the opening message; Zapbot replies with a code the
 * user types back (confirmWhatsappVerification()). Use
 * syncWhatsappVerificationStatus() to poll Zapbot for the current state.
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
     * Creates a hosted verification session on Zapbot, records the state as
     * "awaiting" and returns the hosted page link to redirect the user to.
     * The whole flow (number, wa.me link, code) happens there; when it
     * completes, the user comes back to $returnUrl with signed query params
     * (validate with ZapbotSignature::isValidQuery()).
     *
     * @throws ZapmizerVerificationException
     */
    public function startWhatsappVerification(?string $returnUrl = null): string
    {
        $session = app(VerificationClient::class)->createSession(
            number: $this->getWhatsappNumberForVerification(),
            returnUrl: $returnUrl ?? config('zapmizer.return_url'),
            clientReference: (string) $this->getKey(),
        );

        $this->whatsappVerification()->updateOrCreate([], [
            'number' => $this->getWhatsappNumberForVerification(),
            'verification_id' => $session->id,
            'url' => $session->url,
            'status' => WhatsappVerified::STATUS_AWAITING,
            'verified_at' => null,
        ]);

        return $session->url;
    }

    /**
     * Confirm the code the user received on WhatsApp.
     *
     * Returns true when the code was accepted and the number is verified.
     * A wrong code raises a VerificationRequestFailed (422).
     *
     * @throws ZapmizerVerificationException
     */
    public function confirmWhatsappVerification(string $code): bool
    {
        $record = $this->whatsappVerification()->first();

        if ($record === null || blank($record->number)) {
            throw ZapmizerVerificationException::verificationNotStarted();
        }

        $verification = app(VerificationClient::class)->confirm($record->number, $code);

        $this->applyZapbotStatus($record, $verification);

        return $verification->isVerified();
    }

    /**
     * Poll Zapbot for the current state of the verification and persist it.
     *
     * Returns the package-level status (awaiting/verified/failed), or null
     * when no verification was started yet. This is how the state converges
     * locally when webhooks can't reach the application.
     *
     * @throws ZapmizerVerificationException
     */
    public function syncWhatsappVerificationStatus(): ?string
    {
        $record = $this->whatsappVerification()->first();

        if ($record === null || blank($record->number)) {
            return null;
        }

        $verification = app(VerificationClient::class)->get($record->number);

        $this->applyZapbotStatus($record, $verification);

        return $record->status;
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
     * Map a Zapbot session onto the local state record and persist it.
     */
    protected function applyZapbotStatus(WhatsappVerified $record, Verification $verification): void
    {
        $record->forceFill([
            'status' => match (true) {
                $verification->isVerified() => WhatsappVerified::STATUS_VERIFIED,
                $verification->isTerminal() => WhatsappVerified::STATUS_FAILED,
                default => WhatsappVerified::STATUS_AWAITING,
            },
            'verified_at' => $verification->isVerified()
                ? ($record->verified_at ?? $record->freshTimestamp())
                : null,
            'url' => $verification->waLink ?? $record->url,
        ])->save();
    }

    /**
     * The model class used to store the verification state.
     */
    protected function whatsappVerificationModel(): string
    {
        return config('zapmizer.models.whatsapp_verified', WhatsappVerified::class);
    }
}
