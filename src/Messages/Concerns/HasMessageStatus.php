<?php

namespace NotificationChannels\Zapmizer\Messages\Concerns;

use NotificationChannels\Zapmizer\Messages\MessageStatus;

/**
 * Shortcuts over the `message_status` number of a sent message (see
 * `MessageStatus`). A missing status answers false to all of them.
 */
trait HasMessageStatus
{
    /**
     * The `message_status` number these shortcuts read; null when unknown.
     */
    abstract public function messageStatusCode(): ?int;

    public function isFailed(): bool
    {
        $status = $this->messageStatusCode();

        return $status !== null && MessageStatus::isFailure($status);
    }

    /**
     * Delivered to the contact's device, or further (read, played).
     */
    public function isDelivered(): bool
    {
        $status = $this->messageStatusCode();

        return $status !== null && MessageStatus::reached($status, MessageStatus::DELIVERED);
    }

    public function isRead(): bool
    {
        $status = $this->messageStatusCode();

        return $status !== null && MessageStatus::reached($status, MessageStatus::READ);
    }

    /**
     * Nothing more will change: failed, read or played.
     */
    public function isFinal(): bool
    {
        $status = $this->messageStatusCode();

        return $status !== null && MessageStatus::isFinal($status);
    }
}
