<?php

namespace NotificationChannels\Zapmizer\Messages\Concerns;

use NotificationChannels\Zapmizer\Messages\MessageStatus;

/**
 * Shortcuts over the `message_status` number of a sent message (see
 * `MessageStatus`). A missing status answers false to all of them.
 */
trait HasMessageStatus
{
    public function isFailed(): bool
    {
        return $this->status !== null && MessageStatus::isFailure($this->status);
    }

    /**
     * Delivered to the contact's device, or further (read, played).
     */
    public function isDelivered(): bool
    {
        return $this->status !== null && MessageStatus::reached($this->status, MessageStatus::DELIVERED);
    }

    public function isRead(): bool
    {
        return $this->status !== null && MessageStatus::reached($this->status, MessageStatus::READ);
    }

    /**
     * Nothing more will change: failed, read or played.
     */
    public function isFinal(): bool
    {
        return $this->status !== null && MessageStatus::isFinal($this->status);
    }
}
