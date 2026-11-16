<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\ServiceProvider;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\ZapmizerServiceProvider;

class PublishesMigrationsTest extends TestCase
{
    /**
     * @return array<int, string> the stub file names published under a tag
     */
    protected function stubsFor(string $tag): array
    {
        $paths = array_keys(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, $tag));

        sort($paths);

        return array_map('basename', $paths);
    }

    public function testEachFlowHasItsOwnMigrationTag()
    {
        $this->assertEquals(['create_whatsapp_verifieds_table.php.stub'], $this->stubsFor('zapmizer-migrations-verify'));
        $this->assertEquals(['create_zapmizer_connections_table.php.stub'], $this->stubsFor('zapmizer-migrations-connect'));
    }

    public function testTheMigrationsTagStillPublishesBoth()
    {
        $this->assertEquals(
            ['create_whatsapp_verifieds_table.php.stub', 'create_zapmizer_connections_table.php.stub'],
            $this->stubsFor('migrations')
        );
    }

    public function testTheWebhookKeyMigrationHasItsOwnTag()
    {
        $this->assertEquals(['add_webhook_key_to_zapmizer_connections_table.php.stub'], $this->stubsFor('zapmizer-migrations-webhook-key'));
    }

    public function testTheWebhookKeyMigrationRunsAfterTheCreate()
    {
        $create = basename(array_values(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, 'zapmizer-migrations-connect'))[0]);
        $key = basename(array_values(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, 'zapmizer-migrations-webhook-key'))[0]);

        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_add_webhook_key_to_zapmizer_connections_table\.php$/', $key);
        $this->assertGreaterThan(0, strcmp(substr($key, 0, 17), substr($create, 0, 17)));
    }
}
