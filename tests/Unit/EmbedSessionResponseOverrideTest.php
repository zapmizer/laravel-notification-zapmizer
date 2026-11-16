<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\Fixtures\ScreenCodeEmbedResponse;
use NotificationChannels\Zapmizer\Test\Fixtures\StatusEmbedResponse;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

class EmbedSessionResponseOverrideTest extends TestCase
{
    use FakesZapmizerHttp;

    protected array $history = [];

    protected function tearDown(): void
    {
        StatusEmbedResponse::$status = 401;

        parent::tearDown();
    }

    protected function client(Response|\Throwable ...$answers): InstanceClient
    {
        $this->fakeZapmizer(new MockHandler($answers));

        return new InstanceClient('team-token', new LaravelHttpTransport(), 'http://localhost/api', '2025-06-27');
    }

    protected function answer(EmbedSessionResponse $response): \Illuminate\Http\JsonResponse
    {
        return $response->toResponse(Request::create('/'));
    }

    /** @dataProvider screenPolicy */
    #[DataProvider('screenPolicy')]
    public function testO1TheScreenPolicy(Response|\Throwable $answer, int $status, string $code, ?string $retryAfter)
    {
        $client = $this->client($answer);

        $json = $this->answer(ScreenCodeEmbedResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test')));

        $this->assertSame($status, $json->getStatusCode());
        $this->assertSame(['code' => $code], $json->getData(true));
        $this->assertSame($retryAfter, $json->headers->get('Retry-After'));
    }

    public static function screenPolicy(): array
    {
        $json = ['Content-Type' => 'application/json'];

        $rows = [
            'O1 403 without error' => [new Response(403, $json, '{"message":"Forbidden."}'), 422, 'reauth_required', null],
            'O1 missing_ability' => [new Response(403, $json, '{"error":"missing_ability"}'), 422, 'reauth_required', null],
            'O1 not_a_partner_connection' => [new Response(403, $json, '{"error":"not_a_partner_connection"}'), 422, 'reauth_required', null],
            'O1 origin_not_allowed' => [new Response(403, $json, '{"error":"origin_not_allowed"}'), 422, 'origin_not_allowed', null],
            'O1 subscription_required' => [new Response(402, $json, '{"error":"subscription_required"}'), 422, 'subscription_required', null],
            'O1 402 without error' => [new Response(402, $json, '{"message":"Payment required."}'), 503, 'unavailable', null],
            'O1 422 without error' => [new Response(422, $json, '{"message":"Invalid."}'), 422, 'rejected', null],
            'O1 number_unavailable' => [new Response(422, $json, '{"error":"number_unavailable"}'), 422, 'number_unavailable', null],
            'O1 404' => [new Response(404, $json, '{"message":"Not Found"}'), 503, 'unavailable', null],
            'O1 409 x' => [new Response(409, $json, '{"error":"x"}'), 503, 'unavailable', null],
            'O1 401' => [new Response(401, $json, '{"message":"Unauthenticated."}'), 422, 'reauth_required', null],
            'O1 429' => [new Response(429, $json + ['Retry-After' => '30'], '{"message":"Too Many Attempts."}'), 429, 'rate_limited', '30'],
            'O1 500' => [new Response(500, [], 'boom'), 503, 'unavailable', null],
            'O1 201 without url' => [new Response(201, $json, '{"expires_at":"2026-09-26T14:01:00+00:00"}'), 503, 'unavailable', null],
        ];

        foreach (['O1b error 5' => '{"error":5}', 'O1b error true' => '{"error":true}', 'O1c empty error' => '{"error":""}'] as $name => $body) {
            $rows["{$name} in 403"] = [new Response(403, $json, $body), 422, 'reauth_required', null];
            $rows["{$name} in 422"] = [new Response(422, $json, $body), 422, 'rejected', null];
            $rows["{$name} in 402"] = [new Response(402, $json, $body), 503, 'unavailable', null];
        }

        return $rows;
    }

    /** @dataProvider statusesThatAreNotARefusal */
    #[DataProvider('statusesThatAreNotARefusal')]
    public function testO2ARefusalStatusThatIsNotARefusalIsRefused(int $status)
    {
        StatusEmbedResponse::$status = $status;
        $client = $this->client(new Response(403, ['Content-Type' => 'application/json'], '{"error":"origin_not_allowed"}'));
        $response = StatusEmbedResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(StatusEmbedResponse::class . "::refusalStatus() returned {$status}: a refusal is a 4xx or 5xx other than 401 and 419.");

        $this->answer($response);
    }

    public static function statusesThatAreNotARefusal(): array
    {
        return ['401' => [401], '419' => [419], '302' => [302], '200' => [200], '600' => [600]];
    }

    public function testO2AValidRefusalStatusPasses()
    {
        StatusEmbedResponse::$status = 503;
        $client = $this->client(new Response(403, ['Content-Type' => 'application/json'], '{"error":"origin_not_allowed"}'));

        $json = $this->answer(StatusEmbedResponse::open(fn () => $client->conversationSession('5521988887777', 'https://app.test')));

        $this->assertSame(503, $json->getStatusCode());
        $this->assertSame(['code' => 'origin_not_allowed'], $json->getData(true));
    }
}
