<?php

namespace NotificationChannels\Zapmizer\Connect;

use RuntimeException;

/**
 * Class MediaDownload.
 *
 * Mirror of GET /whatsapp-messages/media: the media of an inbound message,
 * or the reason it is not here yet. The webhook fires BEFORE the bot has
 * finished downloading the media, so the answer has three states:
 *
 * - `attached` — the bytes came, written to a temporary file (not held in
 *   memory: a video can be tens of megabytes). Read them through `path()`,
 *   `stream()` or `contents()`; the file is deleted when this object is
 *   destroyed, so move/copy it before that if you want to keep it.
 * - `downloading` — the bot has not finished yet: ask again later.
 * - `unavailable` — it will never come (outside the 600 s window, a type
 *   Zapmizer does not download, a view-once message).
 */
final class MediaDownload
{
    public const ATTACHED = 'attached';

    public const DOWNLOADING = 'downloading';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $state,
        public readonly ?string $mimeType = null,
        public readonly ?string $filename = null,
        public readonly ?int $size = null,
        private ?string $path = null,
    ) {
    }

    /**
     * @param string $path The temporary file holding the bytes — owned (and
     *                     deleted) by this object from here on.
     */
    public static function attached(string $path, ?string $mimeType, ?string $filename, ?int $size): self
    {
        return new self(self::ATTACHED, $mimeType, $filename, $size, $path);
    }

    public static function downloading(): self
    {
        return new self(self::DOWNLOADING);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function isAttached(): bool
    {
        return $this->state === self::ATTACHED;
    }

    public function isDownloading(): bool
    {
        return $this->state === self::DOWNLOADING;
    }

    public function isUnavailable(): bool
    {
        return $this->state === self::UNAVAILABLE;
    }

    /**
     * The temporary file with the bytes. Deleted on destruct.
     */
    public function path(): string
    {
        if ($this->path === null) {
            throw new RuntimeException("There is no media to read: the download is `{$this->state}`.");
        }

        return $this->path;
    }

    /**
     * A fresh read handle on the temporary file — hand it to
     * `Storage::put()` / `putStream()` so the bytes never sit in memory.
     *
     * @return resource
     */
    public function stream()
    {
        $stream = fopen($this->path(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not open the downloaded media for reading.');
        }

        return $stream;
    }

    /**
     * The whole file in memory — only when you really need the string.
     */
    public function contents(): string
    {
        $contents = file_get_contents($this->path());

        if ($contents === false) {
            throw new RuntimeException('Could not read the downloaded media.');
        }

        return $contents;
    }

    public function __destruct()
    {
        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
