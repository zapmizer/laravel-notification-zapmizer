<?php

namespace NotificationChannels\Zapmizer\Test\Concerns;

use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

trait InvalidBaseUris
{
    public static function invalidBaseUris(): array
    {
        return [
            'no scheme' => ['app.zapmizer.com/api/'],
            'empty' => [''],
            'only a path' => ['/api/'],
            'localhost without scheme' => ['localhost/api/'],
            'host and port without scheme' => ['localhost:8001/api/'],
            'no host' => ['http://:80/api'],
            'user info' => ['https://u:p@h/api'],
            'ftp' => ['ftp://h/api'],
            'unicode host' => ['https://ação.com/api'],
            'space in the host' => ['https://h com/api'],
            'trailing space' => ['https://app.zapmizer.com/api/ '],
            'trailing newline' => ["https://app.zapmizer.com/api/\n"],
        ];
    }

    protected function assertIsTheInvalidBaseUri(\Throwable $exception): void
    {
        $this->assertSame(ZapmizerConnectException::class, get_class($exception));
        $this->assertSame(
            'The Zapmizer API URL is not an absolute http(s) URL with a host and without user info: check zapmizer.base_uri.',
            $exception->getMessage(),
        );
        $this->assertStringNotContainsString('u:p', $exception->getMessage());
    }
}
