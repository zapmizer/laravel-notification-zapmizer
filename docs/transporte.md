# Transporte HTTP, timeouts e testes

## O transporte

`PartnerClient` e `InstanceClient` mandam tudo pelo `Http` do Laravel, por meio do `Connect\Transports\LaravelHttpTransport`, o padrão e único transporte da lib. O ponto de extensão é a interface `Contracts\Transport` e o nome da classe em `zapmizer.http.transport`:

```php
'http' => [
    'transport' => \NotificationChannels\Zapmizer\Connect\Transports\LaravelHttpTransport::class,
    'connect_timeout' => env('ZAPMIZER_HTTP_CONNECT_TIMEOUT'),
    'timeout' => env('ZAPMIZER_HTTP_TIMEOUT'),
],
```

Por isso `Http::fake()`, `Http::preventStrayRequests()` (Laravel 9 em diante), `Http::globalMiddleware()` (10 em diante), os listeners de `RequestSending` e `ResponseReceived` e o Telescope veem as chamadas.

O `Zapmizer` (envio) e o `VerificationClient` ainda usam o `GuzzleHttp\Client` do container.

Quem publicou o `config/zapmizer.php` antes e tem a chave `http` sem `transport` (ou com `null` ou `''`) cai no `LaravelHttpTransport`: o merge da config é só no primeiro nível, então a chave nova não chega sozinha. Um valor inválido (classe que não implementa a interface, a própria interface, algo que não é string) lança `InvalidArgumentException` ao resolver o transporte.

Se o app registra a classe no container, os timeouts da config não se aplicam: quem registrou define os timeouts dela. Amarrar `Contracts\Transport` direto também vale.

## Timeouts

Só um número maior que zero conta. Vazio, `0` ou texto valem `null`; não use `0` como "sem limite". Sem valor, vale o padrão do `Http` da versão: nenhum no Laravel 8; 30 s de total e 10 s de conexão no 9.52 e do 10 em diante.

Recomendamos `ZAPMIZER_HTTP_CONNECT_TIMEOUT=10` e `ZAPMIZER_HTTP_TIMEOUT=30`.

## Mídia

O download de mídia ignora esses timeouts e usa limites fixos de 60 s para conectar e 600 s no total (`ZapmizerApi::TRANSFER_CONNECT_TIMEOUT` e `TRANSFER_TIMEOUT`).

- O teto total pede a extensão `curl` (o `composer.json` sugere `ext-curl`). Sem ela o Guzzle cai no stream handler, que não tem `connect_timeout`, aplica o `timeout` por leitura e não acusa corpo curto. A lib compara o tamanho com o `Content-Length` para pegar esse caso; a comparação é pulada com `Transfer-Encoding`, e com gzip o `Content-Length` some.
- O corpo chega num `php://temp` (memória até 2 MB, disco depois) e é copiado para o arquivo do `MediaDownload`: mídia grande escreve duas vezes em disco.
- O Telescope, e qualquer listener que chame `body()`, carrega a mídia inteira na memória.
- Um middleware que leia o corpo não esvazia a mídia: a cópia rebobina o corpo antes de ler.

## Testes

Use `Http::fake()`:

```php
Http::fake(['zapmizer.test/*' => Http::response(['url' => 'https://zapmizer.test/connect/1', 'expires_at' => null], 201)]);

$session = app(PartnerClient::class)->createSession('https://app.test/zapmizer/callback', 'state-123');

Http::assertSent(fn (Request $request) => $request->method() === 'POST'
    && $request->url() === 'https://zapmizer.test/api/connect/sessions'
    && $request['state'] === 'state-123');

Http::fake(['zapmizer.test/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);

$download = app(InstanceClient::class, ['api_token' => 'team-token'])->media(77, 'true_5581999998888@c.us_3EB0ABC123', 1700000000);
$download->contents(); // 'png-bytes'
```

Um `Http::fake()` sem resposta para a URL da Zapmizer responde `200` vazio, e a chamada falha com `unexpectedResponse` (o "fake vazio"): não vira uma sessão vazia. Um `MockHandler` num `GuzzleHttp\Client` do container não pega mais parceiro nem instância.

## Transporte próprio

O contrato é implementar `Contracts\Transport::send(string $method, string $url, array $options = []): ResponseInterface`:

- As opções têm nomes do Guzzle: `headers`, `json`, `query`, `timeout`, `connect_timeout`.
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

`new PartnerClient($id, $secret)` e `new InstanceClient($token)` pegam o `Contracts\Transport` do container, já configurado. Isso pede o provider da lib registrado, o que o package discovery faz num app normal. Fora disso o erro é `BindingResolutionException`: resolva o cliente pelo container ou registre o provider.

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
