<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Support\ColumnExists;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\TestCase;

class ColumnExistsTest extends TestCase
{
    use CreatesConnectionTables;
    use TogglesConnectionWebhook;

    private const KEY = 'zapmizer:column-exists:zapmizer_connections.webhook_key';

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    public function testAMissingColumnIsCheckedAgain()
    {
        $this->assertFalse(ColumnExists::for(new ZapmizerConnection(), 'webhook_key'));
        $this->assertFalse(Cache::has(self::KEY));

        $this->addWebhookKeyColumn();

        $this->assertTrue(ColumnExists::for(new ZapmizerConnection(), 'webhook_key'));
    }

    public function testAPresentColumnIsCachedForADay()
    {
        $this->addWebhookKeyColumn();

        $this->assertTrue(ColumnExists::for(new ZapmizerConnection(), 'webhook_key'));
        $this->assertTrue(Cache::get(self::KEY));

        $this->travel(1)->days();
        $this->travel(1)->seconds();

        $this->assertFalse(Cache::has(self::KEY));
    }

    public function testTheCachedAnswerIsRead()
    {
        Cache::put(self::KEY, true);

        $this->assertTrue(ColumnExists::for(new ZapmizerConnection(), 'webhook_key'));
    }

    public function testTheStubAddsANullableUniqueKey()
    {
        $this->addWebhookKeyColumn();

        $a = $this->connectedTeam('a', '5581911110001')->zapmizerConnection;
        $b = $this->connectedTeam('b', '5581911110002')->zapmizerConnection;

        $this->assertNull($a->getAttributes()['webhook_key'] ?? null);
        $this->assertNull($b->fresh()->getAttributes()['webhook_key']);

        $a->forceFill(['webhook_key' => str_repeat('a', 40)])->save();

        $this->expectException(QueryException::class);

        $b->forceFill(['webhook_key' => str_repeat('a', 40)])->save();
    }
}
