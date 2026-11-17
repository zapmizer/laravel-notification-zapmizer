<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Arr;
use NotificationChannels\Zapmizer\Support\ConnectWebhook;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `zapmizer.connect.webhook` decides whether the connect asks Zapmizer for a
 * webhook; unset, the webhook route group decides (the behaviour before 2.1).
 */
class ConnectWebhookTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: ?bool}> `'<absent>'` takes the key out
     */
    public static function values(): array
    {
        return [
            'null' => [null, null],
            'absent' => ['<absent>', null],
            'empty string' => ['', null],
            'true word' => ['true', true],
            'one' => ['1', true],
            'false word' => ['false', false],
            'zero' => ['0', false],
            'abc' => ['abc', null],
            'true' => [true, true],
            'false' => [false, false],
        ];
    }

    private function apply(mixed $value): void
    {
        if ($value === '<absent>') {
            config()->set('zapmizer.connect', Arr::except(config('zapmizer.connect'), 'webhook'));

            return;
        }

        config()->set('zapmizer.connect.webhook', $value);
    }

    /** @dataProvider values */
    #[DataProvider('values')]
    public function testConfigured(mixed $value, ?bool $configured)
    {
        $this->apply($value);

        $this->assertSame($configured, ConnectWebhook::configured());
    }

    /** @dataProvider values */
    #[DataProvider('values')]
    public function testInvalidIsASetValueThatIsNotABoolean(mixed $value, ?bool $configured)
    {
        $this->apply($value);

        $this->assertSame($value === 'abc', ConnectWebhook::invalid());
    }

    /** @dataProvider values */
    #[DataProvider('values')]
    public function testAskedFollowsTheConfigWhenSet(mixed $value, ?bool $configured)
    {
        $this->apply($value);

        foreach ([true, false] as $routeOn) {
            config()->set('zapmizer.routes.webhook', $routeOn);

            $this->assertSame($configured ?? $routeOn, ConnectWebhook::asked());
        }
    }

    public function testAskedWithoutTheConfigAndThePackageRoutesOffAsks()
    {
        $this->apply(null);
        config()->set('zapmizer.routes.enabled', false);
        config()->set('zapmizer.routes.webhook', false);

        $this->assertTrue(ConnectWebhook::asked());
    }
}
