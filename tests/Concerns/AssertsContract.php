<?php

namespace NotificationChannels\Zapmizer\Test\Concerns;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

trait AssertsContract
{
    private static ?object $contractDocument = null;

    private static ?Validator $contractValidator = null;

    protected static function contractDocument(): object
    {
        return self::$contractDocument ??= json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/contract/openapi.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    protected static function contractId(): string
    {
        return 'https://contract.test/openapi.json';
    }

    protected function assertMatchesContract(string $method, string $pathTemplate, int $status, string $rawBody, array $headers = []): void
    {
        $document = self::contractDocument();
        $method = strtolower($method);
        $operation = $document->paths->{$pathTemplate}->{$method} ?? null;

        if ($operation === null) {
            $this->fail(sprintf('%s %s is not in the contract snapshot.', strtoupper($method), $pathTemplate));
        }

        $tokens = ['paths', $pathTemplate, $method, 'responses', (string) $status];
        $response = $operation->responses->{(string) $status} ?? null;

        if ($response === null) {
            $this->fail(sprintf('%s %s does not declare a %d response in the contract snapshot.', strtoupper($method), $pathTemplate, $status));
        }

        if (isset($response->{'$ref'})) {
            $tokens = array_map(
                fn (string $token) => str_replace(['~1', '~0'], ['/', '~'], $token),
                explode('/', substr($response->{'$ref'}, 2)),
            );
            $response = $document;

            foreach ($tokens as $token) {
                $response = $response->{$token};
            }
        }

        if (!isset($response->content)) {
            $this->addToAssertionCount(1);

            return;
        }

        if (!isset($response->content->{'application/json'}->schema)) {
            $this->fail(sprintf('The %d response of %s %s has no application/json body in the contract snapshot.', $status, strtoupper($method), $pathTemplate));
        }

        if (isset($response->headers->{'Retry-After'})) {
            $this->assertContains(
                'retry-after',
                array_map('strtolower', array_keys($headers)),
                sprintf('The %d response of %s %s declares Retry-After; the fake must send it.', $status, strtoupper($method), $pathTemplate),
            );
        }

        $data = json_decode($rawBody);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail(sprintf('The fake body for %s %s %d is not JSON: %s', strtoupper($method), $pathTemplate, $status, $rawBody));
        }

        $pointer = implode('/', array_map(
            fn (string $token) => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $token)),
            [...$tokens, 'content', 'application/json', 'schema'],
        ));

        $result = self::contractValidator()->validate($data, (object) ['$ref' => self::contractId() . '#/' . $pointer]);

        if (!$result->isValid()) {
            $this->fail(sprintf(
                'The fake body for %s %s %d does not match the contract: %s',
                strtoupper($method),
                $pathTemplate,
                $status,
                json_encode((new ErrorFormatter())->format($result->error()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
        }

        $this->addToAssertionCount(1);
    }

    private static function contractValidator(): Validator
    {
        if (self::$contractValidator === null) {
            self::$contractValidator = new Validator();
            self::$contractValidator->resolver()->registerRaw(self::contractDocument(), self::contractId());
        }

        return self::$contractValidator;
    }
}
