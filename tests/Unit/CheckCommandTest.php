<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Test\Concerns\TogglesConnectionWebhook;
use NotificationChannels\Zapmizer\Test\Concerns\UsesTemporaryApp;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\PlainUser;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesFirstTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;

class CheckCommandTest extends TestCase
{
    use CreatesConnectionTables;
    use TogglesConnectionWebhook;
    use UsesTemporaryApp;

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://app.example.com');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.partner.id', '12');
        $app['config']->set('zapmizer.partner.secret', 'partner-secret');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTemporaryApp();
        $this->appFile('config/zapmizer.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryApp();

        parent::tearDown();
    }

    protected function partnerAnswers(int $status, array $body = ['message' => 'Record not found.']): void
    {
        Http::fake(['zap.test/api/partner/users/*' => Http::response($body, $status)]);
    }

    /**
     * @return array{exit: int, ok: bool, checks: array<string, array{status: string, message: string, hint: string|null}>}
     */
    protected function checkJson(array $options = []): array
    {
        $exit = Artisan::call('zapmizer:check', $options + ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded, 'the --json output is JSON and nothing else');

        $checks = [];

        foreach ($decoded['checks'] as $check) {
            $this->assertSame(['id', 'status', 'message', 'hint'], array_keys($check));
            $checks[$check['id']] = $check;
        }

        return ['exit' => $exit, 'ok' => $decoded['ok'], 'checks' => $checks];
    }

    public function testAHealthyInstallPasses()
    {
        $this->partnerAnswers(404);

        $this->artisan('zapmizer:check')
            ->expectsOutputToContain('a Zapmizer aceitou as credenciais de parceiro')
            ->expectsOutputToContain('tabela zapmizer_connections criada')
            ->expectsOutputToContain('NotificationChannels\Zapmizer\Test\Fixtures\User é Connectable')
            ->expectsOutputToContain('Tudo certo para o connect.')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'http://zap.test/api/partner/users/zapmizer-check'
            && $request->hasHeader('X-Partner-Key', '12|partner-secret'));
    }

    public function testTheJsonOutputListsEveryCheck()
    {
        $this->partnerAnswers(200, [
            'user_id' => 3, 'team_id' => 7, 'external_id' => 'zapmizer-check', 'subscribed' => true,
            'quantity' => 1, 'trial_ends_at' => null, 'payment_incomplete' => false,
        ]);

        $result = $this->checkJson();

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['ok']);
        $this->assertSame(
            ['app_key', 'config', 'base_uri', 'partner_credentials', 'partner_api', 'migration', 'connectable', 'connect_routes', 'inbox_routes', 'embed_package', 'app_url'],
            array_keys($result['checks']),
        );
        $this->assertSame('ok', $result['checks']['partner_api']['status']);
        $this->assertSame('skip', $result['checks']['inbox_routes']['status']);
        $this->assertSame('skip', $result['checks']['embed_package']['status']);
    }

    public function testARefusedKeyFails()
    {
        $this->partnerAnswers(401, ['message' => 'Chave de parceiro inválida.']);

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertFalse($result['ok']);
        $this->assertSame('fail', $result['checks']['partner_api']['status']);
        $this->assertStringContainsString('recusou', $result['checks']['partner_api']['message']);
    }

    public function testWithoutNetworkTheRemoteCheckIsSkippedWithAWarning()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $result = $this->checkJson();

        $this->assertSame(0, $result['exit']);
        $this->assertSame('warn', $result['checks']['partner_api']['status']);
        $this->assertStringContainsString('sem rede', $result['checks']['partner_api']['message']);
    }

    public function testZapmizerDownIsAWarningToo()
    {
        $this->partnerAnswers(503, ['message' => 'busy']);

        $result = $this->checkJson();

        $this->assertSame(0, $result['exit']);
        $this->assertSame('warn', $result['checks']['partner_api']['status']);
        $this->assertStringContainsString('sem rede ou 5xx', $result['checks']['partner_api']['message']);
    }

    public function testRateLimitedIsAWarning()
    {
        $this->partnerAnswers(429, ['message' => 'Too Many Attempts.']);

        $result = $this->checkJson();

        $this->assertSame(0, $result['exit']);
        $this->assertSame('warn', $result['checks']['partner_api']['status']);
        $this->assertStringContainsString('429', $result['checks']['partner_api']['message']);
    }

    public function testOfflineSendsNothing()
    {
        Http::fake();

        $result = $this->checkJson(['--offline' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('skip', $result['checks']['partner_api']['status']);
        Http::assertNothingSent();
    }

    public function testMissingCredentialsFailWithoutCallingZapmizer()
    {
        config()->set('zapmizer.partner.secret', null);
        Http::fake();

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['partner_credentials']['status']);
        $this->assertSame('ZAPMIZER_PARTNER_SECRET vazio no .env', $result['checks']['partner_credentials']['message']);
        $this->assertStringContainsString('Não invente', $result['checks']['partner_credentials']['hint']);
        $this->assertSame('skip', $result['checks']['partner_api']['status']);
        Http::assertNothingSent();
    }

    public function testAPartnerIdThatIsNotNumericFails()
    {
        config()->set('zapmizer.partner.id', 'partner-id');
        Http::fake();

        $result = $this->checkJson();

        $this->assertSame('fail', $result['checks']['partner_credentials']['status']);
        Http::assertNothingSent();
    }

    public function testAMissingTableFails()
    {
        $this->partnerAnswers(404);
        Schema::drop('zapmizer_connections');

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['migration']['status']);
        $this->assertStringContainsString('zapmizer:install', $result['checks']['migration']['hint']);
    }

    public function testAUserModelThatIsNotConnectableFails()
    {
        $this->partnerAnswers(404);
        config()->set('auth.providers.users.model', PlainUser::class);

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['connectable']['status']);
        $this->assertStringContainsString('zapmizer:install --model=', $result['checks']['connectable']['hint']);
    }

    public function testACustomResolverListsTheConnectableModelsOfTheApp()
    {
        $this->partnerAnswers(404);
        config()->set('auth.providers.users.model', PlainUser::class);
        config()->set('zapmizer.connect.resolver', ResolvesFirstTeam::class);
        $this->appFile('app/Models/Team.php', "<?php\n\nnamespace App\\Models;\n\nuse NotificationChannels\\Zapmizer\\Contracts\\Connectable;\n\nclass Team extends Model implements Connectable\n{\n}\n");

        $result = $this->checkJson();

        $this->assertSame(0, $result['exit']);
        $this->assertSame('ok', $result['checks']['connectable']['status']);
        $this->assertStringContainsString('Connectable no app: Team', $result['checks']['connectable']['message']);
    }

    public function testTheInboxNeedsItsRoutesAndThePinnedEmbedPackage()
    {
        $this->partnerAnswers(404);
        config()->set('zapmizer.inbox.enabled', true);
        $this->reloadPackageRoutes();

        $missing = $this->checkJson();
        $this->assertSame(1, $missing['exit']);
        $this->assertSame('ok', $missing['checks']['inbox_routes']['status']);
        $this->assertSame('fail', $missing['checks']['embed_package']['status']);

        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed']]));
        $unpinned = $this->checkJson();
        $this->assertSame(0, $unpinned['exit']);
        $this->assertSame('warn', $unpinned['checks']['embed_package']['status']);

        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.2']]));
        $pinned = $this->checkJson();
        $this->assertSame(0, $pinned['exit']);
        $this->assertSame('ok', $pinned['checks']['embed_package']['status']);
        $this->assertStringContainsString('v0.1.2', $pinned['checks']['embed_package']['message']);
    }

    public function testInboxRoutesThatAreNotRegisteredFail()
    {
        $this->partnerAnswers(404);
        config()->set('zapmizer.inbox.enabled', true);
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.2']]));

        $result = $this->checkJson();

        $this->assertSame('fail', $result['checks']['inbox_routes']['status']);
        $this->assertStringContainsString('route:clear', $result['checks']['inbox_routes']['hint']);
    }

    public function testALocalAppUrlIsAWarningAndABrokenOneAFailure()
    {
        $this->partnerAnswers(404);

        config()->set('app.url', 'http://localhost');
        $local = $this->checkJson();
        $this->assertSame(0, $local['exit']);
        $this->assertSame('warn', $local['checks']['app_url']['status']);
        $this->assertStringContainsString('https', $local['checks']['app_url']['message']);

        config()->set('app.url', 'not a url');
        $broken = $this->checkJson();
        $this->assertSame(1, $broken['exit']);
        $this->assertSame('fail', $broken['checks']['app_url']['status']);
    }

    public function testAParentOriginWithAPathFails()
    {
        $this->partnerAnswers(404);
        config()->set('zapmizer.inbox.enabled', true);
        config()->set('zapmizer.inbox.parent_origin', 'https://app.example.com/inbox');
        $this->reloadPackageRoutes();
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.2']]));

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['parent_origin']['status']);
    }

    public function testNoAppKeyFails()
    {
        $this->partnerAnswers(404);
        config()->set('app.key', '');

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['app_key']['status']);
    }

    public function testTheHumanOutputCountsTheProblems()
    {
        config()->set('zapmizer.partner.id', null);
        config()->set('zapmizer.partner.secret', null);
        Schema::drop('zapmizer_connections');

        $this->artisan('zapmizer:check')
            ->expectsOutputToContain('ZAPMIZER_PARTNER_ID e ZAPMIZER_PARTNER_SECRET vazio no .env')
            ->expectsOutputToContain('2 problemas críticos.')
            ->assertExitCode(1);
    }
}
