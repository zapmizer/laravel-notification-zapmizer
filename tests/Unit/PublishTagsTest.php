<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\ServiceProvider;
use NotificationChannels\Zapmizer\Test\TestCase;
use NotificationChannels\Zapmizer\ZapmizerServiceProvider;

class PublishTagsTest extends TestCase
{
    /**
     * @return array<int, string> the files a tag publishes, relative to its source
     */
    private function filesOf(string $tag): array
    {
        $files = [];

        foreach (array_keys(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, $tag)) as $source) {
            $base = realpath($source);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $files[] = substr($file->getPathname(), strlen($base) + 1);
            }
        }

        sort($files);

        return $files;
    }

    public function testTheWizardTagIsTheConnectWithoutTheEmbed()
    {
        $files = $this->filesOf('zapmizer-wizard');

        $this->assertSame([
            'components/zapmizer/ConnectButton.vue',
            'components/zapmizer/ConnectionPanel.vue',
            'composables/useZapmizerIntegration.ts',
            'composables/zapmizerRoute.ts',
            'types/zapmizer.ts',
        ], $files);

        foreach (array_keys(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, 'zapmizer-wizard')) as $source) {
            foreach ($files as $file) {
                $this->assertStringNotContainsString('@zapmizer/embed', file_get_contents($source . '/' . $file), "{$file} must build without the embed");
            }
        }
    }

    public function testTheInboxTagHasTheScreenAndTheEmbedGlue()
    {
        $this->assertSame(['components/zapmizer/InboxScreen.vue', 'composables/zapmizerEmbed.ts'], $this->filesOf('zapmizer-inbox'));
        $this->assertSame([resource_path('js')], array_values(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, 'zapmizer-inbox')));
    }
}
