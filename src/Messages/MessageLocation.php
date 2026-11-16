<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * `Message::$location`: a shared location. `name` is the place's name,
 * `url` its link.
 */
final class MessageLocation
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?string $name = null,
        public readonly ?string $address = null,
        public readonly ?string $url = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromContract(array $data): self
    {
        return new self(
            latitude: (float) (ContractFields::number($data, 'latitude') ?? 0),
            longitude: (float) (ContractFields::number($data, 'longitude') ?? 0),
            name: ContractFields::string($data, 'name'),
            address: ContractFields::string($data, 'address'),
            url: ContractFields::string($data, 'url'),
        );
    }
}
