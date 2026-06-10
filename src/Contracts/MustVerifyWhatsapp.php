<?php

namespace NotificationChannels\Zapmizer\Contracts;

/**
 * Interface MustVerifyWhatsapp.
 *
 * Mirrors Laravel's MustVerifyEmail contract for WhatsApp numbers. Implement
 * it on your User model together with the trait of the same name:
 *
 * <code>
 * use NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp;
 * use NotificationChannels\Zapmizer\MustVerifyWhatsapp as VerifiesWhatsapp;
 *
 * class User extends Authenticatable implements MustVerifyWhatsapp
 * {
 *     use VerifiesWhatsapp;
 * }
 * </code>
 */
interface MustVerifyWhatsapp
{
    /**
     * Determine if the model's WhatsApp number has been verified.
     */
    public function hasVerifiedWhatsapp(): bool;

    /**
     * Mark the model's WhatsApp number as verified.
     */
    public function markWhatsappAsVerified(): bool;

    /**
     * Start a new WhatsApp verification and return the hosted page link to
     * redirect the user to.
     */
    public function startWhatsappVerification(): string;

    /**
     * Confirm the code the user received on WhatsApp.
     */
    public function confirmWhatsappVerification(string $code): bool;

    /**
     * Get the WhatsApp number that should be verified.
     */
    public function getWhatsappNumberForVerification(): ?string;
}
