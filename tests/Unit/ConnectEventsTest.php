<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Events\NumberConnected;
use NotificationChannels\Zapmizer\Events\ReconnectRefused;
use NotificationChannels\Zapmizer\Test\TestCase;
use ReflectionClass;

class ConnectEventsTest extends TestCase
{
    public function testTheEventsCarryNoToken()
    {
        $this->assertSame(['connectable', 'connection'], $this->properties(NumberConnected::class));
        $this->assertSame(['connectable', 'connection', 'code', 'message'], $this->properties(ReconnectRefused::class));
    }

    /**
     * @return array<int, string>
     */
    private function properties(string $class): array
    {
        return array_map(fn ($property) => $property->getName(), (new ReflectionClass($class))->getProperties());
    }
}
