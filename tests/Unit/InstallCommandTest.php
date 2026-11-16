<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Test\Concerns\UsesTemporaryApp;
use NotificationChannels\Zapmizer\Test\TestCase;

class InstallCommandTest extends TestCase
{
    use UsesTemporaryApp;

    private const USER_MODEL = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
}

PHP;

    private const ENV = "APP_NAME=Laravel\nAPP_URL=https://app.example.com\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTemporaryApp();
        config()->set('app.url', 'https://app.example.com');
        $this->appFile('.env', self::ENV);
        $this->appFile('.env.example', self::ENV);
        $this->appFile('app/Models/User.php', self::USER_MODEL);
        $this->appFile('package.json', json_encode(['private' => true, 'devDependencies' => ['vue' => '^3.5.0', 'axios' => '^1.7.0']]));
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryApp();

        parent::tearDown();
    }

    /**
     * Artisan::call() and the output, not expectsOutputToContain(): that one
     * only exists from Laravel 9 on.
     *
     * @param array<int, string> $contains
     */
    protected function assertInstall(array $options = [], int $exit = 0, array $contains = []): string
    {
        $code = Artisan::call('zapmizer:install', $options + ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame($exit, $code, $output);

        foreach ($contains as $text) {
            $this->assertStringContainsString($text, $output);
        }

        return $output;
    }

    public function testNoInteractionDoesOnlyTheSafePart()
    {
        $this->assertInstall([], 0, [
            'config/zapmizer.php publicada',
            'Próximos passos',
            'ZAPMIZER_PARTNER_ID e ZAPMIZER_PARTNER_SECRET',
            'liberar o host app.example.com',
            'php artisan zapmizer:check',
        ]);

        $this->assertFileEquals(__DIR__ . '/../../config/zapmizer.php', $this->appFile('config/zapmizer.php'));
        $this->assertCount(1, $this->publishedMigrations());
        $this->assertFileEquals(__DIR__ . '/../../database/migrations/create_zapmizer_connections_table.php.stub', $this->publishedMigrations()[0]);
        $this->assertSame(self::USER_MODEL, $this->readAppFile('app/Models/User.php'), 'no model without --model');
        $this->assertFileDoesNotExist($this->appFile('resources/js/zapmizer/InboxScreen.vue'));
        $this->assertFalse(Schema::hasTable('zapmizer_connections'), 'no migrate without --migrate');

        $expected = self::ENV . "\nZAPMIZER_BASE_URI=https://app.zapmizer.com/api/\nZAPMIZER_API_VERSION=2025-06-27\nZAPMIZER_PARTNER_ID=\nZAPMIZER_PARTNER_SECRET=\nVITE_ZAPMIZER_BRAND=zapmizer\n";

        $this->assertSame($expected, $this->readAppFile('.env'));
        $this->assertSame($expected, $this->readAppFile('.env.example'));
    }

    public function testRunningTwiceChangesNothingTheSecondTime()
    {
        $this->assertInstall(['--model' => 'App\\Models\\User', '--inbox' => true], 0);

        $snapshot = $this->snapshot();

        $this->assertInstall(['--model' => 'App\\Models\\User', '--inbox' => true], 0, [
            'config/zapmizer.php já existe',
            'migration da zapmizer_connections já publicada',
            '.env já tem as chaves da Zapmizer',
            'App\\Models\\User já é Connectable',
            'componentes Vue já publicados',
        ]);

        $this->assertSame($snapshot, $this->snapshot());
        $this->assertCount(1, $this->publishedMigrations());
    }

    public function testExistingKeysAreKeptAndOnlyTheMissingOnesAdded()
    {
        Http::fake(['localhost:8001/*' => Http::response('', 404)]);
        $this->appFile('.env', self::ENV . "export ZAPMIZER_PARTNER_ID=42\nZAPMIZER_BASE_URI = http://localhost:8001/api/\n");

        $this->assertInstall([], 0);

        $env = $this->readAppFile('.env');

        $this->assertStringContainsString("export ZAPMIZER_PARTNER_ID=42\nZAPMIZER_BASE_URI = http://localhost:8001/api/\n\nZAPMIZER_API_VERSION=2025-06-27\nZAPMIZER_PARTNER_SECRET=\n", $env);
        $this->assertSame(1, substr_count($env, 'ZAPMIZER_PARTNER_ID'));
        $this->assertSame(1, substr_count($env, 'ZAPMIZER_BASE_URI'));
    }

    public function testTheBaseUriFlagWritesTheKeyAndTheBrandOfItsDeploy()
    {
        $this->assertInstall(['--inbox' => true, '--base-uri' => 'https://app.parlichat.com/api/'], 0, ['VITE_ZAPMIZER_BRAND=parli do .env entra no bundle']);

        foreach (['.env', '.env.example'] as $file) {
            $this->assertStringContainsString("ZAPMIZER_BASE_URI=https://app.parlichat.com/api/\n", $this->readAppFile($file));
            $this->assertStringContainsString("VITE_ZAPMIZER_BRAND=parli\n", $this->readAppFile($file));
        }
    }

    public function testTheBaseUriAlreadyInDotEnvDecidesTheBrand()
    {
        $this->appFile('.env', self::ENV . "ZAPMIZER_BASE_URI=https://app.parlichat.com/api/ # parli\n");

        $output = $this->assertInstall();

        $this->assertStringContainsString("VITE_ZAPMIZER_BRAND=parli\n", $this->readAppFile('.env'));
        $this->assertStringNotContainsString('Confirme com o humano em qual deploy', $output);
    }

    public function testADeployOutsideTheKnownHostsTellsItsBrand()
    {
        Http::fake(['localhost:8001/brand/manifest.json' => Http::response(['name' => 'Parli', 'short_name' => 'Parli'])]);

        $output = $this->assertInstall(['--inbox' => true, '--base-uri' => 'http://localhost:8001/api/'], 0, [
            'marca do deploy: parli (lida de http://localhost:8001/brand/manifest.json)',
            'VITE_ZAPMIZER_BRAND=parli do .env entra no bundle',
        ]);

        foreach (['.env', '.env.example'] as $file) {
            $this->assertStringContainsString("VITE_ZAPMIZER_BRAND=parli\n", $this->readAppFile($file));
            $this->assertStringContainsString("ZAPMIZER_EMBED_BRAND=parli\n", $this->readAppFile($file), 'the backend would derive zapmizer from the host');
        }

        $this->assertStringNotContainsString('Confirme com o humano a marca do deploy', $output);
    }

    public function testADeployThatDoesNotTellItsBrandGoesToTheHuman()
    {
        Http::fake(['localhost:8001/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertInstall(['--inbox' => true, '--base-uri' => 'http://localhost:8001/api/'], 0, [
            'não consegui ler a marca do deploy',
            'Confirme com o humano a marca do deploy de http://localhost:8001/api/',
        ]);

        $this->assertStringContainsString("VITE_ZAPMIZER_BRAND=zapmizer\n", $this->readAppFile('.env'));
        $this->assertStringNotContainsString('ZAPMIZER_EMBED_BRAND', $this->readAppFile('.env'));
    }

    public function testAKnownHostIsNotAsked()
    {
        Http::fake();

        $this->assertInstall(['--base-uri' => 'https://app.parlichat.com/api/']);

        Http::assertNothingSent();
    }

    public function testADifferentBaseUriFlagNeverOverwritesTheOneInDotEnv()
    {
        $this->appFile('.env', self::ENV . "ZAPMIZER_BASE_URI=https://app.zapmizer.com/api/\nZAPMIZER_PARTNER_ID=42\n");

        $this->assertInstall(['--base-uri' => 'https://app.parlichat.com/api/'], 1, ['não sobrescrevi']);

        $env = $this->readAppFile('.env');
        $this->assertStringContainsString("ZAPMIZER_BASE_URI=https://app.zapmizer.com/api/\n", $env);
        $this->assertStringNotContainsString('parlichat', $env);
        $this->assertStringContainsString("ZAPMIZER_PARTNER_ID=42\n", $env);
        $this->assertStringContainsString("VITE_ZAPMIZER_BRAND=zapmizer\n", $env);
    }

    public function testTheSameBaseUriFlagAgainIsFine()
    {
        $this->assertInstall(['--base-uri' => 'https://app.parlichat.com/api']);
        $this->assertInstall(['--base-uri' => 'https://app.parlichat.com/api/']);
    }

    public function testABaseUriWithoutApiIsRefused()
    {
        $this->assertInstall(['--base-uri' => 'https://app.parlichat.com/'], 1, ['precisa terminar em /api']);

        $this->assertStringNotContainsString('ZAPMIZER_BASE_URI', $this->readAppFile('.env'));
    }

    public function testWithoutTheFlagTheDefaultIsWrittenAndFlaggedForTheHuman()
    {
        $this->assertInstall([], 0, ['Confirme com o humano em qual deploy está a conta de parceiro']);
    }

    public function testAnExistingFrontBrandIsKept()
    {
        $this->appFile('.env', self::ENV . "VITE_ZAPMIZER_BRAND=acme\n");

        $this->assertInstall();

        $this->assertSame(1, substr_count($this->readAppFile('.env'), 'VITE_ZAPMIZER_BRAND'));
        $this->assertStringContainsString("VITE_ZAPMIZER_BRAND=acme\n", $this->readAppFile('.env'));
    }

    public function testWithoutDotEnvItWarnsAndStillWritesTheExample()
    {
        unlink($this->appFile('.env'));

        $this->assertInstall([], 0, [
            '.env não existe',
        ]);

        $this->assertFileDoesNotExist($this->appFile('.env'));
        $this->assertStringContainsString('ZAPMIZER_PARTNER_SECRET=', $this->readAppFile('.env.example'));
    }

    public function testForceOverwritesTheConfigAndTheMigrationInPlace()
    {
        $this->assertInstall([], 0);
        $migration = $this->publishedMigrations()[0];
        file_put_contents($this->appFile('config/zapmizer.php'), '<?php return [];');
        file_put_contents($migration, '<?php // edited');

        $this->assertInstall(['--force' => true], 0, [
            'config/zapmizer.php publicada',
            'sobrescrita',
        ]);

        $this->assertSame([$migration], $this->publishedMigrations());
        $this->assertFileEquals(__DIR__ . '/../../config/zapmizer.php', $this->appFile('config/zapmizer.php'));
        $this->assertFileEquals(__DIR__ . '/../../database/migrations/create_zapmizer_connections_table.php.stub', $migration);
    }

    public function testTheModelBecomesConnectable()
    {
        $this->assertInstall(['--model' => 'App\\Models\\User'], 0, [
            'app/Models/User.php: implements Connectable + use ConnectsZapmizer',
        ]);

        $model = $this->readAppFile('app/Models/User.php');

        $this->assertStringContainsString("use NotificationChannels\\Zapmizer\\Connectable as ConnectsZapmizer;\nuse NotificationChannels\\Zapmizer\\Contracts\\Connectable;\n", $model);
        $this->assertStringContainsString('class User extends Authenticatable implements Connectable', $model);
        $this->assertStringContainsString("    use HasFactory, Notifiable;\n    use ConnectsZapmizer;\n", $model);
    }

    public function testAShortModelNameIsLookedUpInAppModels()
    {
        $this->appFile('app/Models/Team.php', "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Team extends Model\n{\n}\n");

        $this->assertInstall(['--model' => 'Team'], 0, [
            'app/Models/Team.php: implements Connectable',
            'crie um resolver e aponte zapmizer.connect.resolver',
        ]);

        $this->assertStringContainsString('class Team extends Model implements Connectable', $this->readAppFile('app/Models/Team.php'));
    }

    public function testAModelItDoesNotRecognizeIsLeftAloneWithTheSnippet()
    {
        $odd = "<?php\n\nnamespace App\\Models {\n    class User extends \\Illuminate\\Foundation\\Auth\\User {}\n}\n";
        $this->appFile('app/Models/User.php', $odd);

        $this->assertInstall(['--model' => 'App\\Models\\User'], 0, [
            'não reconheci o formato de app/Models/User.php',
            'use NotificationChannels\\Zapmizer\\Connectable as ConnectsZapmizer;',
            'class User extends ... implements Connectable',
        ]);

        $this->assertSame($odd, $this->readAppFile('app/Models/User.php'));
    }

    public function testAnUnknownModelFails()
    {
        $this->assertInstall(['--model' => 'App\\Models\\Nope'], 1, [
            'não encontrado',
        ]);
    }

    public function testInboxTurnsTheRoutesOnAndPublishesTheComponents()
    {
        $this->appFile('.env', self::ENV . "ZAPMIZER_INBOX_ENABLED=false\n");

        $this->assertInstall(['--inbox' => true], 0, [
            'componentes Vue em resources/js/zapmizer',
            'Instale o front: npm install github:zapmizer/embed#v0.1.3 lucide-vue-next',
            'endZapmizerEmbeds() antes do logout',
            'Rebuilde o front',
        ]);

        $this->assertStringContainsString("ZAPMIZER_INBOX_ENABLED=true\n", $this->readAppFile('.env'));
        $this->assertStringNotContainsString('ZAPMIZER_INBOX_ENABLED=false', $this->readAppFile('.env'));
        $this->assertStringEndsWith("ZAPMIZER_PARTNER_SECRET=\nVITE_ZAPMIZER_BRAND=zapmizer\nZAPMIZER_INBOX_ENABLED=true\n", $this->readAppFile('.env.example'), 'one block, no blank line');

        foreach (['ConnectButton.vue', 'ConnectionPanel.vue', 'useZapmizerIntegration.ts', 'zapmizerRoute.ts', 'types.ts'] as $file) {
            $this->assertFileEquals(__DIR__ . '/../../stubs/inertia-vue/' . $file, $this->appFile('resources/js/zapmizer/' . $file));
        }

        foreach (['InboxScreen.vue', 'zapmizerEmbed.ts'] as $file) {
            $this->assertFileEquals(__DIR__ . '/../../stubs/inertia-vue-inbox/' . $file, $this->appFile('resources/js/zapmizer/' . $file));
        }
    }

    public function testTheComponentsGoToAFolderOfTheirOwnWhateverTheAppLayout()
    {
        // The blind test's app: ~/ alias and capitalized folders of its own.
        $this->appFile('resources/js/Components/Button.vue', '<template><button /></template>');
        $this->appFile('resources/js/Composables/useApp.ts', 'export {};');
        $this->appFile('tsconfig.json', json_encode(['compilerOptions' => ['paths' => ['~/*' => ['resources/js/*']]]]));

        $this->assertInstall(['--inbox' => true], 0, ['resources/js/zapmizer/InboxScreen.vue']);

        $this->assertFileExists($this->appFile('resources/js/zapmizer/InboxScreen.vue'));
        $this->assertDirectoryDoesNotExist($this->appFile('resources/js/components'));
        $this->assertDirectoryDoesNotExist($this->appFile('resources/js/composables'));
        $this->assertDirectoryDoesNotExist($this->appFile('resources/js/types'));
        $this->assertSame(['Button.vue'], array_values(array_diff(scandir($this->appFile('resources/js/Components')), ['.', '..'])), 'the app folders are not touched');
    }

    public function testThePackageManagerFollowsTheLockfile()
    {
        foreach (['bun.lock' => 'bun add', 'pnpm-lock.yaml' => 'pnpm add', 'yarn.lock' => 'yarn add'] as $lockfile => $command) {
            $this->appFile($lockfile, '');

            $this->assertInstall(['--inbox' => true], 0, [
            "Instale o front: {$command} github:zapmizer/embed#v0.1.3",
        ]);

            unlink($this->appFile($lockfile));
        }
    }

    public function testComponentsAreKeptUnlessForced()
    {
        $this->assertInstall(['--inbox' => true], 0);
        file_put_contents($this->appFile('resources/js/zapmizer/InboxScreen.vue'), '<!-- mine -->');

        $this->assertInstall(['--inbox' => true], 0);
        $this->assertSame('<!-- mine -->', $this->readAppFile('resources/js/zapmizer/InboxScreen.vue'));

        $this->assertInstall(['--inbox' => true, '--force' => true], 0);
        $this->assertFileEquals(__DIR__ . '/../../stubs/inertia-vue-inbox/InboxScreen.vue', $this->appFile('resources/js/zapmizer/InboxScreen.vue'));
    }

    public function testWithoutVueTheComponentsAreNotPublished()
    {
        $this->appFile('package.json', json_encode(['private' => true, 'devDependencies' => ['vite' => '^6.0']]));

        $output = $this->assertInstall(['--inbox' => true], 0, [
            'o package.json não tem vue',
            'caminho Blade de docs/instalar.md',
            'Instale só o embed: npm install github:zapmizer/embed#v0.1.3',
        ]);

        $this->assertStringNotContainsString('<InboxScreen', $output);
        $this->assertStringNotContainsString('lucide-vue-next', $output);

        $this->assertFileDoesNotExist($this->appFile('resources/js/zapmizer/InboxScreen.vue'));
        $this->assertStringContainsString('ZAPMIZER_INBOX_ENABLED=true', $this->readAppFile('.env'));
    }

    public function testMigrateCreatesTheTable()
    {
        $this->assertInstall(['--migrate' => true], 0);

        $this->assertTrue(Schema::hasTable('zapmizer_connections'));
    }

    public function testInteractiveAsksForWhatTheFlagsDidNotSay()
    {
        config()->set('auth.providers.users.model', 'App\\Models\\User');

        $this->artisan('zapmizer:install')
            ->expectsConfirmation('Ligar a caixa de entrada embutida (rotas da lib + componente Vue)?', 'yes')
            ->expectsQuestion('URL da API da conta de parceiro (ZAPMIZER_BASE_URI): https://app.zapmizer.com/api/ ou https://app.parlichat.com/api/', 'https://app.parlichat.com/api/')
            ->expectsConfirmation('Adicionar Connectable ao App\\Models\\User (o usuário logado é o dono do número)?', 'yes')
            ->expectsConfirmation('Rodar as migrations agora?', 'no')
            ->assertExitCode(0);

        $this->assertStringContainsString('implements Connectable', $this->readAppFile('app/Models/User.php'));
        $this->assertStringContainsString('ZAPMIZER_INBOX_ENABLED=true', $this->readAppFile('.env'));
        $this->assertStringContainsString('VITE_ZAPMIZER_BRAND=parli', $this->readAppFile('.env'));
        $this->assertFalse(Schema::hasTable('zapmizer_connections'));
    }

    public function testInteractiveEmptyAnswerSkipsTheModel()
    {
        config()->set('auth.providers.users.model', 'App\\Models\\User');
        $this->appFile('.env', self::ENV . "ZAPMIZER_BASE_URI=https://app.zapmizer.com/api/\n");

        $this->artisan('zapmizer:install')
            ->expectsConfirmation('Ligar a caixa de entrada embutida (rotas da lib + componente Vue)?', 'no')
            ->expectsConfirmation('Adicionar Connectable ao App\\Models\\User (o usuário logado é o dono do número)?', 'no')
            ->expectsQuestion('Outro model dono do número? (vazio para pular)', '')
            ->expectsConfirmation('Rodar as migrations agora?', 'no')
            ->assertExitCode(0);

        $this->assertSame(self::USER_MODEL, $this->readAppFile('app/Models/User.php'));
    }

    public function testFlagsSkipTheQuestions()
    {
        $this->artisan('zapmizer:install', ['--model' => 'App\\Models\\User', '--inbox' => true, '--migrate' => true, '--base-uri' => 'https://app.zapmizer.com/api/'])
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('zapmizer_connections'));
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->appRoot, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($this->appRoot))] = md5_file($file->getPathname());
        }

        ksort($files);

        return $files;
    }
}
