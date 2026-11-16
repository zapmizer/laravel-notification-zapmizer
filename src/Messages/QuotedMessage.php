<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * `Message::$quoted`: the message being replied to, summarized. `type` is
 * null on the official number, which does not tell it.
 */
final class QuotedMessage
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $sender = null,
        public readonly ?string $type = null,
        public readonly ?string $text = null,
    ) {
    }

    /**
     * Null without a string `id`.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromContract(array $data): ?self
    {
        $id = ContractFields::string($data, 'id');

        return $id === null ? null : new self(
            id: $id,
            sender: ContractFields::string($data, 'sender'),
            type: ContractFields::string($data, 'type'),
            text: ContractFields::string($data, 'text'),
        );
    }
}
