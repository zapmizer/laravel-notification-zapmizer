<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use NotificationChannels\Zapmizer\Connect\InstanceClient;

class SpyingInstanceClient extends InstanceClient
{
    public array $createdPaths = [];

    protected function temporaryMediaPath(): ?string
    {
        $path = parent::temporaryMediaPath();

        if ($path !== null) {
            $this->createdPaths[] = $path;
        }

        return $path;
    }
}
