<?php

// Gera a wiki a partir de docs/. A wiki é só um espelho: toda página é apagada e gerada de novo.
// Uso: php scripts/wiki.php <pasta do clone de laravel-notification-zapmizer.wiki.git>

final class WikiMirror
{
    public const REPO = 'https://github.com/zapmizer/laravel-notification-zapmizer';

    public const TITLE = 'laravel-notification-zapmizer';

    public static function banner(): string
    {
        return '> Esta wiki espelha [`docs/`]('.self::REPO.'/tree/main/docs) da `main`. Ao fixar uma tag no app, leia `docs/` dessa tag: é a doc que acompanha o código que você instalou.'."\n\n";
    }

    public static function titleOf(string $source): string
    {
        return preg_match('/^# (.+)$/m', $source, $m) === 1 ? trim($m[1]) : '';
    }

    public static function pageName(string $file, string $source): string
    {
        if ($file === 'README.md') {
            return 'Home';
        }

        $name = trim(preg_replace('/[^\p{L}\p{N} -]/u', '', self::titleOf($source)));

        return preg_replace('/\s+/u', '-', $name);
    }

    /** Resolve `.` e `..` de um caminho relativo, sem tocar no disco. */
    private static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /**
     * @param  array<int, array{file: string, source: string}>  $docs
     * @return array<int, array{name: string, title: string, body: string}>
     */
    public static function buildPages(array $docs): array
    {
        $names = [];
        foreach ($docs as $doc) {
            $names[$doc['file']] = self::pageName($doc['file'], $doc['source']);
        }

        $rewrite = function (array $m) use ($names): string {
            [$match, $text, $target] = $m;

            if (preg_match('/^[a-z]+:/i', $target) === 1 || str_starts_with($target, '#')) {
                return $match;
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $hash = $anchor === null ? '' : '#'.$anchor;
            $page = $names[self::normalize($path)] ?? null;

            if ($page !== null && !str_contains(self::normalize($path), '/')) {
                return "[{$text}]({$page}{$hash})";
            }

            $inRepo = self::normalize('docs/'.$path);
            $kind = pathinfo($inRepo, PATHINFO_EXTENSION) === '' ? 'tree' : 'blob';

            return "[{$text}](".self::REPO."/{$kind}/main/{$inRepo}{$hash})";
        };

        $pages = [];
        foreach ($docs as $doc) {
            $body = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', $rewrite, $doc['source']);

            if ($doc['file'] === 'README.md') {
                $body = preg_replace('/\nEsta pasta vai junto com o código\.[^\n]*\n/', '', $body, 1);
            }

            $pages[] = [
                'name' => $names[$doc['file']],
                'title' => $doc['file'] === 'README.md' ? self::TITLE : self::titleOf($doc['source']),
                'body' => self::banner().$body,
            ];
        }

        return $pages;
    }

    /** @param  array<int, array{name: string, title: string, body: string}>  $pages */
    public static function sidebar(array $pages): string
    {
        $items = [];
        foreach ($pages as $page) {
            if ($page['name'] !== 'Home') {
                $items[] = "- [{$page['title']}]({$page['name']})";
            }
        }

        return implode("\n", ['**['.self::TITLE.'](Home)**', '', ...$items, '', '[Código]('.self::REPO.')', '']);
    }

    /**
     * Home primeiro, depois a ordem do índice de docs/README.md; o que não está no índice vai no fim.
     *
     * @param  array<int, array{file: string, source: string}>  $docs
     * @return array<int, array{file: string, source: string}>
     */
    public static function ordered(array $docs): array
    {
        $index = '';
        foreach ($docs as $doc) {
            if ($doc['file'] === 'README.md') {
                $index = $doc['source'];
            }
        }

        $rank = function (array $doc) use ($index): int {
            if ($doc['file'] === 'README.md') {
                return -1;
            }

            $at = strpos($index, "]({$doc['file']}");

            return $at === false ? PHP_INT_MAX : $at;
        };

        usort($docs, fn (array $a, array $b) => [$rank($a), $a['file']] <=> [$rank($b), $b['file']]);

        return $docs;
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $wiki = $argv[1] ?? null;

    if ($wiki === null) {
        fwrite(STDERR, "Uso: php scripts/wiki.php <pasta do clone da wiki>\n");
        exit(1);
    }

    $docs = [];
    foreach (glob('docs/*.md') ?: [] as $path) {
        $docs[] = ['file' => basename($path), 'source' => file_get_contents($path)];
    }

    $pages = WikiMirror::buildPages(WikiMirror::ordered($docs));

    foreach (glob(rtrim($wiki, '/').'/*.md') ?: [] as $old) {
        unlink($old);
    }

    foreach ($pages as $page) {
        file_put_contents(rtrim($wiki, '/')."/{$page['name']}.md", $page['body']);
    }

    file_put_contents(rtrim($wiki, '/').'/_Sidebar.md', WikiMirror::sidebar($pages));
}
