<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Connect\BotInstance;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport;
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\InstanceGoneException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Test\Concerns\AssertsContract;
use NotificationChannels\Zapmizer\Test\Concerns\FakesZapmizerHttp;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P5: GET /bot-instances and GET /bot-instances/{id} — the number as the
 * API sees it, `supports_templates` (official number) and `is_online`.
 */
class InstanceClientInstanceTest extends TestCase
{
    use AssertsContract;
    use FakesZapmizerHttp;

    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function makeClient(Response|\Throwable ...$answers): InstanceClient
    {
        $this->fakeZapmizer(new MockHandler($answers));

        return new InstanceClient('team-token', new LaravelHttpTransport(), 'http://localhost/api', '2025-06-27');
    }

    public static function numero(array $overrides = []): array
    {
        return array_merge([
            'id' => 9,
            'created_at' => '2026-10-01T12:00:00.000000Z',
            'state' => 'ready',
            'is_online' => true,
            'is_up' => true,
            'supports_groups' => true,
            'supports_templates' => false,
            'state_label' => 'Conectado',
            'links' => ['self' => 'https://app.zapmizer.com/api/bot-instances/9'],
        ], $overrides);
    }

    public static function page(array $items): array
    {
        $count = count($items);

        return [
            'data' => $items,
            'links' => ['first' => 'https://app.zapmizer.com/api/bot-instances?page=1', 'last' => 'https://app.zapmizer.com/api/bot-instances?page=1', 'prev' => null, 'next' => null],
            'meta' => [
                'current_page' => 1, 'from' => $count === 0 ? null : 1, 'last_page' => 1, 'links' => [],
                'path' => 'https://app.zapmizer.com/api/bot-instances', 'per_page' => 15, 'to' => $count === 0 ? null : $count, 'total' => $count,
            ],
        ];
    }

    protected function jsonAnswer(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }

    public function testI1AQrNumberFromTheContract()
    {
        $body = json_encode(['data' => self::numero()]);
        $this->assertMatchesContract('GET', '/bot-instances/{id}', 200, $body);

        $instance = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body))->instance(9);

        $this->assertInstanceOf(BotInstance::class, $instance);
        $this->assertSame(9, $instance->id);
        $this->assertSame('ready', $instance->state);
        $this->assertSame('Conectado', $instance->stateLabel);
        $this->assertTrue($instance->isOnline);
        $this->assertTrue($instance->isUp);
        $this->assertTrue($instance->supportsGroups);
        $this->assertFalse($instance->supportsTemplates);
        $this->assertFalse($instance->isOfficial());
        $this->assertSame('2026-10-01T12:00:00+00:00', $instance->createdAt->toIso8601String());
        $this->assertSame('https://app.zapmizer.com/api/bot-instances/9', $instance->selfUrl);

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('http://localhost/api/bot-instances/9', (string) $request->getUri());
        $this->assertSame('Bearer team-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('2025-06-27', $request->getHeaderLine('api-version'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testI2AnOfficialNumberOfflineFromTheContract()
    {
        $body = json_encode(['data' => self::numero(['supports_templates' => true, 'supports_groups' => false, 'is_online' => false, 'is_up' => false, 'created_at' => null, 'state' => 'off', 'state_label' => 'Desligado'])]);
        $this->assertMatchesContract('GET', '/bot-instances/{id}', 200, $body);

        $instance = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body))->instance(9);

        $this->assertTrue($instance->isOfficial());
        $this->assertTrue($instance->supportsTemplates);
        $this->assertFalse($instance->supportsGroups);
        $this->assertFalse($instance->isOnline);
        $this->assertNull($instance->createdAt);
    }

    public function testI3TheListOfAConnectTokenHasTheAuthorizedNumberOnly()
    {
        $body = json_encode(self::page([self::numero()]));
        $this->assertMatchesContract('GET', '/bot-instances', 200, $body);

        $instances = $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body))->instances();

        $this->assertCount(1, $instances);
        $this->assertSame(9, $instances[0]->id);
        $this->assertSame('http://localhost/api/bot-instances', (string) $this->history[0]['request']->getUri());
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
    }

    public function testI4AnEmptyList()
    {
        $body = json_encode(self::page([]));
        $this->assertMatchesContract('GET', '/bot-instances', 200, $body);

        $this->assertSame([], $this->makeClient(new Response(200, ['Content-Type' => 'application/json'], $body))->instances());
    }

    public function testI5AbsentFlagsAreFalseAndAnUnreadableDateIsNull()
    {
        Log::spy();

        $instance = $this->makeClient($this->jsonAnswer(200, ['data' => ['id' => 9, 'created_at' => 'yesterday', 'is_online' => 'yes']]))->instance(9);

        $this->assertFalse($instance->isOnline);
        $this->assertFalse($instance->isUp);
        $this->assertFalse($instance->supportsGroups);
        $this->assertFalse($instance->supportsTemplates);
        $this->assertSame('', $instance->state);
        $this->assertSame('', $instance->stateLabel);
        $this->assertNull($instance->createdAt);
        $this->assertNull($instance->selfUrl);
        Log::shouldHaveReceived('warning')->once()->with('zapmizer: unreadable date.', ['field' => 'created_at', 'value' => 'yesterday', 'external_id' => null]);
    }

    /** @dataProvider invalidInstanceBodies */
    #[DataProvider('invalidInstanceBodies')]
    public function testI6AnInstanceWithoutAUsableIdOrDataIsUnexpected(array $body)
    {
        $this->expectException(ZapmizerConnectException::class);
        $this->expectExceptionMessageMatches('/data|id/');

        $this->makeClient($this->jsonAnswer(200, $body))->instance(9);
    }

    public static function invalidInstanceBodies(): array
    {
        return [
            'no data' => [self::numero()],
            'data is a list' => [['data' => [self::numero()]]],
            'no id' => [['data' => array_diff_key(self::numero(), ['id' => true])]],
            'id zero' => [['data' => self::numero(['id' => 0])]],
            'id as text' => [['data' => self::numero(['id' => 'nine'])]],
        ];
    }

    /** @dataProvider invalidLists */
    #[DataProvider('invalidLists')]
    public function testI6AListThatIsNotAListOfInstancesIsUnexpected(array $body)
    {
        $this->expectException(ZapmizerConnectException::class);

        $this->makeClient($this->jsonAnswer(200, $body))->instances();
    }

    public static function invalidLists(): array
    {
        return [
            'no data' => [['links' => [], 'meta' => []]],
            'data is an object' => [['data' => self::numero()]],
            'an item without an id' => [['data' => [['state' => 'ready']]]],
            'an item that is not an object' => [['data' => [9]]],
        ];
    }

    /** @dataProvider refusalsFromTheContract */
    #[DataProvider('refusalsFromTheContract')]
    public function testI7RefusalsFromTheContract(string $path, int $status, string $exception)
    {
        $body = '{"message":"Não encontrado."}';
        $this->assertMatchesContract('GET', $path, $status, $body);

        $client = $this->makeClient(new Response($status, ['Content-Type' => 'application/json'], $body));

        try {
            $path === '/bot-instances' ? $client->instances() : $client->instance(9);
            $this->fail('no exception');
        } catch (ZapmizerConnectException $e) {
            $this->assertInstanceOf($exception, $e);
        }
    }

    public static function refusalsFromTheContract(): array
    {
        return [
            'show 401' => ['/bot-instances/{id}', 401, ZapmizerUnauthorizedException::class],
            'show 404: gone or not this connection\'s' => ['/bot-instances/{id}', 404, InstanceGoneException::class],
            'index 401' => ['/bot-instances', 401, ZapmizerUnauthorizedException::class],
        ];
    }

    public function testI8TheGoneExceptionNamesTheInstance()
    {
        try {
            $this->makeClient($this->jsonAnswer(404, ['message' => 'Não encontrado.']))->instance(9);
            $this->fail('no exception');
        } catch (InstanceGoneException $e) {
            $this->assertSame(404, $e->status());
            $this->assertStringContainsString('Instance 9 is gone', $e->getMessage());
        }
    }

    /** @dataProvider refusalsOutsideTheContract */
    #[DataProvider('refusalsOutsideTheContract')]
    public function testI9RefusalsOutsideTheContract(string $method, Response|\Throwable $answer, string $exception, ?string $error = null)
    {
        $client = $this->makeClient($answer);

        try {
            $method === 'instance' ? $client->instance(9) : $client->instances();
            $this->fail('no exception');
        } catch (ZapmizerConnectException $e) {
            $this->assertSame($exception, get_class($e));

            if ($error !== null) {
                $this->assertSame($error, $e->error());
            }
        }
    }

    public static function refusalsOutsideTheContract(): array
    {
        $json = ['Content-Type' => 'application/json'];
        $cases = [];

        foreach (['instance', 'instances'] as $method) {
            $cases += [
                "{$method}: 403 missing_ability (a token without numbers:read)" => [$method, new Response(403, $json, '{"message":"Invalid ability provided.","error":"missing_ability"}'), ZapmizerApiException::class, ErrorCode::MISSING_ABILITY],
                "{$method}: 429" => [$method, new Response(429, $json + ['Retry-After' => '5'], '{"message":"Too Many Attempts."}'), ZapmizerRateLimitedException::class],
                "{$method}: 500" => [$method, new Response(500, [], ''), ZapmizerUnavailableException::class],
                "{$method}: 503" => [$method, new Response(503, [], ''), ZapmizerUnavailableException::class],
                "{$method}: no answer" => [$method, new ConnectException('timeout', new Request('GET', 'http://localhost/api/bot-instances')), ZapmizerUnavailableException::class],
                "{$method}: redirect to the login page" => [$method, new Response(302, ['Location' => 'http://localhost/login'], ''), ZapmizerConnectException::class],
                "{$method}: html instead of json" => [$method, new Response(200, ['Content-Type' => 'text/html'], '<html></html>'), ZapmizerConnectException::class],
            ];
        }

        return $cases;
    }
}
