<?php

namespace NotificationChannels\Zapmizer\Connect;

use Illuminate\Support\Arr;
use JsonSerializable;

/**
 * Class InstanceSummary.
 *
 * One option of the sender choice, when the team has more than one
 * connected instance. An instance without a paired number never becomes an
 * option.
 */
final readonly class InstanceSummary implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $number,
        public bool $isCurrent = false,
    ) {
    }

    public static function fromArray(array $item, ?int $currentId = null): self
    {
        return new self(
            id: (int) $item['id'],
            number: (string) Arr::get($item, 'client.cid_formatted'),
            isCurrent: (int) $item['id'] === $currentId,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'is_current' => $this->isCurrent,
        ];
    }
}
