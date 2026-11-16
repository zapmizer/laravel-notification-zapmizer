<?php

namespace NotificationChannels\Zapmizer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Connect\PartnerClient;
use NotificationChannels\Zapmizer\Exceptions\PartnerCredentialsException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Install\ConnectableModel;
use NotificationChannels\Zapmizer\Install\EnvFile;
use NotificationChannels\Zapmizer\Support\EmbedBrand;
use NotificationChannels\Zapmizer\Support\Payload;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Class CheckCommand.
 *
 * `php artisan zapmizer:check`: what the connect and the inbox need, one
 * line each, ✓/✗/!/-. Exits non-zero when anything critical fails. The only
 * remote call is `GET /api/partner/users/{external_id}` with the partner
 * key, which reads and writes nothing: Zapmizer answers 401 for a key it
 * does not accept (before anything else), and 200 or 404 once it accepts
 * it. Without network (or with `--offline`) that check is skipped with a
 * warning. `--json` prints the same for an agent.
 *
 * Kept parseable on PHP 8.0: Artisan instantiates it on every boot.
 */
class CheckCommand extends Command
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const SKIP = 'skip';

    /**
     * The external id of the probe: valid for Zapmizer, and harmless if a
     * customer happens to have it (a 200 proves the key just the same).
     */
    public const PROBE_EXTERNAL_ID = 'zapmizer-check';

    /**
     * @var string
     */
    protected $signature = 'zapmizer:check
        {--json : Saída em JSON, para agentes}
        {--offline : Pula a checagem das credenciais na API da Zapmizer}';

    /**
     * @var string
     */
    protected $description = 'Confere a instalação da Zapmizer: credenciais, migration, model Connectable, rotas, caixa de entrada e APP_URL';

    /** @var array<int, array{id: string, status: string, message: string, hint: string|null}> */
    protected array $checks = [];

    public function handle(): int
    {
        // Artisan::call() reuses the instance: start clean every run.
        $this->checks = [];

        $this->checkAppKey();
        $this->checkConfig();
        $this->checkBaseUri();
        $credentials = $this->checkPartnerCredentials();
        $this->checkPartnerApi($credentials);
        $this->checkMigration();
        $this->checkConnectable();
        $this->checkConnectRoutes();
        $inbox = $this->inboxEnabled();
        $this->checkInboxRoutes($inbox);
        $this->checkEmbedPackage($inbox);
        $this->checkEmbedBrand($inbox);
        $this->checkAppUrl($inbox);

        $failed = count(array_filter($this->checks, fn (array $check) => $check['status'] === self::FAIL));

        $this->option('json') ? $this->printJson($failed) : $this->printLines($failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function checkAppKey(): void
    {
        filled(config('app.key'))
            ? $this->record('app_key', self::OK, 'APP_KEY definida (cifra o token e o segredo das conexões)')
            : $this->record('app_key', self::FAIL, 'APP_KEY vazia: o token das conexões é gravado cifrado', 'php artisan key:generate');
    }

    protected function checkConfig(): void
    {
        is_file(config_path('zapmizer.php'))
            ? $this->record('config', self::OK, 'config/zapmizer.php publicada')
            : $this->record('config', self::WARN, 'config/zapmizer.php não publicada: valem os padrões da lib', 'php artisan zapmizer:install');
    }

    protected function checkBaseUri(): void
    {
        $uri = config('zapmizer.base_uri');

        is_string($uri) && Payload::url(rtrim($uri, '/') . '/connect/sessions', underscoreInHost: true) !== null
            ? $this->record('base_uri', self::OK, "ZAPMIZER_BASE_URI {$uri}")
            : $this->record('base_uri', self::FAIL, 'ZAPMIZER_BASE_URI não é uma URL http(s) absoluta', 'ZAPMIZER_BASE_URI=https://app.zapmizer.com/api/');
    }

    protected function checkPartnerCredentials(): bool
    {
        $id = config('zapmizer.partner.id');
        $secret = config('zapmizer.partner.secret');
        $missing = array_keys(array_filter(['ZAPMIZER_PARTNER_ID' => $id, 'ZAPMIZER_PARTNER_SECRET' => $secret], fn ($value) => blank($value)));

        if ($missing !== []) {
            $this->record('partner_credentials', self::FAIL, implode(' e ', $missing) . ' vazio no .env', 'As credenciais de parceiro são emitidas pela Zapmizer para a aplicação: peça a quem cuida da conta. Não invente valores.');

            return false;
        }

        // Zapmizer reads the key as `{id}|{secret}` and refuses an id that is
        // not all digits before looking it up.
        if (!ctype_digit((string) $id)) {
            $this->record('partner_credentials', self::FAIL, 'ZAPMIZER_PARTNER_ID precisa ser só dígitos (é o id numérico do parceiro na Zapmizer)', 'Confira o valor com quem emitiu as credenciais.');

            return false;
        }

        $this->record('partner_credentials', self::OK, 'ZAPMIZER_PARTNER_ID e ZAPMIZER_PARTNER_SECRET preenchidos');

        return true;
    }

    protected function checkPartnerApi(bool $credentials): void
    {
        if (!$credentials) {
            $this->record('partner_api', self::SKIP, 'credenciais na API: pulado, sem credenciais');

            return;
        }

        if ($this->option('offline')) {
            $this->record('partner_api', self::SKIP, 'credenciais na API: pulado (--offline)');

            return;
        }

        try {
            app(PartnerClient::class)->subscription(self::PROBE_EXTERNAL_ID);

            $this->record('partner_api', self::OK, 'a Zapmizer aceitou as credenciais de parceiro');
        } catch (PartnerCredentialsException) {
            $this->record('partner_api', self::FAIL, 'a Zapmizer recusou as credenciais de parceiro (401)', 'Confira ZAPMIZER_PARTNER_ID, ZAPMIZER_PARTNER_SECRET e ZAPMIZER_BASE_URI (a chave é de um ambiente só).');
        } catch (ZapmizerRateLimitedException) {
            $this->record('partner_api', self::WARN, 'a Zapmizer respondeu 429: não deu para conferir as credenciais agora', 'Rode de novo em um minuto.');
        } catch (ZapmizerUnavailableException) {
            $this->record('partner_api', self::WARN, 'sem resposta da Zapmizer (sem rede ou 5xx): checagem remota pulada', 'Rode de novo com rede para conferir as credenciais.');
        } catch (ZapmizerApiException $exception) {
            $this->record('partner_api', self::WARN, "resposta inesperada da Zapmizer ({$exception->status()}) ao conferir as credenciais");
        } catch (ZapmizerConnectException $exception) {
            $this->record('partner_api', self::WARN, 'não deu para conferir as credenciais: ' . $exception->getMessage());
        }
    }

    protected function checkMigration(): void
    {
        $model = config('zapmizer.models.connection');

        try {
            $table = (new $model())->getTable();
            $exists = Schema::connection((new $model())->getConnectionName())->hasTable($table);
        } catch (Throwable $exception) {
            $this->record('migration', self::FAIL, 'não deu para ler o banco: ' . $exception->getMessage(), 'Confira a conexão do banco e rode php artisan migrate.');

            return;
        }

        if ($exists) {
            $this->record('migration', self::OK, "tabela {$table} criada");

            return;
        }

        $published = glob(database_path('migrations/*_create_zapmizer_connections_table.php')) ?: [];

        $this->record('migration', self::FAIL, "tabela {$table} não existe", $published === [] ? 'php artisan zapmizer:install e depois php artisan migrate' : 'php artisan migrate');
    }

    protected function checkConnectable(): void
    {
        $resolver = ltrim((string) config('zapmizer.connect.resolver'), '\\');
        $found = $this->connectableModels();
        $list = $found === [] ? '' : ' (Connectable no app: ' . implode(', ', $found) . ')';

        if ($resolver === 'NotificationChannels\Zapmizer\Connect\ResolvesAuthenticatedUser') {
            $user = ltrim((string) config('auth.providers.users.model'), '\\');

            if ($user !== '' && $this->implementsConnectable($user)) {
                $this->record('connectable', self::OK, "{$user} é Connectable (o connect usa o usuário logado)");

                return;
            }

            $this->record('connectable', self::FAIL, "o connect usa o usuário logado, mas {$user} não é Connectable{$list}", $found === []
                ? 'php artisan zapmizer:install --model=' . ($user ?: 'App\\Models\\User')
                : 'Aponte zapmizer.connect.resolver para um resolver que devolva o seu model Connectable (docs/connect.md, seção 4).');

            return;
        }

        if (!class_exists($resolver) || !is_subclass_of($resolver, 'NotificationChannels\Zapmizer\Contracts\ResolvesConnectable')) {
            $this->record('connectable', self::FAIL, "zapmizer.connect.resolver ({$resolver}) não existe ou não implementa ResolvesConnectable");

            return;
        }

        $found === []
            ? $this->record('connectable', self::WARN, "resolver {$resolver}; nenhum model Connectable achado em app/", 'Confira se o model que o resolver devolve implementa Connectable.')
            : $this->record('connectable', self::OK, "resolver {$resolver}{$list}");
    }

    protected function checkConnectRoutes(): void
    {
        $missing = array_values(array_filter(
            ['zapmizer.connect.show', 'zapmizer.connect.start', 'zapmizer.connect.callback', 'zapmizer.connect.destroy', 'zapmizer.webhook'],
            fn (string $name) => !Route::has($name),
        ));

        $missing === []
            ? $this->record('connect_routes', self::OK, 'rotas do connect registradas')
            : $this->record('connect_routes', self::FAIL, 'rotas ausentes: ' . implode(', ', $missing), filter_var(config('zapmizer.routes.enabled', true), FILTER_VALIDATE_BOOLEAN)
                ? 'Rode php artisan route:clear (rotas em cache).'
                : 'ZAPMIZER_ROUTES_ENABLED=false: monte as rotas da lib no app ou ligue de novo.');
    }

    protected function checkInboxRoutes(bool $inbox): void
    {
        if (!$inbox) {
            $this->record('inbox_routes', self::SKIP, 'caixa de entrada desligada (ZAPMIZER_INBOX_ENABLED)');

            return;
        }

        $missing = array_values(array_filter(['zapmizer.inbox.session', 'zapmizer.inbox.keepalive'], fn (string $name) => !Route::has($name)));

        $missing === []
            ? $this->record('inbox_routes', self::OK, 'rotas da caixa de entrada registradas')
            : $this->record('inbox_routes', self::FAIL, 'rotas ausentes: ' . implode(', ', $missing), 'Rode php artisan route:clear e confira ZAPMIZER_ROUTES_ENABLED.');
    }

    protected function checkEmbedPackage(bool $inbox): void
    {
        if (!$inbox) {
            $this->record('embed_package', self::SKIP, '@zapmizer/embed: não precisa sem a caixa de entrada');

            return;
        }

        $path = base_path('package.json');
        $package = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $version = is_array($package) ? ($package['dependencies'][InstallCommand::EMBED_PACKAGE] ?? $package['devDependencies'][InstallCommand::EMBED_PACKAGE] ?? null) : null;

        if (!is_string($version)) {
            $this->record('embed_package', self::FAIL, InstallCommand::EMBED_PACKAGE . ' não está no package.json', 'Instale o front: npm install ' . InstallCommand::EMBED_VERSION . ' (ou bun/pnpm/yarn add, conforme o lockfile).');

            return;
        }

        preg_match('/#(v\d+\.\d+\.\d+)$/', $version, $tag) === 1
            ? $this->record('embed_package', self::OK, InstallCommand::EMBED_PACKAGE . " {$tag[1]} no package.json")
            : $this->record('embed_package', self::WARN, InstallCommand::EMBED_PACKAGE . " sem tag fixa ({$version})", 'Fixe a tag: ' . InstallCommand::EMBED_VERSION);
    }

    /**
     * The front's brand (VITE_ZAPMIZER_BRAND, baked in at build time) must be
     * the one of the deploy the base_uri points at: @zapmizer/embed drops
     * every event whose `source` is not `{brand}-embed`, so a mismatch is an
     * inbox that never gets its `ready`.
     */
    protected function checkEmbedBrand(bool $inbox): void
    {
        if (!$inbox) {
            $this->record('embed_brand', self::SKIP, 'marca do embed: não precisa sem a caixa de entrada');

            return;
        }

        $expected = EmbedBrand::resolve();
        $front = $this->frontBrand();
        $hint = "VITE_ZAPMIZER_BRAND={$expected} no .env (é a marca do deploy da ZAPMIZER_BASE_URI; ZAPMIZER_EMBED_BRAND sobrescreve) e rebuilde o front.";

        if ($front === null || $front === '') {
            $this->record('embed_brand', self::FAIL, 'VITE_ZAPMIZER_BRAND ausente: o front não sabe a marca do embed', $hint);
        } elseif ($front !== $expected) {
            $this->record('embed_brand', self::FAIL, "VITE_ZAPMIZER_BRAND={$front}, mas a marca do embed é {$expected}: a caixa não receberia o ready", $hint);
        } else {
            $this->record('embed_brand', self::OK, "marca do embed {$expected} no front e na config");
        }
    }

    /**
     * From the app's .env; without the file, from the environment.
     */
    protected function frontBrand(): ?string
    {
        $file = new EnvFile($this->laravel->environmentFilePath());

        if ($file->exists()) {
            return $file->value('VITE_ZAPMIZER_BRAND');
        }

        $value = $_ENV['VITE_ZAPMIZER_BRAND'] ?? $_SERVER['VITE_ZAPMIZER_BRAND'] ?? getenv('VITE_ZAPMIZER_BRAND');

        return is_string($value) ? trim($value) : null;
    }

    /**
     * APP_URL is what route() puts in the redirect_uri and the webhook_url
     * the connect sends; Zapmizer wants https in production and a host on
     * the partner's allowlist.
     */
    protected function checkAppUrl(bool $inbox): void
    {
        $url = (string) config('app.url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            $this->record('app_url', self::FAIL, "APP_URL não é uma URL absoluta ({$url})", 'APP_URL=https://seu-app.com: é a base do redirect_uri e do webhook_url do connect.');

            return;
        }

        $local = $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($scheme !== 'https' || $local) {
            $this->record('app_url', self::WARN, "APP_URL {$url}: a Zapmizer de produção só aceita redirect_uri/webhook_url https, em host público liberado para o parceiro", 'Para testar o connect em dev, use uma URL pública https (túnel) liberada na conta de parceiro.');
        } else {
            $this->record('app_url', self::OK, "APP_URL {$url} (o host {$host} precisa estar liberado na conta de parceiro)");
        }

        $origin = config('zapmizer.inbox.parent_origin');

        if ($inbox && is_string($origin) && trim($origin) !== '' && preg_match('#^https?://[^/?\#]+/?$#i', trim($origin)) !== 1) {
            $this->record('parent_origin', self::FAIL, "ZAPMIZER_INBOX_PARENT_ORIGIN ({$origin}) não é uma origem", 'Só esquema, host e porta: https://seu-app.com');
        }
    }

    protected function inboxEnabled(): bool
    {
        return filter_var(config('zapmizer.inbox.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    protected function implementsConnectable(string $class): bool
    {
        try {
            return class_exists($class) && is_subclass_of($class, ConnectableModel::INTERFACE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The models under app/Models (and app/) whose file declares the
     * interface, read as text: nothing is loaded.
     *
     * @return array<int, string>
     */
    protected function connectableModels(): array
    {
        $found = [];

        foreach (array_merge(glob(app_path('Models/*.php')) ?: [], glob(app_path('*.php')) ?: []) as $file) {
            $code = (string) file_get_contents($file);

            if (!str_contains($code, 'Connectable')) {
                continue;
            }

            $short = basename($file, '.php');

            if ((new ConnectableModel($code, $short))->implementsInterface()) {
                $found[] = $short;
            }
        }

        return $found;
    }

    protected function record(string $id, string $status, string $message, ?string $hint = null): void
    {
        $this->checks[] = ['id' => $id, 'status' => $status, 'message' => $message, 'hint' => $hint];
    }

    protected function printJson(int $failed): void
    {
        $this->line((string) json_encode([
            'ok' => $failed === 0,
            'checks' => $this->checks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function printLines(int $failed): void
    {
        $marks = [
            self::OK => '<info>✓</info>',
            self::FAIL => '<fg=red>✗</>',
            self::WARN => '<comment>!</comment>',
            self::SKIP => '<fg=gray>-</>',
        ];

        $this->line('');
        $this->line('  <options=bold>Zapmizer</> · diagnóstico');
        $this->line('');

        foreach ($this->checks as $check) {
            $this->line("  {$marks[$check['status']]} " . OutputFormatter::escape($check['message']));

            if ($check['hint'] !== null && $check['status'] !== self::OK) {
                $this->line('      <fg=gray>→ ' . OutputFormatter::escape($check['hint']) . '</>');
            }
        }

        $this->line('');
        $this->line($failed === 0
            ? '  <info>Tudo certo para o connect' . ($this->inboxEnabled() ? ' e a caixa de entrada' : '') . '.</info>'
            : "  <fg=red>{$failed} " . ($failed === 1 ? 'problema crítico' : 'problemas críticos') . '.</>');
        $this->line('');
    }
}
