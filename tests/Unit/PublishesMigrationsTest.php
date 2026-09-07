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
}
