<?php

namespace NotificationChannels\Zapmizer\Contracts;

use Psr\Http\Message\ResponseInterface;

interface Transport
{
    public function send(string $method, string $url, array $options = []): ResponseInterface;
}
