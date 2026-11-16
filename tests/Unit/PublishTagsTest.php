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
            'ConnectButton.vue',
            'ConnectionPanel.vue',
            'types.ts',
            'useZapmizerIntegration.ts',
            'zapmizerRoute.ts',
        ], $files);
        $this->assertSame([resource_path('js/zapmizer')], array_values(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, 'zapmizer-wizard')));

        foreach (array_keys(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, 'zapmizer-wizard')) as $source) {
            foreach ($files as $file) {
                $this->assertStringNotContainsString('@zapmizer/embed', file_get_contents($source . '/' . $file), "{$file} must build without the embed");
            }
        }
    }

    public function testTheInboxTagHasTheScreenAndTheEmbedGlue()
    {
        $this->assertSame(['InboxScreen.vue', 'zapmizerEmbed.ts'], $this->filesOf('zapmizer-inbox'));
        $this->assertSame([resource_path('js/zapmizer')], array_values(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, 'zapmizer-inbox')));
    }

    public function testTheStubsImportEachOtherByRelativePaths()
    {
        $files = [];

        foreach (['zapmizer-wizard', 'zapmizer-inbox'] as $tag) {
            foreach (array_keys(ServiceProvider::pathsToPublish(ZapmizerServiceProvider::class, $tag)) as $source) {
                foreach (glob($source . '/*') as $file) {
                    $files[basename($file)] = (string) file_get_contents($file);
                }
            }
        }

        foreach ($files as $name => $code) {
            preg_match_all("/^import .*? from '([^']+)';$/m", $code, $imports);

            foreach ($imports[1] as $module) {
                $this->assertDoesNotMatchRegularExpression('#^[@~]/#', $module, "{$name}: no app alias");

                if (str_starts_with($module, '.')) {
                    $this->assertStringStartsWith('./', $module, $name);
                    $target = substr($module, 2);
                    $this->assertTrue(isset($files[$target]) || isset($files[$target . '.ts']), "{$name} imports {$module}, which is published next to it");
                }
            }
        }
    }
}
