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
        $this->appFile('.env', "VITE_ZAPMIZER_BRAND=zapmizer\n");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryApp();

        parent::tearDown();
    }

    /**
     * The 404 Zapmizer gives an unknown customer: ResolvesPartnerUser's
     * abort(404, 'Record not found.') through the api/* NotFound renderer.
     */
    private const UNKNOWN_CUSTOMER = ['message' => 'Record not found.', 'details' => 'Record not found.'];

    protected function partnerAnswers(int $status, array $body = self::UNKNOWN_CUSTOMER): void
    {
        Http::fake([
            'zap.test/api/partner/users/*' => Http::response($body, $status),
            // zap.test is not a known host: the check asks it for its brand.
            'zap.test/brand/manifest.json' => Http::response(['name' => 'Zapmizer']),
        ]);
    }

    /**
     * The human output (expectsOutputToContain() only exists from Laravel 9 on).
     */
    protected function checkHuman(int $exit): string
    {
        $code = Artisan::call('zapmizer:check');
        $output = Artisan::output();

        $this->assertSame($exit, $code, $output);

        return $output;
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

        $output = $this->checkHuman(0);

        $this->assertStringContainsString('a Zapmizer aceitou as credenciais de parceiro', $output);
        $this->assertStringContainsString('tabela zapmizer_connections criada', $output);
        $this->assertStringContainsString('NotificationChannels\Zapmizer\Test\Fixtures\User é Connectable', $output);
        $this->assertStringContainsString('Tudo certo para o connect.', $output);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'http://zap.test/api/partner/users/zapmizer-check'
            && $request->hasHeader('X-Partner-Key', '12|partner-secret')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function testA404OfAMissingRouteIsNotAnAcceptedKey()
    {
        // The base_uri without /api: Laravel's own 404 for the route.
        $this->partnerAnswers(404, ['message' => 'The route partner/users/zapmizer-check could not be found.']);

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['partner_api']['status']);
        $this->assertStringContainsString('rota de parceiro não existe', $result['checks']['partner_api']['message']);
        $this->assertStringContainsString('/api', $result['checks']['partner_api']['hint']);
    }

    public function testA404OfAMissingApiRouteIsNotAnAcceptedKeyEither()
    {
        // Under api/* every 404 says "Record not found."; `details` tells them apart.
        $this->partnerAnswers(404, ['message' => 'Record not found.', 'details' => 'The route api/v2/partner/users/zapmizer-check could not be found.']);

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['partner_api']['status']);
        $this->assertStringContainsString('The route api/v2', $result['checks']['partner_api']['message']);
    }

    public function testAnHtmlPageIsNotTheApi()
    {
        Http::fake(['zap.test/*' => Http::response('<html>welcome</html>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['partner_api']['status']);
    }

    public function testABaseUriWithoutApiFailsAndSkipsTheRemoteCheck()
    {
        config()->set('zapmizer.base_uri', 'https://app.parlichat.com/');
        Http::fake();

        $result = $this->checkJson();

        $this->assertSame(1, $result['exit']);
        $this->assertSame('fail', $result['checks']['base_uri']['status']);
        $this->assertStringContainsString('precisa terminar em /api', $result['checks']['base_uri']['message']);
        $this->assertSame('skip', $result['checks']['partner_api']['status']);
        Http::assertNothingSent();
    }

    public function testAnOldVueIsAWarningWithTheInbox()
    {
        $this->partnerAnswers(404);
        config()->set('zapmizer.inbox.enabled', true);
        $this->reloadPackageRoutes();
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3', 'vue' => '^3.2.47']]));

        $old = $this->checkJson();
        $this->assertSame(0, $old['exit']);
        $this->assertSame('warn', $old['checks']['vue_version']['status']);
        $this->assertStringContainsString('>= 3.3', $old['checks']['vue_version']['message']);

        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3', 'vue' => '^3.5.13']]));
        $this->assertArrayNotHasKey('vue_version', $this->checkJson()['checks']);
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
            ['app_key', 'config', 'base_uri', 'partner_credentials', 'partner_api', 'migration', 'connectable', 'connect_routes', 'inbox_routes', 'embed_package', 'embed_brand', 'app_url'],
            array_keys($result['checks']),
        );
        $this->assertSame('ok', $result['checks']['partner_api']['status']);
        $this->assertSame('skip', $result['checks']['inbox_routes']['status']);
        $this->assertSame('skip', $result['checks']['embed_package']['status']);
        $this->assertSame('skip', $result['checks']['embed_brand']['status']);
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

        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3']]));
        $pinned = $this->checkJson();
        $this->assertSame(0, $pinned['exit']);
        $this->assertSame('ok', $pinned['checks']['embed_package']['status']);
        $this->assertStringContainsString('v0.1.3', $pinned['checks']['embed_package']['message']);
    }

    public function testAnOlderEmbedTagIsAWarningToUpdate()
    {
        $this->partnerAnswers(404);
        config()->set('zapmizer.inbox.enabled', true);
        $this->reloadPackageRoutes();

        foreach (['v0.1.2', 'v0.1.0'] as $tag) {
            $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => "github:zapmizer/embed#{$tag}"]]));
            $result = $this->checkJson();

            $this->assertSame(0, $result['exit'], $tag);
            $this->assertSame('warn', $result['checks']['embed_package']['status'], $tag);
            $this->assertStringContainsString($tag, $result['checks']['embed_package']['message']);
            $this->assertStringContainsString('atualize para v0.1.3: tipos com moduleResolution node', $result['checks']['embed_package']['message']);
            $this->assertStringContainsString('github:zapmizer/embed#v0.1.3', $result['checks']['embed_package']['hint']);
        }

        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.2.0']]));
        $this->assertSame('ok', $this->checkJson()['checks']['embed_package']['status'], 'a newer tag is fine');
    }

    public function testTheFrontBrandMustMatchTheDeployOfTheBaseUri()
    {
        $this->partnerAnswers(404);
        config()->set('zapmizer.inbox.enabled', true);
        $this->reloadPackageRoutes();
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3']]));

        $match = $this->checkJson();
        $this->assertSame(0, $match['exit']);
        $this->assertSame('ok', $match['checks']['embed_brand']['status']);

        config()->set('zapmizer.base_uri', 'https://app.parlichat.com/api/');
        $mismatch = $this->checkJson(['--offline' => true]);
        $this->assertSame(1, $mismatch['exit']);
        $this->assertSame('fail', $mismatch['checks']['embed_brand']['status']);
        $this->assertStringContainsString('VITE_ZAPMIZER_BRAND=zapmizer, mas a marca do embed é parli', $mismatch['checks']['embed_brand']['message']);
        $this->assertStringContainsString('VITE_ZAPMIZER_BRAND=parli', $mismatch['checks']['embed_brand']['hint']);

        config()->set('zapmizer.inbox.brand', 'zapmizer');
        $this->assertSame('ok', $this->checkJson(['--offline' => true])['checks']['embed_brand']['status'], 'ZAPMIZER_EMBED_BRAND overrides the derived brand');

        config()->set('zapmizer.inbox.brand', null);
        $this->appFile('.env', "APP_NAME=Laravel\n");
        $missing = $this->checkJson(['--offline' => true]);
        $this->assertSame(1, $missing['exit']);
        $this->assertSame('fail', $missing['checks']['embed_brand']['status']);
        $this->assertStringContainsString('ausente', $missing['checks']['embed_brand']['message']);
    }

    public function testADeployOutsideTheKnownHostsIsAskedForItsBrand()
    {
        Http::fake([
            'localhost:8001/api/partner/users/*' => Http::response(self::UNKNOWN_CUSTOMER, 404),
            'localhost:8001/brand/manifest.json' => Http::response(['name' => 'Parli', 'short_name' => 'Parli']),
        ]);
        config()->set('zapmizer.base_uri', 'http://localhost:8001/api/');
        config()->set('zapmizer.inbox.enabled', true);
        $this->reloadPackageRoutes();
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3']]));

        // What the blind test installed: the host default, on a parli deploy.
        $wrong = $this->checkJson();
        $this->assertSame(1, $wrong['exit']);
        $this->assertSame('fail', $wrong['checks']['embed_brand']['status']);
        $this->assertStringContainsString('VITE_ZAPMIZER_BRAND=zapmizer, mas o deploy de localhost é parli', $wrong['checks']['embed_brand']['message']);
        $this->assertStringContainsString('VITE_ZAPMIZER_BRAND=parli e ZAPMIZER_EMBED_BRAND=parli', $wrong['checks']['embed_brand']['hint']);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8001/brand/manifest.json');

        $this->appFile('.env', "VITE_ZAPMIZER_BRAND=parli\n");
        $backend = $this->checkJson();
        $this->assertSame(0, $backend['exit']);
        $this->assertSame('warn', $backend['checks']['embed_brand']['status'], 'the backend (Blade) still derives zapmizer');

        config()->set('zapmizer.inbox.brand', 'parli');
        $right = $this->checkJson();
        $this->assertSame('ok', $right['checks']['embed_brand']['status']);
        $this->assertStringContainsString('confirmada pelo deploy de localhost', $right['checks']['embed_brand']['message']);
    }

    public function testADeployThatDoesNotTellItsBrandIsAWarningToAskTheHuman()
    {
        Http::fake([
            'localhost:8001/api/partner/users/*' => Http::response(self::UNKNOWN_CUSTOMER, 404),
            'localhost:8001/brand/*' => Http::response('', 404),
        ]);
        config()->set('zapmizer.base_uri', 'http://localhost:8001/api/');
        config()->set('zapmizer.inbox.enabled', true);
        $this->reloadPackageRoutes();
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3']]));

        foreach ([[], ['--offline' => true]] as $options) {
            $result = $this->checkJson($options);

            $this->assertSame(0, $result['exit']);
            $this->assertSame('warn', $result['checks']['embed_brand']['status']);
            $this->assertStringContainsString('localhost não é um deploy conhecido', $result['checks']['embed_brand']['message']);
            $this->assertStringContainsString('Confirme com o humano a marca do deploy', $result['checks']['embed_brand']['hint']);
        }
    }

    public function testInboxRoutesThatAreNotRegisteredFail()
    {
        $this->partnerAnswers(404);
        config()->set('zapmizer.inbox.enabled', true);
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3']]));

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
        $this->appFile('package.json', json_encode(['dependencies' => ['@zapmizer/embed' => 'github:zapmizer/embed#v0.1.3']]));

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

        $output = $this->checkHuman(1);

        $this->assertStringContainsString('ZAPMIZER_PARTNER_ID e ZAPMIZER_PARTNER_SECRET vazio no .env', $output);
        $this->assertStringContainsString('2 problemas críticos.', $output);
    }
}
