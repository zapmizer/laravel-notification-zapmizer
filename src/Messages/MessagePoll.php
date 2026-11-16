<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * `Message::$poll`: a poll. `name` is the question; `multiple` says whether
 * more than one option may be chosen. The votes come in `poll_vote`
 * (`Events\PollVoted`).
 */
final class MessagePoll
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly array $options = [],
        public readonly bool $multiple = false,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromContract(array $data): self
    {
        return new self(
            name: ContractFields::string($data, 'name') ?? '',
            options: ContractFields::strings($data, 'options'),
            multiple: ContractFields::bool($data, 'multiple') ?? false,
        );
    }
}
