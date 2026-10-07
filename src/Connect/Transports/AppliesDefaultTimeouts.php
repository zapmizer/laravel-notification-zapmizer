<?php

namespace NotificationChannels\Zapmizer\Connect\Transports;

trait AppliesDefaultTimeouts
{
    protected function prepareOptions(array $options): array
    {
        return array_replace(
            array_filter(
                ['connect_timeout' => $this->connectTimeout, 'timeout' => $this->timeout],
                fn ($value) => $value !== null,
            ),
            $options,
            ['http_errors' => false, 'allow_redirects' => false],
        );
    }
}
