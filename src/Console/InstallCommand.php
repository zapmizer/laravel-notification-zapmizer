<?php

namespace NotificationChannels\Zapmizer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Install\ConnectableModel;
use NotificationChannels\Zapmizer\Install\EnvFile;
use NotificationChannels\Zapmizer\Support\BaseUri;
use NotificationChannels\Zapmizer\Support\EmbedBrand;
use NotificationChannels\Zapmizer\Support\RouteGroups;
use ReflectionClass;
use Throwable;

/**
 * Class InstallCommand.
 *
 * `php artisan zapmizer:install`: the connect (a WhatsApp number per model)
 * and, with `--inbox`, the embedded inbox, switching on the route groups
 * the app asked for (`--model`: connect; `--webhook`: webhook; `--inbox`:
 * inbox and connect), in the mould of `reverb:install`
 * and `horizon:install`. Idempotent — run it again and it only does what is
 * missing — and safe under `--no-interaction`: without a flag nothing beyond
 * the config, the migration and the `.env` keys is touched. It never writes
 * a credential: the partner keys go in empty, for a human to fill.
 *
 * Kept parseable on PHP 8.0: Artisan instantiates it on every boot.
 */
class InstallCommand extends Command
{
    public const EMBED_PACKAGE = '@zapmizer/embed';

    public const EMBED_VERSION = 'github:zapmizer/embed#v0.1.3';

    /**
     * @var string
     */
    protected $signature = 'zapmizer:install
        {--model= : O model dono do número de WhatsApp (ex.: App\\Models\\User ou Team)}
        {--base-uri= : A URL da API da conta de parceiro (ZAPMIZER_BASE_URI), ex.: https://app.parlichat.com/api/}
        {--inbox : Liga a caixa de entrada embutida (e o connect) e publica os componentes Vue}
        {--webhook : Liga o webhook da lib, a rota pública assinada que recebe as mensagens}
        {--migrate : Roda as migrations no fim}
        {--force : Sobrescreve a config, a migration e os componentes já publicados}';

    /**
     * @var string
     */
    protected $description = 'Instala o connect da Zapmizer (um número por model) e, com --inbox, a caixa de entrada embutida';

    /** @var array<int, string> */
    protected array $nextSteps = [];

    protected bool $failed = false;

    protected bool $componentsPublished = false;

    /** Where the base URI came from: `flag`, `env` or `default`. */
    protected string $baseUriSource = 'default';

    protected string $baseUri = BaseUri::DEFAULT;

    /** The embed's brand written to `VITE_ZAPMIZER_BRAND`. */
    protected string $brand = EmbedBrand::ZAPMIZER;

    /** Where the brand came from: `config`, `host`, `manifest` or `unknown`. */
    protected string $brandSource = 'host';

    public function handle(): int
    {
        // Artisan::call() reuses the instance: start clean every run.
        $this->nextSteps = [];
        $this->failed = false;
        $this->componentsPublished = false;
        $this->brandSource = 'host';

        $this->line('');
        $this->line('  <options=bold>Zapmizer</> · instalação');
        $this->line('');

        $this->publishConfig();
        $this->publishMigration();
        $inbox = $this->wantsInbox();
        $webhook = $this->wantsWebhook();
        $baseUri = $this->resolveBaseUri();
        $model = $this->connectModel();

        // The inbox opens for the connection the connect made: it needs it.
        $connect = $model !== null || $inbox;

        if ($baseUri) {
            $this->writeEnvironment($this->routeGroups($connect, $webhook, $inbox));
        }

        if ($inbox) {
            $this->publishComponents();
        }

        $migrated = $this->migrate();

        $this->printNextSteps($inbox, $model, $migrated, $connect, $webhook);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        $target = config_path('zapmizer.php');

        if (is_file($target) && !$this->option('force')) {
            $this->skipped('config/zapmizer.php já existe (--force sobrescreve)');

            return;
        }

        $this->ensureDirectory(dirname($target));
        copy($this->packagePath('config/zapmizer.php'), $target);
        $this->done('config/zapmizer.php publicada');
    }

    /**
     * The connect table. A migration already published (any timestamp) is
     * kept, or overwritten in place with `--force` — never a second copy.
     */
    protected function publishMigration(): void
    {
        $stub = $this->packagePath('database/migrations/create_zapmizer_connections_table.php.stub');
        $existing = glob(database_path('migrations/*_create_zapmizer_connections_table.php')) ?: [];

        if ($existing !== [] && !$this->option('force')) {
            $this->skipped('migration da zapmizer_connections já publicada');

            return;
        }

        $target = $existing[0] ?? database_path('migrations/' . date('Y_m_d_His') . '_create_zapmizer_connections_table.php');

        $this->ensureDirectory(dirname($target));
        copy($stub, $target);
        $this->done('migration ' . basename($target) . ($existing === [] ? ' publicada' : ' sobrescrita'));
    }

    protected function wantsInbox(): bool
    {
        if ($this->option('inbox')) {
            return true;
        }

        if (!$this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Ligar a caixa de entrada embutida (rotas da lib + componente Vue)?', false);
    }

    protected function wantsWebhook(): bool
    {
        if ($this->option('webhook')) {
            return true;
        }

        if (!$this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Ligar o webhook da lib (rota pública assinada, para receber mensagens e eventos)?', false);
    }

    /**
     * The route switches the app asked for, each `true`.
     *
     * @return array<string, string>
     */
    protected function routeGroups(bool $connect, bool $webhook, bool $inbox): array
    {
        $groups = array_filter([
            RouteGroups::CONNECT => $connect,
            RouteGroups::WEBHOOK => $webhook,
            RouteGroups::INBOX => $inbox,
        ]);

        $variables = [];

        foreach (array_keys($groups) as $group) {
            $variables[RouteGroups::env($group)] = 'true';
        }

        return $variables;
    }

    /**
     * The keys the connect reads, added only where missing, in `.env` and
     * `.env.example`. The partner keys go in empty: they are issued by
     * Zapmizer, never made up here. The route switches asked for are
     * switched on even when the file has them with another value. The
     * `.env.example` is committed: it never gets a host outside the known
     * deploys (a local server, a staging), but the public deploy of the
     * same brand, whose host already says the brand (so no
     * `ZAPMIZER_EMBED_BRAND` there).
     *
     * @param array<string, string> $routes
     */
    protected function writeEnvironment(array $routes): void
    {
        $brand = $this->resolveBrand();
        $files = [
            '.env' => [$this->laravel->environmentFilePath(), $this->baseUri],
            '.env.example' => [base_path('.env.example'), BaseUri::shareable($this->baseUri, $brand)],
        ];

        foreach ($files as $label => [$path, $baseUri]) {
            $file = new EnvFile($path);

            if (!$file->exists()) {
                if ($label === '.env') {
                    $this->warning('.env não existe: as chaves foram só para o .env.example. Copie-o para .env.');
                }

                continue;
            }

            // A key left empty is filled; one with a value is never touched.
            if ($file->has('ZAPMIZER_BASE_URI') && $file->value('ZAPMIZER_BASE_URI') === '') {
                $file->set('ZAPMIZER_BASE_URI', $baseUri);
            }

            // A flag is an explicit ask: a route switch already there with
            // another value is switched on.
            $switched = [];

            foreach ($routes as $key => $value) {
                if ($file->has($key) && $file->value($key) !== $value && $file->set($key, $value)) {
                    $switched[] = "{$key}={$value}";
                }
            }

            $added = array_merge($file->addMissing($this->variables($baseUri, $brand) + $routes), $switched);

            $added === []
                ? $this->skipped("{$label} já tem as chaves da Zapmizer")
                : $this->done("{$label}: " . implode(', ', $added));
        }
    }

    /**
     * The keys for a file whose `ZAPMIZER_BASE_URI` is `$baseUri`.
     *
     * @return array<string, string>
     */
    protected function variables(string $baseUri, string $brand): array
    {
        $variables = [
            'ZAPMIZER_BASE_URI' => $baseUri,
            'ZAPMIZER_API_VERSION' => '2025-06-27',
            'ZAPMIZER_PARTNER_ID' => '',
            'ZAPMIZER_PARTNER_SECRET' => '',
            // The embed's brand for the front (read at build time): the one
            // of the deploy the base_uri points at.
            'VITE_ZAPMIZER_BRAND' => $brand,
        ];

        // A brand read from a deploy outside the known hosts also goes to
        // the backend (the Blade path reads EmbedBrand::resolve()), which
        // would otherwise derive `zapmizer` from the host. A known host
        // (the `.env.example` always has one) already says it.
        if ($this->brandSource === 'manifest' && $brand !== EmbedBrand::fromBaseUri($baseUri)) {
            $variables['ZAPMIZER_EMBED_BRAND'] = $brand;
        }

        return $variables;
    }

    /**
     * The brand of the deploy: `zapmizer.inbox.brand` when set, else the one
     * of a known host (app.zapmizer.com, parlichat.com), else the one the
     * deploy publishes in `/brand/manifest.json`. A deploy that does not
     * tell keeps the host default and goes to the human in the next steps.
     */
    protected function resolveBrand(): string
    {
        $configured = EmbedBrand::configured();
        $known = EmbedBrand::knownDeploy($this->baseUri);
        $discovered = $configured === null && $known === null ? EmbedBrand::discover($this->baseUri) : null;

        [$this->brand, $this->brandSource] = match (true) {
            $configured !== null => [$configured, 'config'],
            $known !== null => [$known, 'host'],
            $discovered !== null => [$discovered, 'manifest'],
            default => [EmbedBrand::fromBaseUri($this->baseUri), 'unknown'],
        };

        if ($this->brandSource === 'manifest') {
            $this->done("marca do deploy: {$this->brand} (lida de " . EmbedBrand::manifestUrl($this->baseUri) . ')');
        } elseif ($this->brandSource === 'unknown') {
            $this->warning("não consegui ler a marca do deploy de {$this->baseUri} (" . EmbedBrand::manifestUrl($this->baseUri) . "): usei {$this->brand}. Confirme com o humano.");
        }

        return $this->brand;
    }

    /**
     * The API of the partner account: `--base-uri`, else the `.env` value,
     * else (interactive) asked, else the default. A value already in `.env`
     * is never overwritten: a different `--base-uri` is refused.
     */
    protected function resolveBaseUri(): bool
    {
        $env = new EnvFile($this->laravel->environmentFilePath());
        $existing = $env->exists() ? $env->value('ZAPMIZER_BASE_URI') : null;
        $flag = $this->option('base-uri');

        if (is_string($flag) && trim($flag) !== '') {
            $flag = trim($flag);

            if (($problem = BaseUri::problem($flag)) !== null) {
                $this->failure("--base-uri {$flag} {$problem}.");
                $this->failed = true;

                return false;
            }

            if ($existing !== null && $existing !== '' && !BaseUri::same($existing, $flag)) {
                $this->failure("o .env já tem ZAPMIZER_BASE_URI={$existing}: não sobrescrevi com {$flag}. Troque à mão se for o caso.");
                $this->failed = true;
                [$this->baseUri, $this->baseUriSource] = [$existing, 'env'];

                return true;
            }

            [$this->baseUri, $this->baseUriSource] = [$flag, 'flag'];

            return true;
        }

        if ($existing !== null && $existing !== '') {
            [$this->baseUri, $this->baseUriSource] = [$existing, 'env'];

            return true;
        }

        if ($this->input->isInteractive()) {
            $answer = trim((string) $this->ask('URL da API da conta de parceiro (ZAPMIZER_BASE_URI): https://app.zapmizer.com/api/ ou https://app.parlichat.com/api/', BaseUri::DEFAULT));

            if (($problem = BaseUri::problem($answer)) !== null) {
                $this->failure("{$answer} {$problem}.");
                $this->failed = true;

                return false;
            }

            [$this->baseUri, $this->baseUriSource] = [$answer, 'flag'];

            return true;
        }

        [$this->baseUri, $this->baseUriSource] = [BaseUri::DEFAULT, 'default'];

        return true;
    }

    /**
     * @return string|null the model made Connectable (or already it)
     */
    protected function connectModel(): ?string
    {
        $model = $this->option('model');

        if (($model === null || $model === '') && $this->input->isInteractive()) {
            $user = $this->userModel();

            $model = $user !== null && $this->confirm("Adicionar Connectable ao {$user} (o usuário logado é o dono do número)?", true)
                ? $user
                : $this->ask('Outro model dono do número? (vazio para pular)');
        }

        if (!is_string($model) || trim($model) === '') {
            $this->skipped('nenhum model editado (--model=App\\Models\\User para escolher)');

            return null;
        }

        [$class, $path] = $this->locateModel(trim($model));

        if ($path === null) {
            $this->failure("model {$model} não encontrado. Passe o nome completo da classe (ex.: --model=App\\Models\\User).");
            $this->failed = true;

            return null;
        }

        $short = substr($class, (int) strrpos('\\' . $class, '\\'));
        $relative = $this->relative($path);
        $editor = new ConnectableModel((string) file_get_contents($path), $short);

        if ($this->alreadyConnectable($class) || ($editor->implementsInterface() && $editor->usesTrait())) {
            $this->skipped("{$class} já é Connectable");

            return $class;
        }

        $edited = $editor->withConnectable();

        if ($edited === null) {
            $this->warning("não reconheci o formato de {$relative}: acrescente à mão o trecho abaixo e rode o comando de novo.");
            $this->line('');

            foreach (explode("\n", ConnectableModel::snippet($short)) as $line) {
                $this->line('    ' . $line);
            }

            $this->line('');
            $this->nextSteps[] = "Torne {$class} Connectable (trecho acima) e rode php artisan zapmizer:install de novo.";

            return $class;
        }

        file_put_contents($path, $edited);
        $this->done("{$relative}: implements Connectable + use ConnectsZapmizer");

        return $class;
    }

    /**
     * The Inertia + Vue components (connect button, panel, inbox screen), the
     * same set as `vendor:publish --tag=zapmizer-wizard --tag=zapmizer-inbox`.
     */
    protected function publishComponents(): void
    {
        $package = $this->packageJson();

        if ($package !== null && !isset($this->dependencies($package)['vue'])) {
            $this->warning('o package.json não tem vue: os componentes não foram publicados. Sem Vue, veja "Sem Inertia/Vue" em docs/instalar.md.');

            return;
        }

        $copied = 0;
        $kept = 0;

        foreach (['stubs/inertia-vue', 'stubs/inertia-vue-inbox'] as $directory) {
            $source = $this->packagePath($directory);

            foreach ($this->files($source) as $file) {
                $target = resource_path('js/zapmizer/' . substr($file, strlen($source) + 1));

                if (is_file($target) && !$this->option('force')) {
                    $kept++;

                    continue;
                }

                $this->ensureDirectory(dirname($target));
                copy($file, $target);
                $copied++;
            }
        }

        $this->componentsPublished = true;

        $copied > 0
            ? $this->done("componentes Vue em resources/js/zapmizer ({$copied} copiados" . ($kept > 0 ? ", {$kept} mantidos" : '') . ')')
            : $this->skipped('componentes Vue já publicados (--force sobrescreve)');
    }

    protected function migrate(): bool
    {
        $migrate = $this->option('migrate');

        if (!$migrate && $this->input->isInteractive()) {
            $migrate = $this->confirm('Rodar as migrations agora?', true);
        }

        if (!$migrate) {
            return false;
        }

        $status = $this->call('migrate');

        if ($status !== 0) {
            $this->failed = true;

            return false;
        }

        return true;
    }

    protected function printNextSteps(bool $inbox, ?string $model, bool $migrated, bool $connect, bool $webhook): void
    {
        $steps = [];

        if (blank(config('zapmizer.partner.id')) || blank(config('zapmizer.partner.secret'))) {
            $steps[] = 'Peça ao responsável pela conta Zapmizer as credenciais de parceiro e preencha ZAPMIZER_PARTNER_ID e ZAPMIZER_PARTNER_SECRET no .env (são emitidas pela Zapmizer; não invente valores).';
        }

        if ($this->baseUriSource === 'default') {
            $steps[] = 'Confirme com o humano em qual deploy está a conta de parceiro: gravei ZAPMIZER_BASE_URI=' . BaseUri::DEFAULT . ' (padrão). Se for a Parli, troque por https://app.parlichat.com/api/ (e VITE_ZAPMIZER_BRAND=parli) ou rode de novo com --base-uri num .env sem a chave.';
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'o host do APP_URL';
        $webhookOn = $webhook || RouteGroups::switchedOn(RouteGroups::WEBHOOK);
        $steps[] = "Peça à Zapmizer para liberar o host {$host} na conta de parceiro: é ele que vai no redirect_uri" . ($webhookOn ? ', no webhook_url' : '') . ($inbox ? ' e no parent_origin da caixa' : '') . ' (https em produção). Confira se o APP_URL é a URL pública do app.';

        if ($connect && !$webhookOn) {
            $steps[] = 'O webhook da lib ficou desligado: as conexões enviam, mas não recebem mensagens nem eventos. Se o app precisa receber, rode de novo com --webhook (ZAPMIZER_ROUTES_WEBHOOK=true) antes de conectar os números.';
        }

        if ($model !== null && $model !== $this->userModel() && $this->usesDefaultResolver()) {
            $steps[] = "O connect resolve o usuário logado. Para conectar {$model}, crie um resolver e aponte zapmizer.connect.resolver para ele (docs/connect.md, seção 4).";
        } elseif ($model === null && !$this->userIsConnectable()) {
            $steps[] = 'Torne Connectable o model dono do número e ligue as rotas do connect: php artisan zapmizer:install --model=App\\Models\\User (ou o seu model).';
        }

        $steps = array_merge($steps, $this->nextSteps);

        if (!$migrated && !$this->tableExists()) {
            $steps[] = 'Rode as migrations: php artisan migrate';
        }

        $brand = $this->brand;

        if ($this->brandSource === 'unknown') {
            $steps[] = 'Confirme com o humano a marca do deploy de ' . $this->baseUri . ": o host não é um dos deploys conhecidos (app.zapmizer.com, *.parlichat.com) e o deploy não disse a sua em /brand/manifest.json. Gravei VITE_ZAPMIZER_BRAND={$brand}; se for a Parli, troque por parli, defina ZAPMIZER_EMBED_BRAND=parli e rebuilde o front (com a marca errada a caixa termina em ready_timeout).";
        }

        if ($inbox && $this->componentsPublished) {
            $steps[] = 'Instale o front: ' . $this->frontendInstall(true);
            $steps[] = 'Monte <InboxScreen /> (resources/js/zapmizer/InboxScreen.vue; o person vem do servidor, não passe) numa página autenticada, chame listenToZapmizerLogout() no boot do app e endZapmizerEmbeds() antes do logout (resources/js/zapmizer/zapmizerEmbed.ts). Importe de resources/js/zapmizer pelo alias que o app já usa para resources/js (ou por caminho relativo); os arquivos de lá se importam entre si por caminho relativo, sem depender de alias.';
            $steps[] = "Rebuilde o front (npm run build, ou o build do gerenciador do app) depois de instalar: o VITE_ZAPMIZER_BRAND={$brand} do .env entra no bundle, e precisa ser a marca do deploy da ZAPMIZER_BASE_URI.";
        } elseif ($inbox) {
            $steps[] = 'Sem Vue, os componentes não foram publicados: monte o connect e a caixa pelo caminho Blade de docs/instalar.md ("Sem Inertia/Vue"). Instale só o embed: ' . $this->frontendInstall(false);
            $steps[] = "No Blade, passe brand: '{$brand}' ao @zapmizer/embed (a marca do deploy da ZAPMIZER_BASE_URI) e rebuilde o front.";
        }

        if (method_exists($this->laravel, 'configurationIsCached') && $this->laravel->configurationIsCached()) {
            $steps[] = 'A config está em cache: rode php artisan config:clear (ou config:cache) para o .env novo valer.';
        }

        $steps[] = 'Confira tudo com php artisan zapmizer:check (--json para agentes). O roteiro completo está em docs/instalar.md.';

        $this->line('');
        $this->line('  <options=bold>Próximos passos</>');

        foreach ($steps as $index => $step) {
            $this->line('  ' . ($index + 1) . '. ' . $step);
        }

        $this->line('');
    }

    /**
     * The package manager the lockfile names, the way Breeze picks it.
     */
    protected function frontendInstall(bool $components): string
    {
        $packages = [self::EMBED_VERSION];
        $package = $this->packageJson() ?? [];
        $dependencies = $this->dependencies($package);

        foreach ($components ? ['axios', 'lucide-vue-next'] : [] as $dependency) {
            if (!isset($dependencies[$dependency])) {
                $packages[] = $dependency;
            }
        }

        $list = implode(' ', $packages);

        if (is_file(base_path('pnpm-lock.yaml'))) {
            return "pnpm add {$list}";
        }

        if (is_file(base_path('yarn.lock'))) {
            return "yarn add {$list}";
        }

        if (is_file(base_path('bun.lock')) || is_file(base_path('bun.lockb'))) {
            return "bun add {$list}";
        }

        return "npm install {$list}";
    }

    /**
     * @return array{0: string, 1: string|null} class, file
     */
    protected function locateModel(string $model): array
    {
        $model = ltrim($model, '\\');
        $namespace = $this->appNamespace();
        $candidates = str_contains($model, '\\') ? [$model] : [$namespace . 'Models\\' . $model, $namespace . $model];

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                $file = (new ReflectionClass($class))->getFileName();

                return [$class, is_string($file) ? $file : null];
            }

            if ($namespace !== '' && str_starts_with($class, $namespace)) {
                $path = app_path(str_replace('\\', '/', substr($class, strlen($namespace))) . '.php');

                if (is_file($path)) {
                    return [$class, $path];
                }
            }
        }

        return [$candidates[0], null];
    }

    protected function alreadyConnectable(string $class): bool
    {
        return class_exists($class, false)
            && is_subclass_of($class, ConnectableModel::INTERFACE)
            && in_array(ConnectableModel::TRAIT, class_uses_recursive($class), true);
    }

    protected function userModel(): ?string
    {
        $model = config('auth.providers.users.model');

        return is_string($model) ? ltrim($model, '\\') : null;
    }

    protected function userIsConnectable(): bool
    {
        $model = $this->userModel();

        try {
            return $model !== null && class_exists($model) && is_subclass_of($model, ConnectableModel::INTERFACE);
        } catch (Throwable) {
            return false;
        }
    }

    protected function usesDefaultResolver(): bool
    {
        return ltrim((string) config('zapmizer.connect.resolver'), '\\') === 'NotificationChannels\Zapmizer\Connect\ResolvesAuthenticatedUser';
    }

    protected function tableExists(): bool
    {
        try {
            return Schema::hasTable('zapmizer_connections');
        } catch (Throwable) {
            return false;
        }
    }

    protected function appNamespace(): string
    {
        try {
            return $this->laravel->getNamespace();
        } catch (Throwable) {
            return 'App\\';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function packageJson(): ?array
    {
        $path = base_path('package.json');

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, string>
     */
    protected function dependencies(array $package): array
    {
        $dependencies = [];

        foreach (['dependencies', 'devDependencies'] as $key) {
            if (isset($package[$key]) && is_array($package[$key])) {
                $dependencies += $package[$key];
            }
        }

        return $dependencies;
    }

    /**
     * @return array<int, string>
     */
    protected function files(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    protected function packagePath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }

    protected function relative(string $path): string
    {
        $base = rtrim(base_path(), '/') . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    protected function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    protected function done(string $message): void
    {
        $this->line("  <info>✓</info> {$message}");
    }

    protected function skipped(string $message): void
    {
        $this->line("  <fg=gray>·</> {$message}");
    }

    protected function warning(string $message): void
    {
        if (isset($this->components)) {
            $this->components->warn($message);

            return;
        }

        $this->warn("  ! {$message}");
    }

    protected function failure(string $message): void
    {
        if (isset($this->components)) {
            $this->components->error($message);

            return;
        }

        $this->error("  ✗ {$message}");
    }
}
