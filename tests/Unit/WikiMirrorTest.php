<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Test\TestCase;
use WikiMirror;

require_once __DIR__.'/../../scripts/wiki.php';

class WikiMirrorTest extends TestCase
{
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $dir) {
            $this->removeDir($dir);
        }

        parent::tearDown();
    }

    private function home(): array
    {
        return ['file' => 'README.md', 'source' => "# Documentação\n\nEsta pasta vai junto com o código. Pela tag.\n\n1. [Backend](backend.md)\n2. [Vue](vue.md)\n"];
    }

    private function backend(): array
    {
        return ['file' => 'backend.md', 'source' => "# Backend: o endpoint de `sessão`\n\nVeja [Vue](vue.md#conversa), [de novo](./vue.md), [o exemplo](../src/A.php), [a pasta](../examples), [acima](#topo), [fora](https://example.com) e [mail](mailto:a@b.co).\n"];
    }

    private function vue(): array
    {
        return ['file' => 'vue.md', 'source' => "# Vue\n"];
    }

    private function page(array $pages, string $name): ?array
    {
        foreach ($pages as $page) {
            if ($page['name'] === $name) {
                return $page;
            }
        }

        return null;
    }

    public function test_names_a_page_after_its_title_without_punctuation(): void
    {
        $this->assertSame('Backend-o-endpoint-de-sessão', WikiMirror::pageName('backend.md', $this->backend()['source']));
        $this->assertSame('Home', WikiMirror::pageName('README.md', $this->home()['source']));
    }

    public function test_points_doc_links_to_wiki_pages_and_repo_links_to_main(): void
    {
        $body = $this->page(WikiMirror::buildPages([$this->home(), $this->backend(), $this->vue()]), 'Backend-o-endpoint-de-sessão')['body'];
        $repo = 'https://github.com/zapmizer/laravel-notification-zapmizer';

        $this->assertStringContainsString('[Vue](Vue#conversa)', $body);
        $this->assertStringContainsString('[de novo](Vue)', $body);
        $this->assertStringContainsString("[o exemplo]({$repo}/blob/main/src/A.php)", $body);
        $this->assertStringContainsString("[a pasta]({$repo}/tree/main/examples)", $body);
        $this->assertStringContainsString('[acima](#topo)', $body);
        $this->assertStringContainsString('[fora](https://example.com)', $body);
        $this->assertStringContainsString('[mail](mailto:a@b.co)', $body);
    }

    public function test_opens_every_page_with_the_note_and_drops_the_folder_line_from_home(): void
    {
        $pages = WikiMirror::buildPages([$this->home(), $this->backend()]);

        foreach ($pages as $page) {
            $this->assertStringStartsWith('> Esta wiki espelha', $page['body']);
        }

        $this->assertStringNotContainsString('Esta pasta vai junto', $pages[0]['body']);
    }

    public function test_follows_the_order_of_the_index_with_home_first(): void
    {
        $extra = ['file' => 'aaa.md', 'source' => "# Extra\n"];

        $files = array_column(WikiMirror::ordered([$this->vue(), $extra, $this->backend(), $this->home()]), 'file');
        $this->assertSame(['README.md', 'backend.md', 'vue.md', 'aaa.md'], $files);

        $sidebar = WikiMirror::sidebar(WikiMirror::buildPages(WikiMirror::ordered([$this->vue(), $this->backend(), $this->home()])));
        $this->assertStringContainsString("- [Backend: o endpoint de `sessão`](Backend-o-endpoint-de-sessão)\n- [Vue](Vue)", $sidebar);
        $this->assertStringContainsString('**[laravel-notification-zapmizer](Home)**', $sidebar);
    }

    public function test_cli_replaces_old_pages_and_writes_pages_and_sidebar(): void
    {
        $root = $this->tempDir();
        $wiki = $root.'/wiki';
        mkdir($root.'/docs/sub', 0777, true);
        mkdir($wiki);
        file_put_contents($root.'/docs/README.md', "# Docs\n\nEsta pasta vai junto com o código.\n\n1. [Vue](vue.md)\n");
        file_put_contents($root.'/docs/vue.md', "# Vue\n");
        file_put_contents($root.'/docs/sub/ignored.md', "# Ignored\n");
        file_put_contents($wiki.'/Old.md', 'old');
        file_put_contents($wiki.'/keep.txt', 'x');

        $process = proc_open(
            [PHP_BINARY, realpath(__DIR__.'/../../scripts/wiki.php'), $wiki],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($process));

        $files = array_values(array_diff(scandir($wiki), ['.', '..']));
        sort($files);
        $this->assertSame(['Home.md', 'Vue.md', '_Sidebar.md', 'keep.txt'], $files);
        $this->assertStringContainsString('(Vue)', file_get_contents($wiki.'/_Sidebar.md'));
    }

    public function test_cli_without_argument_exits_with_usage(): void
    {
        $process = proc_open(
            [PHP_BINARY, realpath(__DIR__.'/../../scripts/wiki.php')],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        $this->assertSame(1, proc_close($process));
        $this->assertStringContainsString('Uso:', $out);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/wiki-mirror-'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $this->tmp[] = $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            is_dir("$dir/$entry") ? $this->removeDir("$dir/$entry") : unlink("$dir/$entry");
        }

        rmdir($dir);
    }
}
