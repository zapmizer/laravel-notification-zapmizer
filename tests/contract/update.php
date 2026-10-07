<?php

$url = 'https://docs.parlichat.com/openapi.json';
$json = @file_get_contents($url);

if ($json === false || !is_object(json_decode($json))) {
    fwrite(STDERR, "Could not download a valid OpenAPI document from {$url}." . PHP_EOL);
    exit(1);
}

file_put_contents(__DIR__ . '/openapi.json', $json);
file_put_contents(__DIR__ . '/SOURCE', "source: {$url}" . PHP_EOL . 'fetched: ' . gmdate('Y-m-d H:i:s') . ' UTC' . PHP_EOL);

echo 'tests/contract/openapi.json updated. Review the diff before committing.' . PHP_EOL;
