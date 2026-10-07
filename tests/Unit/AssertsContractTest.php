<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class AssertsContractTest extends TestCase
{
    use AssertsContract;

    protected function assertContractRefuses(callable $assertion, string $expected): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString($expected, $failure->getMessage());

            return;
        }

        $this->fail('The contract assertion should have failed.');
    }

    public function testAResponseRefIsResolved()
    {
        $this->assertMatchesContract('POST', '/connect/sessions', 422, '{"message":"Invalid.","errors":{"redirect_uri":["required"]}}');
    }

    public function testAnEmptyErrorsObjectIsAnObject()
    {
        $this->assertMatchesContract('POST', '/connect/sessions', 422, '{"message":"Invalid.","errors":{}}');
    }

    public function testASchemaRefInsideTheDocumentIsResolvedOnAPathWithAnId()
    {
        $this->assertMatchesContract('GET', '/bot-instances/{id}/connection', 200, json_encode(['data' => [
            'id' => 9, 'state' => 'connected', 'state_label' => 'Conectado', 'is_online' => true, 'is_up' => true,
            'qrcode' => null, 'qrcode_available_at' => null, 'qrcode_expires_at' => null, 'number' => '5581911110000',
            'disconnect_reason' => null, 'disconnect_kind' => null, 'disconnect_kind_label' => null,
        ]]));

        $this->assertContractRefuses(
            fn () => $this->assertMatchesContract('GET', '/bot-instances/{id}/connection', 200, '{"data":{"state":"connected"}}'),
            'does not match the contract',
        );
    }

    public function testAnInlineSchemaIsValidated()
    {
        $this->assertMatchesContract('POST', '/messages', 409, '{"error":"window_closed","message":"Fechou.","window_expires_at":"2026-10-06T10:00:00Z"}');

        $this->assertContractRefuses(
            fn () => $this->assertMatchesContract('POST', '/messages', 409, '{"error":"window_closed","window_expires_at":"2026-10-06 10:00:00"}'),
            'date-time',
        );
    }

    public function testAnInvalidBodyFails()
    {
        $this->assertContractRefuses(
            fn () => $this->assertMatchesContract('POST', '/messages', 422, '{"error":"not_a_code"}'),
            'does not match the contract',
        );
        $this->assertContractRefuses(
            fn () => $this->assertMatchesContract('POST', '/messages', 401, '<html></html>'),
            'is not JSON',
        );
    }

    public function testAnUnknownRouteMethodOrStatusFails()
    {
        $this->assertContractRefuses(fn () => $this->assertMatchesContract('POST', '/webhooks', 422, '{}'), 'POST /webhooks is not in the contract snapshot');
        $this->assertContractRefuses(fn () => $this->assertMatchesContract('PATCH', '/messages', 422, '{}'), 'PATCH /messages is not in the contract snapshot');
        $this->assertContractRefuses(fn () => $this->assertMatchesContract('POST', '/messages', 429, '{}'), 'does not declare a 429 response');
        $this->assertContractRefuses(fn () => $this->assertMatchesContract('GET', '/bot-instances/9/connection', 404, '{"message":"x"}'), 'is not in the contract snapshot');
    }

    public function testABinaryResponseFails()
    {
        $this->assertContractRefuses(
            fn () => $this->assertMatchesContract('GET', '/whatsapp-messages/media', 200, '{}'),
            'has no application/json body',
        );
    }

    public function testADeclaredResponseWithoutBodyOnlyChecksTheStatus()
    {
        $this->assertMatchesContract('DELETE', '/connect/token', 204, '');

        $this->assertContractRefuses(
            fn () => $this->assertMatchesContract('DELETE', '/connect/token', 200, ''),
            'does not declare a 200 response',
        );
    }

    public function testADeclaredRetryAfterIsRequired()
    {
        $this->assertMatchesContract('POST', '/connect/sessions', 429, '{"message":"Too Many Attempts."}', ['retry-after' => '12']);

        $this->assertContractRefuses(
            fn () => $this->assertMatchesContract('POST', '/connect/sessions', 429, '{"message":"Too Many Attempts."}'),
            'declares Retry-After',
        );
    }
}
