<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

class FixedTimeoutTransport extends RecordingTransport
{
    public function __construct(public float $timeout = 30.0)
    {
        parent::__construct();
    }
}
