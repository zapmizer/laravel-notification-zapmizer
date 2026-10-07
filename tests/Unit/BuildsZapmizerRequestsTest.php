<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Test\Fixtures\RecordingTransport;
use NotificationChannels\Zapmizer\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BuildsZapmizerRequestsTest extends TestCase
{
    protected function exposed(string $client): object
    {
        return match ($client) {
            'partner' => new class ('partner-id', 'partner-secret', new RecordingTransport(), 'http://localhost/api') extends PartnerClient {
                public function exposedGuardState(?string $state): void
                {
                    $this->guardState($state);
                }

                public function exposedWithoutNulls(array $body): array
                {
                    return $this->withoutNulls($body);
                }

                public function exposedClampExpiresIn(?int $expiresIn): ?int
                {
                    return $this->clampExpiresIn($expiresIn);
                }
            },
            'instance' => new class ('team-token', new RecordingTransport(), 'http://localhost/api') extends InstanceClient {
                public function exposedGuardState(?string $state): void
                {
                    $this->guardState($state);
                }

                public function exposedWithoutNulls(array $body): array
                {
                    return $this->withoutNulls($body);
                }

                public function exposedClampExpiresIn(?int $expiresIn): ?int
                {
                    return $this->clampExpiresIn($expiresIn);
                }
            },
        };
    }

    public static function clients(): array
    {
        return ['partner' => ['partner'], 'instance' => ['instance']];
    }

    /** @dataProvider expiresIn */
    #[DataProvider('expiresIn')]
    public function testBothClientsKeepExpiresInWithinWhatZapmizerAllows(string $client, ?int $requested, ?int $expected)
    {
        $this->assertSame($expected, $this->exposed($client)->exposedClampExpiresIn($requested));
    }

    public static function expiresIn(): array
    {
        $cases = [
            'null' => [null, null],
            'far below the floor' => [100, 900],
            'below the floor' => [899, 900],
            'at the floor' => [900, 900],
            'within' => [3600, 3600],
            'at the ceiling' => [86400, 86400],
            'above the ceiling' => [100000, 86400],
        ];
        $data = [];

        foreach (['partner', 'instance'] as $client) {
            foreach ($cases as $name => [$requested, $expected]) {
                $data["{$client} {$name}"] = [$client, $requested, $expected];
            }
        }

        return $data;
    }

    /** @dataProvider invalidStates */
    #[DataProvider('invalidStates')]
    public function testBothClientsRefuseTheSameStates(string $client, string $state)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The state must not be "" or "0"');

        $this->exposed($client)->exposedGuardState($state);
    }

    public static function invalidStates(): array
    {
        $states = ['empty' => '', 'zero' => '0', 'leading space' => ' a', 'trailing newline' => "a\n", 'only spaces' => '   '];
        $data = [];

        foreach (['partner', 'instance'] as $client) {
            foreach ($states as $name => $state) {
                $data["{$client} {$name}"] = [$client, $state];
            }
        }

        return $data;
    }

    /** @dataProvider clients */
    #[DataProvider('clients')]
    public function testBothClientsAcceptAStateZapmizerKeeps(string $client)
    {
        $exposed = $this->exposed($client);

        $exposed->exposedGuardState(null);
        $exposed->exposedGuardState('a b');
        $exposed->exposedGuardState('00');

        $this->addToAssertionCount(1);
    }

    /** @dataProvider clients */
    #[DataProvider('clients')]
    public function testBothClientsDropOnlyTheNulls(string $client)
    {
        $this->assertSame(
            ['empty' => '', 'zero' => 0, 'false' => false, 'list' => []],
            $this->exposed($client)->exposedWithoutNulls(['empty' => '', 'null' => null, 'zero' => 0, 'false' => false, 'list' => []]),
        );
    }

    public function testT3AnOverriddenGuardStateHoldsInThePartnerClient()
    {
        $transport = new RecordingTransport(new Response(201, ['Content-Type' => 'application/json'], '{"url":"http://localhost/connect/1"}'));
        $client = new class ('partner-id', 'partner-secret', $transport, 'http://localhost/api') extends PartnerClient {
            protected function guardState(?string $state): void
            {
            }
        };

        $client->createSession('http://app.test/cb', '0');

        $this->assertSame(['redirect_uri' => 'http://app.test/cb', 'state' => '0'], $transport->calls[0]['options']['json']);
    }
}
