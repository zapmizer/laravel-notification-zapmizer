<?php

namespace NotificationChannels\Zapmizer\Messages;

/**
 * `Message::$media`: what the contract says about the file of a media
 * message. The bytes do not come along: on a number by QR, ask for them
 * with `ZapmizerConnection::media()`. On the official number only `mime`
 * and `filename` come.
 *
 * - `filename`: the name the sender gave the file (documents, mostly).
 * - `size`: bytes. `duration`: seconds, in audio and video. `width` and
 *   `height`: pixels. `pageCount`: pages of a document. `isGif`: a video
 *   sent as a GIF.
 */
final class MessageMedia
{
    public function __construct(
        public readonly ?string $mime = null,
        public readonly ?string $filename = null,
        public readonly ?int $size = null,
        public readonly int|float|null $duration = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $pageCount = null,
        public readonly ?bool $isGif = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromContract(array $data): self
    {
        return new self(
            mime: ContractFields::string($data, 'mime'),
            filename: ContractFields::string($data, 'filename'),
            size: ContractFields::int($data, 'size'),
            duration: ContractFields::number($data, 'duration'),
            width: ContractFields::int($data, 'width'),
            height: ContractFields::int($data, 'height'),
            pageCount: ContractFields::int($data, 'page_count'),
            isGif: ContractFields::bool($data, 'is_gif'),
        );
    }
}
