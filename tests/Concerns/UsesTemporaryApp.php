<?php

namespace NotificationChannels\Zapmizer\Test\Concerns;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Points the testbench app at a throwaway base path, so the install and
 * check commands write their files (config, migrations, .env, models,
 * resources/js, package.json) there and never into the skeleton.
 */
trait UsesTemporaryApp
{
    protected string $appRoot;

    protected function setUpTemporaryApp(): void
    {
        $this->appRoot = sys_get_temp_dir() . '/zapmizer-install-' . bin2hex(random_bytes(6));

        foreach (['app/Models', 'config', 'database/migrations', 'resources/js', 'storage/logs', 'storage/framework/views', 'storage/framework/cache', 'bootstrap/cache'] as $directory) {
            mkdir($this->appRoot . '/' . $directory, 0755, true);
        }

        file_put_contents($this->appRoot . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

        $this->app->setBasePath($this->appRoot);
        $this->app->useEnvironmentPath($this->appRoot);
    }

    protected function tearDownTemporaryApp(): void
    {
        if (!isset($this->appRoot) || !is_dir($this->appRoot)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->appRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->appRoot);
    }

    protected function appFile(string $path, ?string $contents = null): string
    {
        $full = $this->appRoot . '/' . $path;

        if ($contents !== null) {
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0755, true);
            }

            file_put_contents($full, $contents);
        }

        return $full;
    }

    protected function readAppFile(string $path): ?string
    {
        $full = $this->appRoot . '/' . $path;

        return is_file($full) ? (string) file_get_contents($full) : null;
    }

    /**
     * @return array<int, string>
     */
    protected function publishedMigrations(): array
    {
        return glob($this->appRoot . '/database/migrations/*_create_zapmizer_connections_table.php') ?: [];
    }
}
