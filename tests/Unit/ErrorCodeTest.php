<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use ReflectionClass;

class ErrorCodeTest extends TestCase
{
    use AssertsContract;

    public function testTheConstantsAreTheErrorCodesOfTheContract()
    {
        $constants = array_values((new ReflectionClass(ErrorCode::class))->getConstants());
        sort($constants);

        $this->assertSame($this->contractErrorCodes(), $constants);
    }

    public function testEachConstantIsNamedAfterItsCode()
    {
        foreach ((new ReflectionClass(ErrorCode::class))->getConstants() as $name => $value) {
            $this->assertSame(strtoupper($value), $name);
        }
    }

    /**
     * @return string[]
     */
    protected function contractErrorCodes(): array
    {
        $codes = [];

        foreach (self::contractDocument()->paths as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation->responses ?? [] as $status => $response) {
                    if (!str_starts_with((string) $status, '4')) {
                        continue;
                    }

                    foreach ($this->resolve($response)->content ?? [] as $media) {
                        $codes = [...$codes, ...$this->errorCodesOf($media->schema ?? null)];
                    }
                }
            }
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * @return string[]
     */
    protected function errorCodesOf(mixed $schema): array
    {
        $schema = $this->resolve($schema);

        if (!is_object($schema)) {
            return [];
        }

        $codes = isset($schema->properties->error) ? $this->stringsOf($schema->properties->error) : [];

        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            foreach ($schema->{$keyword} ?? [] as $branch) {
                $codes = [...$codes, ...$this->errorCodesOf($branch)];
            }
        }

        return $codes;
    }

    /**
     * @return string[]
     */
    protected function stringsOf(mixed $schema): array
    {
        $schema = $this->resolve($schema);

        if (!is_object($schema)) {
            return [];
        }

        $strings = array_values(array_filter($schema->enum ?? [], 'is_string'));

        if (is_string($schema->const ?? null)) {
            $strings[] = $schema->const;
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            foreach ($schema->{$keyword} ?? [] as $branch) {
                $strings = [...$strings, ...$this->stringsOf($branch)];
            }
        }

        return $strings;
    }

    protected function resolve(mixed $node): mixed
    {
        while (is_object($node) && isset($node->{'$ref'})) {
            $target = self::contractDocument();

            foreach (explode('/', substr($node->{'$ref'}, 2)) as $token) {
                $target = $target->{str_replace(['~1', '~0'], ['/', '~'], $token)};
            }

            $node = $target;
        }

        return $node;
    }
}
