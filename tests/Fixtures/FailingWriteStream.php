<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

class FailingWriteStream
{
    public $context;

    public static int $capacity = 4;

    public static array $unlinked = [];

    private int $written = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $accepted = max(0, min(strlen($data), self::$capacity - $this->written));
        $this->written += $accepted;

        return $accepted;
    }

    public function stream_close(): void
    {
    }

    public function unlink(string $path): bool
    {
        self::$unlinked[] = $path;

        return true;
    }

    public function url_stat(string $path, int $flags)
    {
        return false;
    }
}
