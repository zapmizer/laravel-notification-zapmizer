<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * `Message::$reply`: the option chosen on a message with buttons
 * (`button_reply`) or a list (`list_reply`). `id` is the one given when
 * sending; `description` only comes on a list row.
 */
final class MessageReply
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $label,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromContract(array $data): self
    {
        return new self(
            id: ContractFields::string($data, 'id'),
            label: ContractFields::string($data, 'label') ?? '',
            description: ContractFields::string($data, 'description'),
        );
    }
}
