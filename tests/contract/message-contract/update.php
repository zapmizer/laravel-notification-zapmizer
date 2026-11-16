<?php

// Copies Zapmizer's message-contract fixtures from a zapbot checkout
// (bot-v2/tests/fixtures/message-contract). `wwebjs/*`: `raw` is the
// whatsapp-web.js message (the `data[0]` of the `message` webhook) and
// `message` Zapmizer's own reading of it. `whatsapp_rust/*`: the Rust
// engine's event, Zapmizer's reading of it (`message`) and part of the
// whatsapp-web.js imitation the legacy webhook gets (`legacy_payload`).
// The parity test holds Message::fromLegacy() to them.
//
// php tests/contract/message-contract/update.php /path/to/zapbot [ref]
//
// `ref` defaults to origin/main (run `git fetch` there first). Nothing is
// checked out: the files are read with `git show`.

$repository = $argv[1] ?? null;
$ref = $argv[2] ?? 'origin/main';
$source = 'bot-v2/tests/fixtures/message-contract';
$engines = ['wwebjs', 'whatsapp_rust'];

if ($repository === null || !is_dir($repository)) {
    fwrite(STDERR, 'Usage: php tests/contract/message-contract/update.php /path/to/zapbot [ref]' . PHP_EOL);
    exit(1);
}

$git = fn (string $arguments) => shell_exec('git -C ' . escapeshellarg($repository) . ' ' . $arguments . ' 2>/dev/null');

$commit = trim((string) $git('rev-parse --verify ' . escapeshellarg($ref . '^{commit}')));
$copied = 0;

foreach ($engines as $engine) {
    $files = array_filter(explode("\n", (string) $git('ls-tree --name-only ' . escapeshellarg($commit) . ' ' . escapeshellarg("{$source}/{$engine}/"))));
    $target = __DIR__ . '/' . $engine;

    if ($commit === '' || $files === []) {
        fwrite(STDERR, "No fixtures at {$ref}:{$source}/{$engine}." . PHP_EOL);
        exit(1);
    }

    foreach (glob($target . '/*.json') ?: [] as $stale) {
        unlink($stale);
    }

    if (!is_dir($target)) {
        mkdir($target, 0777, true);
    }

    foreach ($files as $file) {
        $json = (string) $git('show ' . escapeshellarg($commit . ':' . $file));

        if (!is_array(json_decode($json, true))) {
            fwrite(STDERR, "{$file} is not valid JSON." . PHP_EOL);
            exit(1);
        }

        file_put_contents($target . '/' . basename($file), $json);
        $copied++;
    }
}

$date = trim((string) $git('show -s --format=%ci ' . escapeshellarg($commit)));

file_put_contents(__DIR__ . '/SOURCE', implode(PHP_EOL, [
    "source: leettech/zapbot {$source}",
    "commit: {$commit} ({$ref}, {$date})",
    'fetched: ' . gmdate('Y-m-d H:i:s') . ' UTC',
]) . PHP_EOL);

echo "{$copied} fixtures copied to tests/contract/message-contract. Review the diff before committing." . PHP_EOL;
