<?php

namespace NotificationChannels\Zapmizer\Connect;

use Carbon\CarbonImmutable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

/**
 * Class BotInstance.
 *
 * Mirror of a number in GET /bot-instances(/{id}). `isOnline` is the way
 * Zapmizer recommends to confirm the number is up — the query has no side
 * effect and works for official numbers too, which have no
 * `/connection` route. `supportsTemplates` is `true` on an official
 * number (Meta's API): free text only inside the 24 h window, templates
 * outside it. `state` is the state of Zapmizer's process (`ready`, `off`,
 * ...), not the pairing state of `InstanceConnection`. The number itself is
 * not in this payload.
 *
 * A flag is only `true` when Zapmizer sent `true`: an absent one is
 * `false`.
 */
final class BotInstance
{
    public function __construct(
        public readonly int $id,
        public readonly string $state = '',
        public readonly string $stateLabel = '',
        public readonly bool $isOnline = false,
        public readonly bool $isUp = false,
        public readonly bool $supportsGroups = false,
        public readonly bool $supportsTemplates = false,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly ?string $selfUrl = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data One `Numero` — the `data` of the
     *                                   show route or an item of the list
     *
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $data): self
    {
        $id = Payload::positiveId($data['id'] ?? null);

        if ($id === null) {
            throw ZapmizerConnectException::unexpectedResponse('invalid bot instance id');
        }

        return new self(
            id: $id,
            state: is_string($data['state'] ?? null) ? $data['state'] : '',
            stateLabel: is_string($data['state_label'] ?? null) ? $data['state_label'] : '',
            isOnline: ($data['is_online'] ?? null) === true,
            isUp: ($data['is_up'] ?? null) === true,
            supportsGroups: ($data['supports_groups'] ?? null) === true,
            supportsTemplates: ($data['supports_templates'] ?? null) === true,
            createdAt: Payload::date($data['created_at'] ?? null, 'created_at'),
            selfUrl: is_string($data['links']['self'] ?? null) ? $data['links']['self'] : null,
        );
    }

    /**
     * An official number (Meta's API): sends templates, free text only
     * inside the 24 h window.
     */
    public function isOfficial(): bool
    {
        return $this->supportsTemplates;
    }
}
