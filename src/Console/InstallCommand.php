<?php

namespace NotificationChannels\Zapmizer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Zapmizer\Install\ConnectableModel;
use NotificationChannels\Zapmizer\Install\EnvFile;
use ReflectionClass;
use Throwable;

/**
 * Class InstallCommand.
 *
 * `php artisan zapmizer:install`: the connect (a WhatsApp number per model)
 * and, with `--inbox`, the embedded inbox, in the mould of `reverb:install`
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

    public const EMBED_VERSION = 'github:zapmizer/embed#v0.1.2';

    /**
     * @var string
     */
    protected $signature = 'zapmizer:install
        {--model= : O model dono do número de WhatsApp (ex.: App\\Models\\User ou Team)}
        {--inbox : Liga a caixa de entrada embutida e publica os componentes Vue}
        {--migrate : Roda as migrations no fim}
        {--force : Sobrescreve a config, a migration e os componentes já publicados}';

    /**
     * @var string
     */
    protected $description = 'Instala o connect da Zapmizer (um número por model) e, com --inbox, a caixa de entrada embutida';

    /** @var array<int, string> */
    protected array $nextSteps = [];

    protected bool $failed = false;

    public function handle(): int
    {
        // Artisan::call() reuses the instance: start clean every run.
        $this->nextSteps = [];
        $this->failed = false;

        $this->line('');
        $this->line('  <options=bold>Zapmizer</> · instalação');
        $this->line('');

        $this->publishConfig();
        $this->publishMigration();
        $inbox = $this->wantsInbox();
        $this->writeEnvironment($inbox);
        $model = $this->connectModel();

        if ($inbox) {
            $this->publishComponents();
        }

        $migrated = $this->migrate();

        $this->printNextSteps($inbox, $model, $migrated);

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

    /**
     * The keys the connect reads, added only where missing, in `.env` and
     * `.env.example`. The partner keys go in empty: they are issued by
     * Zapmizer, never made up here.
     */
    protected function writeEnvironment(bool $inbox): void
    {
        $variables = [
            'ZAPMIZER_BASE_URI' => 'https://app.zapmizer.com/api/',
            'ZAPMIZER_API_VERSION' => '2025-06-27',
            'ZAPMIZER_PARTNER_ID' => '',
            'ZAPMIZER_PARTNER_SECRET' => '',
        ];

        $environment = $this->laravel->environmentFilePath();

        foreach ([$environment => '.env', base_path('.env.example') => '.env.example'] as $path => $label) {
            $file = new EnvFile($path);

            if (!$file->exists()) {
                if ($label === '.env') {
                    $this->warning('.env não existe: as chaves foram só para o .env.example. Copie-o para .env.');
                }

                continue;
            }

            // --inbox is an explicit ask: a ZAPMIZER_INBOX_ENABLED already
            // there with another value is switched on.
            $switched = $inbox && $file->has('ZAPMIZER_INBOX_ENABLED') && $file->value('ZAPMIZER_INBOX_ENABLED') !== 'true'
                && $file->set('ZAPMIZER_INBOX_ENABLED', 'true');
            $added = $file->addMissing($inbox ? $variables + ['ZAPMIZER_INBOX_ENABLED' => 'true'] : $variables);

            if ($switched) {
                $added[] = 'ZAPMIZER_INBOX_ENABLED=true';
            }

            $added === []
                ? $this->skipped("{$label} já tem as chaves da Zapmizer")
                : $this->done("{$label}: " . implode(', ', $added));
        }
    }

    /**
     * @return string|null the model made Connectable (or already it)
     */
    protected function connectModel(): ?string
    {
        $model = $this->option('model');

        if (($model === null || $model === '') && $this->input->isInteractive()) {
            $model = $this->ask('Qual model é dono do número de WhatsApp? (vazio para pular)', $this->userModel());
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
     * same set as `vendor:publish --tag=zapmizer-wizard`.
     */
    protected function publishComponents(): void
    {
        $package = $this->packageJson();

        if ($package !== null && !isset($this->dependencies($package)['vue'])) {
            $this->warning('o package.json não tem vue: os componentes não foram publicados. Sem Vue, veja "Sem Inertia/Vue" em docs/instalar.md.');

            return;
        }

        $source = $this->packagePath('stubs/inertia-vue');
        $copied = 0;
        $kept = 0;

        foreach ($this->files($source) as $file) {
            $target = resource_path('js/' . substr($file, strlen($source) + 1));

            if (is_file($target) && !$this->option('force')) {
                $kept++;

                continue;
            }

            $this->ensureDirectory(dirname($target));
            copy($file, $target);
            $copied++;
        }

        $copied > 0
            ? $this->done("componentes Vue em resources/js ({$copied} copiados" . ($kept > 0 ? ", {$kept} mantidos" : '') . ')')
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

    protected function printNextSteps(bool $inbox, ?string $model, bool $migrated): void
    {
        $steps = [];

        if (blank(config('zapmizer.partner.id')) || blank(config('zapmizer.partner.secret'))) {
            $steps[] = 'Peça ao responsável pela conta Zapmizer as credenciais de parceiro e preencha ZAPMIZER_PARTNER_ID e ZAPMIZER_PARTNER_SECRET no .env (são emitidas pela Zapmizer; não invente valores).';
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'o host do APP_URL';
        $steps[] = "Peça à Zapmizer para liberar o host {$host} na conta de parceiro: é ele que vai no redirect_uri, no webhook_url" . ($inbox ? ' e no parent_origin da caixa' : '') . ' (https em produção). Confira se o APP_URL é a URL pública do app.';

        if ($model !== null && $model !== $this->userModel() && $this->usesDefaultResolver()) {
            $steps[] = "O connect resolve o usuário logado. Para conectar {$model}, crie um resolver e aponte zapmizer.connect.resolver para ele (docs/connect.md, seção 4).";
        } elseif ($model === null && !$this->userIsConnectable()) {
            $steps[] = 'Torne Connectable o model dono do número: php artisan zapmizer:install --model=App\\Models\\User (ou o seu model).';
        }

        $steps = array_merge($steps, $this->nextSteps);

        if (!$migrated && !$this->tableExists()) {
            $steps[] = 'Rode as migrations: php artisan migrate';
        }

        if ($inbox) {
            $steps[] = 'Instale o front: ' . $this->frontendInstall();
            $steps[] = 'Monte <InboxScreen :person="String($page.props.auth.user.id)" /> (resources/js/components/zapmizer/InboxScreen.vue) numa página autenticada, chame listenToZapmizerLogout() no boot do app e endZapmizerEmbeds() antes do logout (resources/js/composables/zapmizerEmbed.ts).';
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
    protected function frontendInstall(): string
    {
        $packages = [self::EMBED_VERSION];
        $package = $this->packageJson() ?? [];
        $dependencies = $this->dependencies($package);

        foreach (['axios', 'lucide-vue-next'] as $dependency) {
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
