# Transporte HTTP, timeouts e testes

## O transporte

`PartnerClient`, `InstanceClient` e o `Zapmizer` (o cliente de envio, que o `ZapmizerMessage::send()` usa) mandam tudo pelo `Http` do Laravel, por meio do `Connect\Transports\LaravelHttpTransport`, o padrão e único transporte da lib. O ponto de extensão é a interface `Contracts\Transport` e o nome da classe em `zapmizer.http.transport`:

```php
'http' => [
    'transport' => \NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport::class,
    'connect_timeout' => env('ZAPMIZER_HTTP_CONNECT_TIMEOUT'),
    'timeout' => env('ZAPMIZER_HTTP_TIMEOUT'),
],
```

Por isso `Http::fake()`, `Http::preventStrayRequests()` (Laravel 9 em diante), `Http::globalMiddleware()` (10 em diante), os listeners de `RequestSending` e `ResponseReceived` e o Telescope veem as chamadas.

O `GuzzleHttp\Client` do container passa a valer só para o `VerificationClient`.

Quem publicou o `config/zapmizer.php` antes e tem a chave `http` sem `transport` (ou com `null` ou `''`) cai no `LaravelHttpTransport`: o merge da config é só no primeiro nível, então a chave nova não chega sozinha. Um valor inválido (classe que não implementa a interface, a própria interface, algo que não é string) lança `InvalidArgumentException` ao resolver o transporte.

Se o app registra a classe no container, os timeouts da config não se aplicam: quem registrou define os timeouts dela. Amarrar `Contracts\Transport` direto também vale.

## Timeouts

Só um número maior que zero conta. Vazio, `0` ou texto valem `null`; não use `0` como "sem limite". Sem valor, vale o padrão do `Http` da versão: nenhum no Laravel 8; 30 s de total e 10 s de conexão no 9.52 e do 10 em diante.

Recomendamos `ZAPMIZER_HTTP_CONNECT_TIMEOUT=10` e `ZAPMIZER_HTTP_TIMEOUT=30`.

O envio com arquivo (`sendMessageWithFile()`, `ZapmizerMessage::image()`/`document()`) ignora esses valores e usa os mesmos 60 s para conectar e 600 s no total (`ZapmizerApi::TRANSFER_CONNECT_TIMEOUT` e `TRANSFER_TIMEOUT`), para que um timeout curto pensado nas chamadas JSON não o corte. O envio de texto segue a config.

## Mídia

O download de mídia ignora esses timeouts e usa limites fixos de 60 s para conectar e 600 s no total (`ZapmizerApi::TRANSFER_CONNECT_TIMEOUT` e `TRANSFER_TIMEOUT`).

- O teto total pede a extensão `curl` (o `composer.json` sugere `ext-curl`). Sem ela o Guzzle cai no stream handler, que não tem `connect_timeout`, aplica o `timeout` por leitura e não acusa corpo curto. A lib compara o tamanho com o `Content-Length` para pegar esse caso; a comparação é pulada com `Transfer-Encoding`, e com gzip o `Content-Length` some.
- O corpo chega num `php://temp` (memória até 2 MB, disco depois) e é copiado para o arquivo do `MediaDownload`: mídia grande escreve duas vezes em disco.
- O Telescope, e qualquer listener que chame `body()`, carrega a mídia inteira na memória.
- Um middleware que leia o corpo não esvazia a mídia: a cópia rebobina o corpo antes de ler.

## Testes

Use `Http::fake()`. Os fakes se acumulam: um segundo `Http::fake()` não substitui o primeiro, e o primeiro stub que casa com a URL vence. Por isso o exemplo usa um só, com um padrão para cada endpoint (ou escreva um teste por fake).

```php
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Zapmizer\Connect\InstanceClient;
use NotificationChannels\Zapmizer\Connect\PartnerClient;

config([
    'zapmizer.base_uri' => 'https://zapmizer.test/api/',
    'zapmizer.partner.id' => 'partner-id',
    'zapmizer.partner.secret' => 'partner-secret',
]);

Http::fake([
    'zapmizer.test/api/connect/*' => Http::response(['url' => 'https://zapmizer.test/connect/1', 'expires_at' => null], 201),
    'zapmizer.test/api/whatsapp-messages/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
]);

$session = app(PartnerClient::class)->createSession('https://app.test/zapmizer/callback', 'state-123');

Http::assertSent(fn (Request $request) => $request->method() === 'POST'
    && $request->url() === 'https://zapmizer.test/api/connect/sessions'
    && $request['state'] === 'state-123');

$download = app(InstanceClient::class, ['api_token' => 'team-token'])->media(77, 'true_5581999998888@c.us_3EB0ABC123', 1700000000);
$download->contents(); // 'png-bytes'
```

Um `Http::fake()` sem resposta para a URL da Zapmizer responde `200` vazio (o "fake vazio"). As chamadas que esperam JSON (`createSession`, `exchangeCode`, `subscription`, `checkout`, `connection`, `createWebhook` e `rotateWebhookSecret`) falham com `unexpectedResponse`: não viram uma sessão vazia. Duas passam sem aviso: `deleteWebhook()` retorna normalmente e `media()` devolve um download anexado vazio (`size === 0`). Um `MockHandler` num `GuzzleHttp\Client` do container não pega mais parceiro, instância nem envio.

O envio de mensagem também passa pelo fake, e a recusa da Zapmizer vira `ZapmizerApiException` (os dois fakes abaixo são de testes diferentes, porque o primeiro que casa vence):

```php
use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\ZapmizerMessage;

config(['zapmizer.api_token' => 'team-token', 'zapmizer.base_uri' => 'https://zapmizer.test/api/']);

Http::fake(['zapmizer.test/*' => Http::response(['id' => 1], 200)]);

ZapmizerMessage::create(from: '5581911110000', to: '5511999999999')->text('Hello')->send();

Http::assertSent(fn (Request $request) => $request->url() === 'https://zapmizer.test/api/messages'
    && $request['to'] === '5511999999999'
    && $request['metadata']['text'] === 'Hello');

// Em outro teste, com o fake da recusa:
Http::fake(['zapmizer.test/*' => Http::response(['error' => 'window_closed', 'message' => 'The 24 h window is closed.'], 409)]);

try {
    ZapmizerMessage::create(from: '5581911110000', to: '5511999999999')->text('Hello')->send();
} catch (ZapmizerApiException $exception) {
    $exception->status();  // 409
    $exception->error();   // ErrorCode::WINDOW_CLOSED
}
```

## Transporte próprio

O contrato é implementar `Contracts\Transport::send(string $method, string $url, array $options = []): ResponseInterface`:

- As opções têm nomes do Guzzle: `headers`, `json`, `query`, `form_params`, `multipart`, `timeout`, `connect_timeout`. O envio de texto usa `form_params`; o envio de arquivo usa `multipart`, com o arquivo como recurso aberto.
- Não lance exceção em 4xx nem 5xx.
- Não siga redirect. Com o token recusado a Zapmizer redireciona para o login, e seguido isso vira um `200` HTML e uma mensagem perdida em silêncio.
- Devolva o corpo legível, de preferência seekable. Um corpo não seekable é lido de onde está, uma vez.
- Lance `ZapmizerUnavailableException` quando não houver resposta.
- O construtor com `?float $connectTimeout = null, ?float $timeout = null` recebe os valores da config.

O caso de uso típico é o proxy no Laravel 8, 9 e 10 antes do 10.48, que não têm `Http::globalOptions`:

```php
namespace App\Zapmizer;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use NotificationChannels\Zapmizer\Contracts\Transport;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use Psr\Http\Message\ResponseInterface;

class ProxiedTransport implements Transport
{
    public function __construct(protected ?float $connectTimeout = null, protected ?float $timeout = null)
    {
    }

    public function send(string $method, string $url, array $options = []): ResponseInterface
    {
        $defaults = array_filter(['connect_timeout' => $this->connectTimeout, 'timeout' => $this->timeout], fn ($value) => $value !== null);

        try {
            return (new Client())->request($method, $url, array_replace($defaults, $options, [
                'http_errors' => false,
                'allow_redirects' => false,
                'proxy' => config('services.zapmizer.proxy'),
            ]));
        } catch (TransferException $exception) {
            throw ZapmizerUnavailableException::dueTo($exception);
        }
    }
}
```

## Montar um cliente à mão

`new PartnerClient($id, $secret)`, `new InstanceClient($token)` e `new Zapmizer($token)` pegam o `Contracts\Transport` do container, já configurado. Isso pede o provider da lib registrado, o que o package discovery faz num app normal. Fora disso o erro é `BindingResolutionException`: resolva o cliente pelo container ou registre o provider.

Passar um transporte no construtor também vale. Uma config inválida falha no construtor.

## Mídia num job da fila

O webhook responde na hora e o bot pode ainda estar baixando a mídia. Por isso o listener (`QueueInboundMedia`) só despacha um job quando a mensagem tem mídia (`hasMedia`) e há conexão, sem HTTP no webhook. O job pergunta uma vez (`media()`) e se devolve à fila em vez de segurar um worker:

- `retryUntil()` é `sentAt` mais 600 s: a Zapmizer guarda a mídia por esse tempo.
- `MediaRateLimitedException`: `release($e->retryAfter() ?? 60)`.
- `MediaRejectedException`: registra no log e chama `fail()`.
- `downloading`: `release(5)`.
- `unavailable`: registra no log e termina.
- `attached`: `$disk->put($path, $download->stream())`.

O caminho é fixo por mensagem e conhecido antes de baixar, então o retry vira um `exists()`.

```php
namespace App\Jobs;

use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use Symfony\Component\Mime\MimeTypes;

class StoreInboundMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ZapmizerConnection $zapmizerConnection,
        public InboundMessage $message,
    ) {
    }

    public function retryUntil(): DateTime
    {
        return $this->message->sentAt->copy()->addSeconds(600)->toDateTime();
    }

    public function handle(): void
    {
        $disk = Storage::disk(config('services.zapmizer.media_disk'));
        $path = $this->pathFor($this->zapmizerConnection->getKey(), $this->message->id, $this->message->mediaMimeType());

        if ($disk->exists($path)) {
            return;
        }

        try {
            $download = $this->zapmizerConnection->media($this->message);
        } catch (MediaRateLimitedException $exception) {
            $this->release($exception->retryAfter() ?? 60);

            return;
        } catch (MediaRejectedException $exception) {
            Log::warning('zapmizer media rejected', ['message' => $this->message->id, 'reason' => $exception->reason()]);
            $this->fail($exception);

            return;
        }

        if ($download->isDownloading()) {
            $this->release(5);

            return;
        }

        if ($download->isUnavailable()) {
            Log::info('zapmizer media unavailable', ['message' => $this->message->id]);

            return;
        }

        $stream = $download->stream();

        try {
            $disk->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    protected function pathFor(int|string $connectionId, string $messageId, ?string $mime): string
    {
        $extension = $mime === null ? null : (MimeTypes::getDefault()->getExtensions($mime)[0] ?? null);

        return "zapmizer/{$connectionId}/" . preg_replace('/[^A-Za-z0-9_-]/', '_', $messageId) . '.' . ($extension ?? 'bin');
    }
}
```
