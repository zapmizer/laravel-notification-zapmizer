<?php

namespace NotificationChannels\Zapmizer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\ZapmizerApi;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnauthorizedException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use NotificationChannels\Zapmizer\Install\ConnectableModel;
use NotificationChannels\Zapmizer\Install\EnvFile;
use NotificationChannels\Zapmizer\Support\BaseUri;
use NotificationChannels\Zapmizer\Support\ConnectionWebhook;
use NotificationChannels\Zapmizer\Support\ConnectWebhook;
use NotificationChannels\Zapmizer\Support\EmbedBrand;
use NotificationChannels\Zapmizer\Support\PhoneNumber;
use NotificationChannels\Zapmizer\Support\RouteGroups;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Class CheckCommand.
 *
 * `php artisan zapmizer:check`: what the app uses, one line each,
 * ✓/✗/!/-/○. The send is always checked (the token, the base URI, the
 * default `from`); each route group (connect, webhook, inbox,
 * verify-number) only when it is switched on, and `off` (○) otherwise,
 * which is neither a failure nor a warning. Exits non-zero only when
 * something critical fails.
 *
 * The remote calls read and write nothing: `GET /api/bot-instances` with
 * the app's token (when it has one), and, with the connect on,
 * `GET /api/partner/users/{external_id}` with the partner key (Zapmizer
 * answers 401 for a key it does not accept, and 200 or 404 once it accepts
 * it). Without network (or with `--offline`) they are skipped. `--json`
 * prints the same for an agent.
 *
 * Kept parseable on PHP 8.0: Artisan instantiates it on every boot.
 */
class CheckCommand extends Command
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const SKIP = 'skip';

    /** A route group switched off: nothing of it is checked. */
    public const OFF = 'off';

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
        {--offline : Pula as checagens na API da Zapmizer}';

    /**
     * @var string
     */
    protected $description = 'Confere a instalação da Zapmizer: o envio sempre, e o connect, o webhook, a caixa de entrada e o verify-number quando ligados';

    /** @var array<int, array{id: string, status: string, message: string, hint: string|null}> */
    protected array $checks = [];

    public function handle(): int
    {
        // Artisan::call() reuses the instance: start clean every run.
        $this->checks = [];

        $connect = RouteGroups::switchedOn(RouteGroups::CONNECT);
        $inbox = $this->inboxEnabled();
        $verify = RouteGroups::switchedOn(RouteGroups::VERIFY_NUMBER);

        $this->checkAppKey($connect);
        $this->checkConfig();
        $baseUri = $this->checkBaseUri();
        $token = $this->checkApiToken($connect, $verify);
        $this->checkSendApi($token, $baseUri);
        $this->checkFromNumber();
        $this->checkConnect($connect, $baseUri);
        $this->checkWebhookRoutes();
        $this->checkInboxRoutes($inbox);
        $this->checkVerifyNumber($verify);
        $this->checkEmbedPackage($inbox);
        $this->checkEmbedBrand($inbox);
        $this->checkAppUrl($inbox);

        $failed = count(array_filter($this->checks, fn (array $check) => $check['status'] === self::FAIL));

        $this->option('json') ? $this->printJson($failed) : $this->printLines($failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The checks of the connect, or each of them `off` with the group.
     */
    protected function checkConnect(bool $connect, bool $baseUri): void
    {
        if (!$connect) {
            foreach (['partner_credentials' => 'credenciais de parceiro', 'partner_api' => 'credenciais na API', 'migration' => 'tabela das conexões', 'connectable' => 'model Connectable'] as $id => $what) {
                $this->off($id, "{$what}: só com o connect", RouteGroups::CONNECT);
            }

            $this->off('connect_routes', 'connect desligado (ZAPMIZER_ROUTES_CONNECT)', RouteGroups::CONNECT);

            return;
        }

        $credentials = $this->checkPartnerCredentials();
        $this->checkPartnerApi($credentials, $baseUri);
        $this->checkMigration();
        $this->checkConnectable();
        $this->checkConnectRoutes();
    }

    /**
     * A group switched off: not a failure nor a warning, with how to switch
     * it on.
     */
    protected function off(string $id, string $message, string $group): void
    {
        $how = [
            RouteGroups::CONNECT => 'Se o app conecta números de usuários ou times: ZAPMIZER_ROUTES_CONNECT=true no .env (o zapmizer:install --model liga).',
            RouteGroups::WEBHOOK => 'Se o app recebe mensagens ou eventos pela lib: ZAPMIZER_ROUTES_WEBHOOK=true no .env (o zapmizer:install --webhook liga).',
            RouteGroups::INBOX => 'Se o app mostra a caixa de entrada: ZAPMIZER_ROUTES_INBOX=true no .env, com o connect (o zapmizer:install --inbox liga os dois).',
            RouteGroups::VERIFY_NUMBER => 'Se o app manda o usuário verificar o número pela rota da lib: ZAPMIZER_ROUTES_VERIFY_NUMBER=true no .env.',
        ];

        $this->record($id, self::OFF, $message, $how[$group] ?? null);
    }

    /**
     * The app's own token (`ZAPMIZER_API_TOKEN`): what `ZapmizerMessage`,
     * `app(Zapmizer::class)` and the verify-number send with. With the
     * connect, each connection sends with its own and the app's is
     * optional, unless the verify-number is on.
     */
    protected function checkApiToken(bool $connect, bool $verify): bool
    {
        if (filled(config('zapmizer.api_token'))) {
            $this->record('api_token', self::OK, 'ZAPMIZER_API_TOKEN preenchido');

            return true;
        }

        if ($connect && !$verify) {
            $this->record('api_token', self::SKIP, 'ZAPMIZER_API_TOKEN vazio: cada conexão envia com o próprio token ($model->zapmizerMessage())', 'Preencha só se o app também envia pelo número dele (ZapmizerMessage::create(), o canal sem conexão).');

            return false;
        }

        $this->record('api_token', self::FAIL, 'ZAPMIZER_API_TOKEN vazio: ' . ($verify ? 'a verificação de número e o envio precisam do token do time' : 'nada é enviado sem o token do time'), 'O token de API do time na Zapmizer (Configurações > API). Peça ao humano; não invente.');

        return false;
    }

    /**
     * `GET {base}/bot-instances` with the app's token: reads and changes
     * nothing, and proves the token (401 refuses it) and that it sees a
     * number to send from.
     */
    protected function checkSendApi(bool $token, bool $baseUri): void
    {
        if (!$token || !$baseUri) {
            $this->record('send_api', self::SKIP, 'token na API: pulado, ' . (!$token ? 'sem ZAPMIZER_API_TOKEN' : 'ZAPMIZER_BASE_URI inválida'));

            return;
        }

        if ($this->option('offline')) {
            $this->record('send_api', self::SKIP, 'token na API: pulado (--offline)');

            return;
        }

        $tokenHint = 'Confira ZAPMIZER_API_TOKEN e ZAPMIZER_BASE_URI (o token é de um deploy só).';

        try {
            $numbers = (new InstanceClient((string) config('zapmizer.api_token'), app(Transport::class), (string) config('zapmizer.base_uri'), config('zapmizer.api_version')))->instances();
        } catch (ZapmizerUnauthorizedException) {
            $this->record('send_api', self::FAIL, 'a Zapmizer recusou o ZAPMIZER_API_TOKEN (401)', $tokenHint);

            return;
        } catch (ZapmizerRateLimitedException) {
            $this->record('send_api', self::WARN, 'a Zapmizer respondeu 429: não deu para conferir o token agora', 'Rode de novo em um minuto.');

            return;
        } catch (ZapmizerApiException $exception) {
            $this->record('send_api', self::FAIL, "a Zapmizer recusou a leitura dos números com o ZAPMIZER_API_TOKEN ({$exception->status()}: {$exception->getMessage()})", $exception->status() === 404 ? 'A ZAPMIZER_BASE_URI precisa ser a API, terminando em /api.' : $tokenHint);

            return;
        } catch (ZapmizerUnavailableException) {
            $this->record('send_api', self::WARN, 'sem resposta da Zapmizer (sem rede ou 5xx): checagem do token pulada', 'Rode de novo com rede para conferir o token.');

            return;
        } catch (ZapmizerConnectException $exception) {
            $this->record('send_api', self::FAIL, "a ZAPMIZER_BASE_URI não respondeu como a API da Zapmizer ({$exception->getMessage()})", 'Confira a ZAPMIZER_BASE_URI: https://app.zapmizer.com/api/ ou https://app.parlichat.com/api/.');

            return;
        }

        $numbers === []
            ? $this->record('send_api', self::WARN, 'a Zapmizer aceitou o ZAPMIZER_API_TOKEN, mas ele não vê número nenhum: nada sai até o time conectar um', 'Conecte um número no time da Zapmizer dono do token.')
            : $this->record('send_api', self::OK, 'a Zapmizer aceitou o ZAPMIZER_API_TOKEN (' . count($numbers) . (count($numbers) === 1 ? ' número' : ' números') . ')');
    }

    /**
     * `ZAPMIZER_FROM_NUMBER`, when set, is a number: the `from` of the sends
     * that take it from the config.
     */
    protected function checkFromNumber(): void
    {
        $from = config('zapmizer.from_number');

        if (blank($from)) {
            $this->record('from_number', self::SKIP, 'ZAPMIZER_FROM_NUMBER vazio: cada envio diz o from');

            return;
        }

        $digits = PhoneNumber::digits(Str::before(is_scalar($from) ? (string) $from : '', '@'));

        strlen($digits) >= 10 && strlen($digits) <= 15
            ? $this->record('from_number', self::OK, "ZAPMIZER_FROM_NUMBER {$digits}")
            : $this->record('from_number', self::FAIL, 'ZAPMIZER_FROM_NUMBER não é um número de WhatsApp (' . (is_scalar($from) ? (string) $from : get_debug_type($from)) . ')', 'O número do time com DDI e DDD, só dígitos: 5511999999999.');
    }

    protected function checkAppKey(bool $connect): void
    {
        if (!$connect) {
            $this->off('app_key', 'APP_KEY: só com o connect (cifra o token das conexões)', RouteGroups::CONNECT);

            return;
        }

        filled(config('app.key'))
            ? $this->record('app_key', self::OK, 'APP_KEY definida (cifra o token e o segredo das conexões)')
            : $this->record('app_key', self::FAIL, 'APP_KEY vazia: o token das conexões é gravado cifrado', 'php artisan key:generate');
    }

    protected function checkConfig(): void
    {
        if (is_file(config_path('zapmizer.php'))) {
            $this->record('config', self::OK, 'config/zapmizer.php publicada');

            return;
        }

        // Sending needs only the .env; the groups have keys worth seeing.
        $this->anyGroupOn()
            ? $this->record('config', self::WARN, 'config/zapmizer.php não publicada: valem os padrões da lib', 'php artisan zapmizer:install')
            : $this->record('config', self::SKIP, 'config/zapmizer.php não publicada: o envio lê o .env (ZAPMIZER_*)');
    }

    protected function checkBaseUri(): bool
    {
        $uri = config('zapmizer.base_uri');
        $problem = BaseUri::problem(is_string($uri) ? $uri : null);

        if ($problem === null) {
            $this->record('base_uri', self::OK, "ZAPMIZER_BASE_URI {$uri}");

            return true;
        }

        $this->record('base_uri', self::FAIL, 'ZAPMIZER_BASE_URI ' . (is_string($uri) ? $uri . ' ' : '') . $problem, 'A URL da API da conta de parceiro: https://app.zapmizer.com/api/ ou https://app.parlichat.com/api/. Pergunte ao humano em qual deploy está a conta.');

        return false;
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

    /**
     * `GET {base}/partner/users/zapmizer-check` with the partner key. Only two
     * answers prove the key: a 200 with the customer, or the 404 Zapmizer
     * gives an unknown customer (`{"message": "Record not found."}`, with
     * `details` saying the same). A 404 of a route that does not exist — a
     * base_uri without /api, another server — says nothing about the key.
     */
    protected function checkPartnerApi(bool $credentials, bool $baseUri): void
    {
        if (!$credentials || !$baseUri) {
            $this->record('partner_api', self::SKIP, 'credenciais na API: pulado, ' . (!$credentials ? 'sem credenciais' : 'ZAPMIZER_BASE_URI inválida'));

            return;
        }

        if ($this->option('offline')) {
            $this->record('partner_api', self::SKIP, 'credenciais na API: pulado (--offline)');

            return;
        }

        $url = rtrim((string) config('zapmizer.base_uri'), '/') . '/partner/users/' . self::PROBE_EXTERNAL_ID;

        try {
            $response = (new ZapmizerApi(app(Transport::class)))->send('GET', $url, [], [
                'X-Partner-Key' => config('zapmizer.partner.id') . '|' . config('zapmizer.partner.secret'),
            ]);
        } catch (ZapmizerUnavailableException) {
            $this->record('partner_api', self::WARN, 'sem resposta da Zapmizer (sem rede ou 5xx): checagem remota pulada', 'Rode de novo com rede para conferir as credenciais.');

            return;
        } catch (ZapmizerConnectException $exception) {
            $this->record('partner_api', self::FAIL, "a ZAPMIZER_BASE_URI não respondeu como a API da Zapmizer ({$exception->getMessage()})", 'Confira a ZAPMIZER_BASE_URI: https://app.zapmizer.com/api/ ou https://app.parlichat.com/api/.');

            return;
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];

        if ($status === 401 || $status === 403) {
            $this->record('partner_api', self::FAIL, "a Zapmizer recusou as credenciais de parceiro ({$status})", 'Confira ZAPMIZER_PARTNER_ID, ZAPMIZER_PARTNER_SECRET e ZAPMIZER_BASE_URI (a chave é de um deploy e de um ambiente só).');
        } elseif ($status === 404 && ($body['message'] ?? null) === 'Record not found.' && in_array($body['details'] ?? 'Record not found.', ['Record not found.', ''], true)) {
            $this->record('partner_api', self::OK, 'a Zapmizer aceitou as credenciais de parceiro');
        } elseif ($status === 200 && array_key_exists('external_id', $body)) {
            $this->record('partner_api', self::OK, 'a Zapmizer aceitou as credenciais de parceiro');
        } elseif ($status === 404) {
            $message = is_string($body['details'] ?? null) ? $body['details'] : (is_string($body['message'] ?? null) ? $body['message'] : 'sem corpo');

            $this->record('partner_api', self::FAIL, "a rota de parceiro não existe nessa ZAPMIZER_BASE_URI (404: {$message})", 'A ZAPMIZER_BASE_URI precisa ser a API da conta de parceiro, terminando em /api: https://app.zapmizer.com/api/ ou https://app.parlichat.com/api/.');
        } elseif ($status === 429) {
            $this->record('partner_api', self::WARN, 'a Zapmizer respondeu 429: não deu para conferir as credenciais agora', 'Rode de novo em um minuto.');
        } else {
            $this->record('partner_api', self::WARN, "resposta inesperada da Zapmizer ({$status}) ao conferir as credenciais", 'Rode de novo; se repetir, mostre a saída ao humano.');
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
        $missing = $this->missingRoutes(['zapmizer.connect.show', 'zapmizer.connect.start', 'zapmizer.connect.callback', 'zapmizer.connect.destroy', 'zapmizer.connect.reconnect', 'zapmizer.connect.reconnect.callback']);

        $missing === []
            ? $this->record('connect_routes', self::OK, 'rotas do connect registradas')
            : $this->record('connect_routes', self::FAIL, 'rotas ausentes: ' . implode(', ', $missing), $this->missingRoutesHint());
    }

    /**
     * The webhook route is where deliveries arrive; the connect asking for a
     * webhook (`zapmizer.connect.webhook`, by default what the route group
     * says) is what makes the connections receive through it. Off, the
     * connect still works (connections that only send): a warning, not a
     * failure, unless the app said so (`ZAPMIZER_CONNECT_WEBHOOK=false`).
     */
    protected function checkWebhookRoutes(): void
    {
        $connect = RouteGroups::switchedOn(RouteGroups::CONNECT);
        $configured = ConnectWebhook::configured();

        if (ConnectWebhook::invalid()) {
            $this->record('connect_webhook', self::WARN, 'ZAPMIZER_CONNECT_WEBHOOK com valor que não é booleano: o connect segue a rota do webhook', 'Use true ou false, ou tire a chave.');
        }

        if (!RouteGroups::switchedOn(RouteGroups::WEBHOOK)) {
            $this->checkWebhookGroupOff($connect, $configured);

            return;
        }

        $missing = $this->missingRoutes(array_merge(['zapmizer.webhook'], ConnectionWebhook::enabled() ? ['zapmizer.webhook.connection'] : []));

        if ($missing !== []) {
            $this->record('webhook_routes', self::FAIL, 'rotas ausentes: ' . implode(', ', $missing), $this->missingRoutesHint());

            return;
        }

        $connect && $configured === false
            ? $this->record('webhook_routes', self::OK, 'rota do webhook registrada para receber entregas (o webhook do time, verify_number.*); o connect não pede webhook para as conexões (ZAPMIZER_CONNECT_WEBHOOK=false): as conexões feitas a partir daí enviam, mas não recebem')
            : $this->record('webhook_routes', self::OK, 'rota do webhook registrada');
    }

    /**
     * The webhook group is off: with the connect on, the app either said the
     * connections need no webhook, mounted the route itself for the connect
     * to ask for, or left the connections without one (a warning).
     */
    private function checkWebhookGroupOff(bool $connect, ?bool $configured): void
    {
        if (!$connect) {
            $this->off('webhook_routes', 'webhook da lib desligado (ZAPMIZER_ROUTES_WEBHOOK)', RouteGroups::WEBHOOK);

            return;
        }

        if ($configured === false) {
            $this->off('webhook_routes', 'o connect não pede webhook (ZAPMIZER_CONNECT_WEBHOOK=false) e o webhook da lib está desligado (ZAPMIZER_ROUTES_WEBHOOK)', RouteGroups::WEBHOOK);

            return;
        }

        // Asks: the app said so, or the package routes are off and the app
        // mounts them itself (the connect then sends route('zapmizer.webhook')).
        $why = match (true) {
            $configured === true => 'ZAPMIZER_CONNECT_WEBHOOK=true',
            $configured === null && !RouteGroups::routesEnabled() => 'ZAPMIZER_ROUTES_ENABLED=false: o app monta as rotas',
            default => null,
        };

        if ($why !== null) {
            $missing = $this->missingRoutes(array_merge(['zapmizer.webhook'], ConnectionWebhook::enabled() ? ['zapmizer.webhook.connection'] : []));

            $missing === []
                ? $this->record('webhook_routes', self::OK, "o connect pede webhook ({$why}) na rota zapmizer.webhook montada pelo app")
                : $this->record('webhook_routes', self::FAIL, "o connect pede webhook ({$why}), mas a rota do webhook não existe: " . implode(', ', $missing), $configured === true
                    ? 'Ligue ZAPMIZER_ROUTES_WEBHOOK=true, monte a rota zapmizer.webhook no app ou tire ZAPMIZER_CONNECT_WEBHOOK.'
                    : 'Monte a rota zapmizer.webhook no app, ou ZAPMIZER_CONNECT_WEBHOOK=false se as conexões só enviam.');

            return;
        }

        $this->record('webhook_routes', self::WARN, 'webhook da lib desligado (ZAPMIZER_ROUTES_WEBHOOK): as conexões novas nascem sem webhook e não recebem mensagens nem eventos', 'Se o app recebe mensagens pela lib, ZAPMIZER_ROUTES_WEBHOOK=true (o zapmizer:install --webhook liga); as conexões feitas sem ele precisam conectar de novo. Se o app só envia pelas conexões, ZAPMIZER_CONNECT_WEBHOOK=false deixa isso explícito.');
    }

    protected function checkInboxRoutes(bool $inbox): void
    {
        if (!$inbox) {
            $legacy = $this->legacyInboxSwitch();

            $legacy
                ? $this->record('inbox_routes', self::WARN, 'ZAPMIZER_INBOX_ENABLED não liga mais a caixa de entrada (desde a 2.0.0-beta.4)', 'Troque por ZAPMIZER_ROUTES_INBOX=true no .env (e tire a chave antiga), ou apague a chave antiga se o app não usa a caixa da lib.')
                : $this->off('inbox_routes', 'caixa de entrada desligada (ZAPMIZER_ROUTES_INBOX)', RouteGroups::INBOX);

            return;
        }

        if (!RouteGroups::switchedOn(RouteGroups::CONNECT)) {
            $this->record('inbox_routes', self::FAIL, 'a caixa de entrada está ligada, mas o connect não (ZAPMIZER_ROUTES_CONNECT): a caixa abre para a conexão feita pelo connect', 'ZAPMIZER_ROUTES_CONNECT=true no .env.');

            return;
        }

        $missing = $this->missingRoutes(['zapmizer.inbox.session', 'zapmizer.inbox.keepalive']);

        $missing === []
            ? $this->record('inbox_routes', self::OK, 'rotas da caixa de entrada registradas')
            : $this->record('inbox_routes', self::FAIL, 'rotas ausentes: ' . implode(', ', $missing), $this->missingRoutesHint());
    }

    /**
     * The verify-number route sends the logged-in user to the hosted page:
     * the user model implements MustVerifyWhatsapp, and the state lives in
     * `whatsapp_verifieds`. The token is checked by `api_token`.
     */
    protected function checkVerifyNumber(bool $verify): void
    {
        if (!$verify) {
            $this->off('verify_number_routes', 'verify-number desligado (ZAPMIZER_ROUTES_VERIFY_NUMBER)', RouteGroups::VERIFY_NUMBER);

            return;
        }

        if ($this->missingRoutes(['zapmizer.verify_number']) !== []) {
            $this->record('verify_number_routes', self::FAIL, 'rota ausente: zapmizer.verify_number', $this->missingRoutesHint());

            return;
        }

        $user = ltrim((string) config('auth.providers.users.model'), '\\');

        if ($user === '' || !class_exists($user) || !is_subclass_of($user, 'NotificationChannels\Zapmizer\Contracts\MustVerifyWhatsapp')) {
            $this->record('verify_number_routes', self::FAIL, "o verify-number usa o usuário logado, mas {$user} não implementa MustVerifyWhatsapp", 'No model de usuário, implements Contracts\\MustVerifyWhatsapp (o contrato) e use MustVerifyWhatsapp (o trait): docs/verify-number.md.');

            return;
        }

        $model = config('zapmizer.models.whatsapp_verified', 'NotificationChannels\Zapmizer\Models\WhatsappVerified');

        try {
            $table = (new $model())->getTable();
            $exists = Schema::connection((new $model())->getConnectionName())->hasTable($table);
        } catch (Throwable $exception) {
            $this->record('verify_number_routes', self::FAIL, 'não deu para ler o banco: ' . $exception->getMessage(), 'Confira a conexão do banco e rode php artisan migrate.');

            return;
        }

        if (!$exists) {
            $this->record('verify_number_routes', self::FAIL, "tabela {$table} não existe (estado da verificação de número)", 'php artisan vendor:publish --tag=zapmizer-migrations-verify e php artisan migrate');

            return;
        }

        $registered = "verify-number registrado ({$user} é MustVerifyWhatsapp, tabela {$table} criada)";
        $secret = config('zapmizer.verify_number.webhook_secret');

        if (is_string($secret) && $secret !== '') {
            $this->record('verify_number_routes', self::OK, $registered . '; as entregas verify_number.* só valem assinadas com ZAPMIZER_VERIFY_NUMBER_WEBHOOK_SECRET');

            return;
        }

        // Deliveries only arrive through the webhook route.
        Route::has('zapmizer.webhook')
            ? $this->record('verify_number_routes', self::WARN, $registered . ', mas o app aceita as entregas verify_number.* sem assinatura: qualquer um que saiba a URL do webhook confirma um número', 'Em Desenvolvedores → Webhooks da Zapmizer, clique em "Gerar segredo" no webhook do verify_number e ponha o valor em ZAPMIZER_VERIFY_NUMBER_WEBHOOK_SECRET no .env (docs/verify-number.md).')
            : $this->record('verify_number_routes', self::OK, $registered);
    }

    /**
     * @param array<int, string> $names
     * @return array<int, string>
     */
    protected function missingRoutes(array $names): array
    {
        return array_values(array_filter($names, fn (string $name) => !Route::has($name)));
    }

    protected function missingRoutesHint(): string
    {
        return RouteGroups::routesEnabled()
            ? 'Rode php artisan route:clear (rotas em cache).'
            : 'ZAPMIZER_ROUTES_ENABLED=false: monte as rotas da lib no app ou ligue de novo.';
    }

    /**
     * `ZAPMIZER_INBOX_ENABLED` (`inbox.enabled`) switched the inbox on until
     * 2.0.0-beta.3; it does nothing now.
     */
    protected function legacyInboxSwitch(): bool
    {
        $file = new EnvFile($this->laravel->environmentFilePath());

        return filter_var(config('zapmizer.inbox.enabled', false), FILTER_VALIDATE_BOOLEAN)
            || ($file->exists() && $file->has('ZAPMIZER_INBOX_ENABLED'));
    }

    protected function checkEmbedPackage(bool $inbox): void
    {
        if (!$inbox) {
            $this->off('embed_package', '@zapmizer/embed: só com a caixa de entrada', RouteGroups::INBOX);

            return;
        }

        $path = base_path('package.json');
        $package = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $version = is_array($package) ? ($package['dependencies'][InstallCommand::EMBED_PACKAGE] ?? $package['devDependencies'][InstallCommand::EMBED_PACKAGE] ?? null) : null;

        if (!is_string($version)) {
            $this->record('embed_package', self::FAIL, InstallCommand::EMBED_PACKAGE . ' não está no package.json', 'Instale o front: npm install ' . InstallCommand::EMBED_VERSION . ' (ou bun/pnpm/yarn add, conforme o lockfile).');

            return;
        }

        $vue = $package['dependencies']['vue'] ?? $package['devDependencies']['vue'] ?? null;

        if (is_string($vue) && preg_match('/(\d+)(?:\.(\d+))?/', $vue, $match) === 1 && version_compare($match[1] . '.' . ($match[2] ?? '0'), '3.3', '<')) {
            $this->record('vue_version', self::WARN, "o package.json declara vue {$vue}: o @zapmizer/embed/vue pede vue >= 3.3", 'Suba o vue para ^3.3 (o InboxScreen usa @zapmizer/embed/vue).');
        }

        if (preg_match('/#(v\d+\.\d+\.\d+)$/', $version, $tag) !== 1) {
            $this->record('embed_package', self::WARN, InstallCommand::EMBED_PACKAGE . " sem tag fixa ({$version})", 'Fixe a tag: ' . InstallCommand::EMBED_VERSION);

            return;
        }

        $wanted = substr(InstallCommand::EMBED_VERSION, (int) strrpos(InstallCommand::EMBED_VERSION, '#') + 1);

        // Before v0.1.3 the package's types only resolved through `exports`:
        // an app on `moduleResolution: node` got none for @zapmizer/embed/*.
        version_compare(ltrim($tag[1], 'v'), ltrim($wanted, 'v'), '<')
            ? $this->record('embed_package', self::WARN, InstallCommand::EMBED_PACKAGE . " {$tag[1]} no package.json: atualize para {$wanted}: tipos com moduleResolution node", 'Troque a dependência por ' . InstallCommand::EMBED_VERSION . ' (sem mexer no tsconfig por causa dos tipos do embed).')
            : $this->record('embed_package', self::OK, InstallCommand::EMBED_PACKAGE . " {$tag[1]} no package.json");
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
            $this->off('embed_brand', 'marca do embed: só com a caixa de entrada', RouteGroups::INBOX);

            return;
        }

        $expected = EmbedBrand::resolve();
        $front = $this->frontBrand();
        $hint = "VITE_ZAPMIZER_BRAND={$expected} no .env (é a marca do deploy da ZAPMIZER_BASE_URI; ZAPMIZER_EMBED_BRAND sobrescreve) e rebuilde o front.";

        if ($front === null || $front === '') {
            $this->record('embed_brand', self::FAIL, 'VITE_ZAPMIZER_BRAND ausente: o front não sabe a marca do embed', $hint);

            return;
        }

        $baseUri = (string) config('zapmizer.base_uri');

        // A known host (app.zapmizer.com, parlichat.com) says the brand; any
        // other deploy is asked for it (`/brand/manifest.json`).
        if (EmbedBrand::knownDeploy($baseUri) !== null) {
            $front !== $expected
                ? $this->record('embed_brand', self::FAIL, "VITE_ZAPMIZER_BRAND={$front}, mas a marca do embed é {$expected}: a caixa não receberia o ready", $hint)
                : $this->record('embed_brand', self::OK, "marca do embed {$expected} no front e na config");

            return;
        }

        $host = (string) parse_url($baseUri, PHP_URL_HOST);
        $deploy = $this->option('offline') ? null : EmbedBrand::discover($baseUri);

        if ($deploy === null) {
            $front !== $expected
                ? $this->record('embed_brand', self::FAIL, "VITE_ZAPMIZER_BRAND={$front}, mas a marca do embed é {$expected}: a caixa não receberia o ready", $hint)
                : $this->record('embed_brand', self::WARN, "marca do embed {$front}, sem confirmação: {$host} não é um deploy conhecido (app.zapmizer.com, *.parlichat.com) e " . ($this->option('offline') ? 'o --offline não pergunta a ele' : 'ele não disse a marca em ' . EmbedBrand::manifestUrl($baseUri)), 'Confirme com o humano a marca do deploy (zapmizer ou parli) e acerte VITE_ZAPMIZER_BRAND e ZAPMIZER_EMBED_BRAND; com a marca errada a caixa termina em ready_timeout.');

            return;
        }

        $deployHint = "VITE_ZAPMIZER_BRAND={$deploy} e ZAPMIZER_EMBED_BRAND={$deploy} no .env e rebuilde o front.";

        if ($front !== $deploy) {
            $this->record('embed_brand', self::FAIL, "VITE_ZAPMIZER_BRAND={$front}, mas o deploy de {$host} é {$deploy} (" . EmbedBrand::manifestUrl($baseUri) . '): a caixa não receberia o ready', $deployHint);
        } elseif ($expected !== $deploy) {
            $this->record('embed_brand', self::WARN, "o front usa {$deploy}, a marca do deploy, mas a config dá {$expected} (o caminho Blade lê a config)", $deployHint);
        } else {
            $this->record('embed_brand', self::OK, "marca do embed {$deploy} no front e na config, confirmada pelo deploy de {$host}");
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
        if (!$this->anyGroupOn()) {
            $this->off('app_url', 'APP_URL: só com um grupo de rotas ligado (o envio não usa)', RouteGroups::CONNECT);

            return;
        }

        $url = (string) config('app.url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            $this->record('app_url', self::FAIL, "APP_URL não é uma URL absoluta ({$url})", 'APP_URL=https://seu-app.com: é a base do redirect_uri e do webhook_url do connect.');

            return;
        }

        $local = $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP) !== false;

        // https is only required by a production deploy (the zapbot's
        // PartnerUrlRule checks the scheme only when isProduction()): a local
        // or staging Zapmizer takes http, as long as the host is allowed.
        $production = EmbedBrand::knownDeploy(config('zapmizer.base_uri')) !== null;

        if (($scheme !== 'https' || $local) && $production) {
            $this->record('app_url', self::WARN, "APP_URL {$url}: o deploy de produção da Zapmizer/Parli só aceita redirect_uri/webhook_url https, em host público liberado para o parceiro", 'Para testar o connect contra o deploy de produção em dev, use uma URL pública https (túnel) liberada na conta de parceiro. Contra um Zapmizer local, http funciona.');
        } elseif ($scheme !== 'https' || $local) {
            $this->record('app_url', self::OK, "APP_URL {$url}: http vale com um Zapmizer local ou de desenvolvimento (https só é exigido em produção); o host {$host} precisa estar liberado na conta de parceiro");
        } else {
            $this->record('app_url', self::OK, "APP_URL {$url} (o host {$host} precisa estar liberado na conta de parceiro)");
        }

        $origin = config('zapmizer.inbox.parent_origin');

        if ($inbox && is_string($origin) && trim($origin) !== '' && preg_match('#^https?://[^/?\#]+/?$#i', trim($origin)) !== 1) {
            $this->record('parent_origin', self::FAIL, "ZAPMIZER_INBOX_PARENT_ORIGIN ({$origin}) não é uma origem", 'Só esquema, host e porta: https://seu-app.com');
        }
    }

    /**
     * "o envio, o connect e a caixa de entrada": what was checked.
     */
    protected function checkedParts(): string
    {
        $names = [
            RouteGroups::CONNECT => 'o connect',
            RouteGroups::WEBHOOK => 'o webhook',
            RouteGroups::INBOX => 'a caixa de entrada',
            RouteGroups::VERIFY_NUMBER => 'o verify-number',
        ];
        $parts = ['o envio'];

        foreach ($names as $group => $name) {
            if (RouteGroups::switchedOn($group)) {
                $parts[] = $name;
            }
        }

        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts) . ' e ' . $last;
    }

    protected function anyGroupOn(): bool
    {
        return array_filter(RouteGroups::ALL, fn (string $group) => RouteGroups::switchedOn($group)) !== [];
    }

    protected function inboxEnabled(): bool
    {
        return RouteGroups::switchedOn(RouteGroups::INBOX);
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
            self::OFF => '<fg=gray>○</>',
        ];

        $this->line('');
        $this->line('  <options=bold>Zapmizer</> · diagnóstico');
        $this->line('');

        foreach ($this->checks as $check) {
            $this->line("  {$marks[$check['status']]} " . OutputFormatter::escape($check['message']));

            if ($check['hint'] !== null && !in_array($check['status'], [self::OK, self::OFF], true)) {
                $this->line('      <fg=gray>→ ' . OutputFormatter::escape($check['hint']) . '</>');
            }
        }

        $this->line('');
        $this->line($failed === 0
            ? '  <info>Tudo certo para ' . $this->checkedParts() . '.</info>'
            : "  <fg=red>{$failed} " . ($failed === 1 ? 'problema crítico' : 'problemas críticos') . '.</>');
        $this->line('');
    }
}
