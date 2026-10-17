<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

/**
 * A team whose customer id on Zapmizer is not its key, as a multi-tenant
 * app sending its tenant id would do. The test resets the static.
 */
class ExternalIdTeam extends Team
{
    public static ?string $externalId = null;

    public function zapmizerExternalId(): string
    {
        return static::$externalId ?? parent::zapmizerExternalId();
    }
}
