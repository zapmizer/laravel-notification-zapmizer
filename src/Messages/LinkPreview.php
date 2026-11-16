<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * `Message::$linkPreview`: the preview WhatsApp built for a link in the text.
 */
final class LinkPreview
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromContract(array $data): self
    {
        return new self(
            url: ContractFields::string($data, 'url') ?? '',
            title: ContractFields::string($data, 'title'),
            description: ContractFields::string($data, 'description'),
        );
    }
}
