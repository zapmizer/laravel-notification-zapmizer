<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

class TimeoutCapturingTransport extends RecordingTransport
{
    public function __construct(public ?float $connectTimeout = null, public ?float $timeout = null)
    {
        parent::__construct();
    }
}
