# Changelog

# Unreleased

- **O connect pode deixar de pedir webhook sem desligar a rota do webhook.** Até aqui `zapmizer.routes.webhook` fazia duas coisas: registrava `zapmizer.webhook` (onde chegam as entregas, inclusive o webhook do time do app, cadastrado à mão, com os `verify_number.*`) e fazia o `start` mandar `webhook_url` na sessão, o que registra um webhook por conexão de usuário. Um app que só queria a verificação de número ligava a rota e ganhava, junto, esse webhook em cada conexão. Agora existe `zapmizer.connect.webhook` (`ZAPMIZER_CONNECT_WEBHOOK`): sem ela (`null`), o connect segue a rota, como antes; `false` mantém a rota ligada para as entregas do app e o `start` não manda `webhook_url` nem `webhook_version` (as conexões feitas a partir daí enviam, mas não recebem; uma conexão que já tem webhook o mantém e segue recebendo até desconectar ou ter o token revogado); `true` com a rota desligada faz o `start` pedir o webhook e deixa a rota `zapmizer.webhook` para o app montar. O leitor é `Support\ConnectWebhook`, e `ConnectController::asksForWebhook()` continua `protected` e sobrescrevível.
- Sem webhook pedido, o callback não vira `webhook_failed`: o `start` marca no estado pendente que não pediu, e o callback termina em `status: "ok"`, mesmo que a Zapmizer devolva um `webhook_id`, sem gravar `webhook_id`, `webhook_secret` nem `webhook_previous_secret`: um reconnect do mesmo time com a chave em `false` não apaga o webhook que a conexão já tinha (a troca de time ainda o limpa). Um valor que não é booleano é lido como ausente (o connect segue a rota) e o `zapmizer:check` avisa com `connect_webhook`. Um estado aberto antes do deploy (sem a marca) conta como tendo pedido.
- O `zapmizer:check` reconhece o arranjo: com a rota ligada e `ZAPMIZER_CONNECT_WEBHOOK=false` o resultado é `ok`, com a mensagem de que as conexões feitas a partir daí enviam, mas não recebem; com a rota desligada, `false` é `off`, e `true` (ou a chave ausente com `ZAPMIZER_ROUTES_ENABLED=false`) confere se o app montou `zapmizer.webhook`. O aviso do connect sem webhook sugere `ZAPMIZER_CONNECT_WEBHOOK=false` para quem só envia. O `zapmizer:install` não lista o `webhook_url` entre os hosts a liberar quando o connect não pede webhook e diz, nos próximos passos, o que as conexões não recebem.
- Sem breaking: sem a chave, ou com ela `null`, nada muda. O merge da config é raso: uma `config/zapmizer.php` publicada antes da 2.1 lê `null` e segue como antes; para usar a chave, acrescente `'webhook' => env('ZAPMIZER_CONNECT_WEBHOOK'),` dentro de `connect`. Documentado em `docs/connect.md`, `docs/instalar.md`, `docs/webhook.md` e `docs/verify-number.md`.

# 2.0.2

- **Os componentes Vue publicados vêm sem estilo: o visual é do app.** `ConnectButton.vue`, `ConnectionPanel.vue` e `InboxScreen.vue` (`zapmizer:install --inbox`, tags `zapmizer-wizard` e `zapmizer-inbox`) usavam classes de um design system que nem a lib nem o app tinham (`bg-gp-panel`, `text-gp-muted`, `gp-tag-mint`, `gp-eyebrow`...) e utilitárias de cor e tipografia do Tailwind; instalados, saíam sem estilo e nada dizia que o app precisava estilizá-los. Agora só trazem classes de gancho `zapmizer-*` no padrão BEM (`zapmizer-connect__button`, `zapmizer-panel__status--connected`...), sem CSS; o layout que a caixa precisa segue em `style` inline. Estrutura, textos, ícones, props, eventos e `role`/`aria-*` são os mesmos, e os ícones ganham `:size` no lugar das classes de tamanho. O `docs/instalar.md` (passo 4, com a lista das classes), o README, o `llms.txt`, a skill e as diretrizes do Boost e os próximos passos do `zapmizer:install --inbox` dizem que o app adapta os componentes ao próprio design system ou UI kit e que o `--force` sobrescreve os arquivos editados.
- Os arquivos publicados em `resources/js/zapmizer/` (`.vue` e `.ts`) passam a seguir um lint comum de app: indentação de 4 espaços e linhas de até 120 colunas (prettier com `tabWidth: 4`, `printWidth: 120` e `vue/max-len` 120).
- Sem breaking de API: nada muda em PHP, rotas, props ou eventos. Só um app que dependia das classes `gp-*` dos componentes (nenhum deveria; elas não existiam fora do app de onde os componentes vieram) perde esse visual ao republicá-los com `--force`.

# 2.0.1

- **`zapmizer:install` não leva mais um host de desenvolvimento ao `.env.example`.** Com a `ZAPMIZER_BASE_URI` fora dos deploys conhecidos (um `http://localhost:8001/api/` no `.env`, ou passado em `--base-uri`), o `.env.example` recebia o mesmo host e, quando o deploy dizia a marca em `/brand/manifest.json`, também o `ZAPMIZER_EMBED_BRAND`. Agora ele recebe a API pública do deploy da mesma marca (`https://app.parlichat.com/api/` para `parli`; `https://app.zapmizer.com/api/` para `zapmizer` e para marca desconhecida) e não recebe `ZAPMIZER_EMBED_BRAND`. Um host conhecido (app.zapmizer.com, *.parlichat.com) vai igual nos dois arquivos, e o `.env` não muda. Uma chave já presente no `.env.example` continua intocada: um app que já commitou o host local precisa trocá-lo à mão.

# 2.0.0

**Quebra a 0.x.** Leia "Upgrade das versões 0.0, 0.1 e 0.2" ou "Upgrade da 0.3 e da 0.4" antes de atualizar; o resto desta seção traz o de/para por tema.

## Rotas por grupo, todas desligadas (desde a 2.0.0-beta.4)

**Breaking.** A lib não registra rota nenhuma até o app ligar um grupo. Até a 2.0.0-beta.3 (e na 0.0.6 a 0.4), subir de versão dava ao app as rotas do connect, do reconnect, do webhook público e do verify-number, mesmo num app que só envia.

| Grupo | Chave | Env | Rotas |
| --- | --- | --- | --- |
| connect | `zapmizer.routes.connect` | `ZAPMIZER_ROUTES_CONNECT` | `zapmizer.connect.show`, `.destroy`, `.start`, `.callback`, `.reconnect`, `.reconnect.callback` |
| webhook | `zapmizer.routes.webhook` | `ZAPMIZER_ROUTES_WEBHOOK` | `zapmizer.webhook` e, com `webhook.per_connection`, `zapmizer.webhook.connection` |
| inbox | `zapmizer.routes.inbox` | `ZAPMIZER_ROUTES_INBOX` | `zapmizer.inbox.session`, `zapmizer.inbox.keepalive` |
| verify_number | `zapmizer.routes.verify_number` | `ZAPMIZER_ROUTES_VERIFY_NUMBER` | `zapmizer.verify_number` |

- Todos `false` por padrão. `zapmizer.routes.enabled` (`ZAPMIZER_ROUTES_ENABLED`) segue como chave mestra: com `false`, nenhuma rota.
- **`zapmizer.inbox.enabled` (`ZAPMIZER_INBOX_ENABLED`) saiu**, trocada por `zapmizer.routes.inbox` (`ZAPMIZER_ROUTES_INBOX`). A chave antiga não liga mais nada; o `zapmizer:check` avisa quando ela continua no `.env` ou na config.
- Uma `config/zapmizer.php` publicada sem as chaves novas em `routes` as recebe da lib (o provider completa `routes`, sem mexer nas chaves do app): basta a env.
- **Connect sem webhook** é válido: com o connect ligado e o webhook não, o `start` não manda `webhook_url` nem `webhook_version`, e as conexões só enviam. Ligar o webhook depois não dá webhook às conexões já feitas.
- `zapmizer:install`: `--model` liga o connect, `--inbox` a caixa e o connect, e `--webhook` (novo) o webhook; grava `ZAPMIZER_ROUTES_*=true` no `.env` e no `.env.example`, e liga uma chave já presente com outro valor. Sem flag, nenhuma. Os próximos passos avisam quando o connect fica sem webhook. Interativo, pergunta também pelo webhook.
- `zapmizer:check` confere só o que está ligado (desde a 2.0.0-beta.5; na beta.4, `connect_routes` falhava com o connect desligado, e um app que só envia não passava). Veja "`zapmizer:check` em app que só envia" abaixo. `webhook_routes` (novo) avisa com o connect ligado e o webhook não, e falha com o grupo ligado e a rota ausente; `inbox_routes` falha com a caixa ligada e o connect não.
- `routes/web.php` fica só com o connect e o reconnect; o verify-number passa a `routes/verify-number.php`.
- Testes do app que batiam nas rotas da lib precisam ligar os grupos (no `phpunit.xml`, por exemplo, `<env name="ZAPMIZER_ROUTES_CONNECT" value="true"/>`).

## `zapmizer:check` em app que só envia (desde a 2.0.0-beta.5)

O check confere o envio sempre e cada grupo de rotas só quando ele está ligado.

- **Novos, sempre:** `api_token` (o `ZAPMIZER_API_TOKEN`: falha sem ele quando o connect está desligado ou o verify-number ligado; com o connect, `skip`, porque cada conexão envia com o próprio token), `send_api` (`GET /api/bot-instances` com esse token, só leitura: `401` falha, lista vazia avisa que não há número para enviar, sem rede avisa, `--offline` pula) e `from_number` (o `ZAPMIZER_FROM_NUMBER`, quando definido, tem 10 a 15 dígitos).
- **Status novo `off`** no `--json` (○ na saída para humanos): o grupo está desligado, nada dele foi conferido, e o `hint` diz como ligar. Não é falha nem aviso, e o exit code fica 0. Saem como `off` com o connect desligado: `app_key`, `partner_credentials`, `partner_api` (sem chamada à Zapmizer), `migration`, `connectable` e `connect_routes`; com o webhook desligado e o connect também, `webhook_routes`; com a caixa desligada, `inbox_routes`, `embed_package` e `embed_brand` (antes `skip`); sem nenhum grupo, `app_url`.
- **`verify_number_routes` (novo):** com `ZAPMIZER_ROUTES_VERIFY_NUMBER=true`, a rota registrada, o model de usuário com `MustVerifyWhatsapp` e a tabela `whatsapp_verifieds`.
- `config` vira `skip` (era aviso) quando nenhum grupo está ligado: o envio só lê o `.env`.
- As dependências entre grupos ficam: caixa ligada sem connect falha; connect ligado sem webhook avisa.
- O formato do `--json` não muda (`ok` e `checks` com `id`, `status`, `message`, `hint`); um agente que tratava `status` como `ok|fail|warn|skip` precisa aceitar `off`. A tabela dos itens está em `docs/instalar.md`.

## Testes: `Zapmizer::fake()` (desde a 2.0.0-beta.5)

Novo, no estilo do `Mail::fake()`: `NotificationChannels\Zapmizer\Facades\Zapmizer::fake()` pega todo envio da lib e nenhuma requisição sai.

- **Facade nova `NotificationChannels\Zapmizer\Facades\Zapmizer`**, com o mesmo nome curto do cliente `NotificationChannels\Zapmizer\Zapmizer` (que ela resolve, com o token da config). Num arquivo que usa os dois, dê um alias a um. Um `$this->mock(Zapmizer::class)` com a facade importada não mocka o cliente.
- **O que pega:** `sendMessage()`, `sendMessageWithFile()` e `sendTemplate()` pela facade ou por qualquer cliente (do container, com parâmetros ou `new`), `ZapmizerMessage::send()`, o canal `zapmizer`, o `zapmizer:send-message` e os envios pelo número de um model conectado (`$model->zapmizerMessage()`, `$model->zapmizer()`, `ZapmizerConnection::message()`/`zapmizer()`). O ponto único é a classe `Zapmizer`: enquanto o fake (`Testing\ZapmizerFake`) está no container, ela entrega o envio a ele. `track()`, `sentMessage()` e `metaMessage()` respondem `QUEUED` sem chamar a API. A verificação de número, o connect, o parceiro e o `InstanceClient` não passam pelo fake: `Http::fake()`.
- **Resposta:** `SentMessage::fake()` com `id` próprio e `widTo`/`widFrom` dos números enviados; no template, `SentTemplate` com `wamid`.
- **Asserções:** `assertSent(?callable)`, `assertSentTimes(int, ?callable)`, `assertNotSent(?callable)`, `assertNothingSent()` e `sent(?callable): Collection`. O callback recebe um `Testing\FakeSend` (`kind`, `to`, `from`, `text`, `type`, `file`, `template`, `language`, `body`, `header`, `token`, `baseUri`, `connection`, `params`, `response`).
- **Falha:** `Zapmizer::fake()->failWith($exception)` (ou um closure que recebe o `FakeSend` e devolve a exceção ou `null`) e `->failWithApiError(int $status, array $body = [], array $headers = [])`, que monta a exceção como a API (`ZapmizerUnauthorizedException` no 401, `ZapmizerRateLimitedException` no 429, `ZapmizerApiException` no resto). O envio que falha não é registrado.
- `Zapmizer::getConnection()`/`setConnection()` (novos): a conexão de um cliente feito por `ZapmizerConnection::zapmizer()`, que o fake mostra em `FakeSend::$connection`; `app(Zapmizer::class, ['connection' => $connection])` aceita a chave.
- Sem o fake, nada muda: o `Http::fake()` e o mock do `Zapmizer` seguem valendo.

## Upgrade das versões 0.0, 0.1 e 0.2

Para quem está na 0.0.x, na 0.1.x ou na 0.2.x, em geral apps que só enviam. Quem também usa o connect, o webhook ou as mensagens recebidas lê em seguida "Upgrade da 0.3 e da 0.4", que vale aqui também. As quebras do envio abaixo são quase todas silenciosas, ou só aparecem **depois** que a mensagem já saiu: confira cada uma antes de subir.

1. **Rotas.** Da 0.0.6 à 0.2 a lib registrava sozinha o verify-number e o webhook (e, desde a 0.1, o connect); a 0.0.5 e anteriores, nenhuma. Agora nenhuma vem ligada (veja "Rotas por grupo"). Um app que só envia não faz nada. Quem usa uma delas liga o grupo: `ZAPMIZER_ROUTES_VERIFY_NUMBER=true`, `ZAPMIZER_ROUTES_WEBHOOK=true`, `ZAPMIZER_ROUTES_CONNECT=true`.
2. **O envio devolve `Messages\SentMessage`.** `Zapmizer::sendMessage()` e `sendMessageWithFile()` devolviam `?ResponseInterface`, e `ZapmizerMessage::send()` devolvia a própria mensagem (`$this`). As duas devolvem `SentMessage` agora (`Zapmizer.php:101` e `:111`, `ZapmizerMessage.php:78`). Procure no app:
   - **`toZapmizer(): ZapmizerMessage` que devolve `->send()`**: dá `TypeError` *depois* de enviar. Numa notificação na fila, o job falha e é tentado de novo, e cada tentativa envia outra vez. Troque o tipo para `SentMessage` (ou tire o tipo), ou devolva a mensagem **sem** `->send()`: desde a 2.0.0-beta.4 o canal a envia, com a rota do notifiable quando ela não tem `to` (veja "Envio").
   - **`$response->getBody()`, `json_decode((string) $response?->getBody(), true)`, `getStatusCode()`** no retorno do envio: `Error` de método inexistente, de novo depois de enviar, com o mesmo risco de reenvio num job. O `?->` não protege: o retorno nunca é `null`. Use os campos: `$sent->id` (sempre string, e lido tanto da resposta com `api-version` quanto do formato antigo em `data`, que antes se lia como `data.id`), `$sent->widTo`, `$sent->selfUrl` e `$sent->raw()` (o corpo decodificado). Não há `getStatusCode()`: um envio que volta é 2xx, e o resto lança exceção.
   - **Listener de `NotificationSent`** que olha o retorno (`$event->response['message'] instanceof ZapmizerMessage`, ou que conta o envio como "aceito pelo provedor" por ele): passa a ver `SentMessage`, e o teste antigo só deixa de casar, sem erro. Troque por `instanceof SentMessage`.
3. **`CouldNotSendNotification` saiu.** Um `catch (CouldNotSendNotification $e)` ou um `instanceof CouldNotSendNotification` de uma classe que não existe **não dá erro**: só nunca casa. O `catch` deixa a exceção passar (um 500 no controller, um job falho), e um listener de `NotificationFailed` que filtra por ela para de rodar, sem log: se ele contava falhas para desativar uma integração, nada mais é contado nem desativado. Procure o nome no app inteiro, inclusive nos testes. De/para:

   | Caso | 0.0 a 0.2 | 2.0 |
   | --- | --- | --- |
   | 4xx | `CouldNotSendNotification` (`` Zapmizer responded with an error `{status} - {description}` ``), `getPrevious()` o `ClientException` do Guzzle | `ZapmizerApiException`: `status()`, `error()` (o código da API, ex. `ErrorCode::WINDOW_CLOSED`), `reason()`, `errors()`, `payload()`; `getPrevious()` `null` |
   | 401 | idem | `ZapmizerUnauthorizedException`, filha de `ZapmizerApiException` |
   | 429 | idem | `ZapmizerRateLimitedException`, com `retryAfter()` |
   | 5xx, rede, timeout | `CouldNotSendNotification` (`The communication with Zapmizer failed...`), sem `previous` | `ZapmizerUnavailableException`; `getPrevious()` é a exceção de rede do `Http` (ou do Guzzle), `null` num 5xx |
   | sem token | `CouldNotSendNotification` | `ZapmizerUnauthorizedException`, `status()` 401, mesma mensagem |
   | redirect ou resposta que não é JSON (0.1.1+) | `CouldNotSendNotification` | `ZapmizerConnectException` (`unexpectedResponse`) |
   | arquivo que não abre | `CouldNotSendNotification` | `ZapmizerConnectException` (`unreadableFile`) |

   - Para pegar tudo, `catch (ZapmizerException $e)`; todas as classes acima são filhas dela. `catch (Exception)` continua pegando.
   - Quem lia o status do `getPrevious()` (`$e->getPrevious()->getResponse()->getStatusCode()`, ou um `BadResponseException` no `previous`) passa a ler `$e->status()` de `ZapmizerApiException`: o `previous` não é mais a resposta do Guzzle, e quem gravava `HTTP <status>` a partir dele passa a não achar nada.
   - Num teste, `andThrow(CouldNotSendNotification::zapmizerBotTokenNotProvided('...'))` vira `andThrow(ZapmizerUnauthorizedException::withoutResponse('...'))`, e uma falha de rede, `andThrow(new ZapmizerUnavailableException('...'))`.
4. **`setHttpClient()` e `httpClient()` saíram**, e o 2º argumento de `new Zapmizer()` passa de `GuzzleHttp\Client` a `Contracts\Transport` (veja "Transporte"). O envio sai pelo `Http` do Laravel. Para timeouts próprios num envio, no lugar de `->setHttpClient(new Client(['timeout' => 10, 'connect_timeout' => 5]))`:
   ```php
   app(Zapmizer::class, ['timeout' => 10, 'connect_timeout' => 5, 'api_version' => '2025-06-27']);
   ```
   (desde a 2.0.0-beta.4; antes, só montando `new Zapmizer($token, new LaravelHttpTransport(5, 10), $baseUri, $version)` com a config repetida). Para todos os envios, `ZAPMIZER_HTTP_TIMEOUT` e `ZAPMIZER_HTTP_CONNECT_TIMEOUT`. Um `handler` copiado do `GuzzleHttp\Client` do container (para o histórico dos testes) não tem mais efeito.
5. **Testes.**
   - **A forma recomendada é o `Zapmizer::fake()`** (desde a 2.0.0-beta.5), no lugar do `MockHandler` e do mock do `Zapmizer`: `use NotificationChannels\Zapmizer\Facades\Zapmizer;`, `Zapmizer::fake();` e, depois do código testado, `Zapmizer::assertSent(fn (FakeSend $send) => $send->to === '5511999999999')`. Ele pega todo envio da lib, inclusive o do número de um model conectado, sem requisição nenhuma; `failWithApiError(409, [...])` ou `failWith($exception)` testam os erros. Veja "Testes: `Zapmizer::fake()`" acima e `docs/envio.md`.
   - Um `MockHandler` num `GuzzleHttp\Client` registrado no container **não pega mais o envio**: a requisição sai de verdade (ou cai no `preventStrayRequests`). Use o `Zapmizer::fake()`, ou `Http::fake()` e `Http::assertSent()` para provar a requisição, com corpo JSON na resposta (um `Http::fake()` vazio responde 200 sem corpo, e o envio lança `unexpectedResponse`). Para guardar o histórico como o `Middleware::history`, um fake com closure: `Http::fake(['*/api/messages' => function (Request $request, array $options) use ($history) { $history[] = [$request->toPsrRequest(), $options]; return Http::response(['id' => '1'], 201); }])`.
   - Um mock de `Zapmizer::sendMessage()` **sem `andReturn()`** quebra, porque o `SentMessage` é `final` e o Mockery não fabrica o retorno; um `andReturn(new Response(...))` dá `TypeError`. Devolva `->andReturn(SentMessage::fake())` (ou `SentMessage::fromArray($corpo)` para um corpo exato). Veja "Testes" em `docs/envio.md`.
   - Os timeouts chegam às opções como `float` (`10.0`): compare com `assertEquals`/`toEqual`, não `assertSame`/`toBe`.
6. **Config e env de nomes genéricos.** A config da lib cresceu, e o merge dela com a `config/zapmizer.php` do app é raso: chaves que o app criou ali para uso próprio passam a ser lidas pela lib. Confira antes de subir:
   - `zapmizer.webhook.secret` (`ZAPMIZER_WEBHOOK_SECRET`) é o segredo que assina o webhook da lib, com o grupo webhook ligado. Um app que usa essa chave para outro fim (um webhook próprio) renomeia a dele antes de ligar o grupo.
   - `zapmizer.http.timeout` e `zapmizer.http.connect_timeout` passam a valer no envio de texto e nas chamadas de parceiro e de instância. Um app que tinha `http` na config com valores pensados para outra coisa (120 s, por exemplo) muda o timeout do envio sem perceber.
   - `ZAPMIZER_INBOX_ENABLED`: na 2.0.0-beta.1 a beta.3 ligava as rotas da caixa da lib; desde a 2.0.0-beta.4 não liga nada (a chave é `ZAPMIZER_ROUTES_INBOX`), e o app pode seguir usando o nome para o que quiser. O `zapmizer:check` avisa quando ela está no `.env`, para quem esperava a caixa da lib.
7. **`ZAPMIZER_API_VERSION` muda o formato das respostas.** Na 0.0.x o provider não lia a config: o `api-version` só ia quando o app passava `app(Zapmizer::class, ['api_version' => ...])`. Desde a 0.1 o envio (`Zapmizer`), o `VerificationClient` e o `InstanceClient` mandam `zapmizer.api_version` (`ZAPMIZER_API_VERSION`, `config/zapmizer.php:16`; o `PartnerClient` não manda, veja "Parceiro"), e o `zapmizer:install` grava `2025-06-27` quando a chave falta. Com ela, o número por QR responde o envio com o objeto na raiz e `links.self` em `sent-messages/{id}`; sem ela, no formato antigo, dentro de `data`, com `links.self` em `/messages/{id}`. O `SentMessage` lê os dois, mas código que lê o corpo à mão (`data.id`, `data_get($corpo, 'data.id')`) ou consulta `/messages/{id}` à mão com a versão da config muda de formato. Um `ZAPMIZER_API_VERSION` que já estava no `.env` (ignorado pela 0.0.x) passa a valer. Não ligue sem conferir essas leituras; para acompanhar o envio, use `Zapmizer::track()` (veja `docs/envio.md`).
8. **Envio estrito (desde a 0.1.1).** O envio manda `Accept: application/json` e não segue redirect (`ZapmizerApi.php:32`, `LaravelHttpTransport.php:45`). Antes da 0.1.1, um token revogado fazia a Zapmizer redirecionar para o login, o Guzzle seguia, e a página HTML voltava 200: o envio "dava certo" e a mensagem se perdia. Agora um redirect, ou uma 2xx que não é JSON, lança `ZapmizerConnectException` (`unexpectedResponse`). Um envio que parecia funcionar pode passar a falhar alto: é o token.
9. **`base_uri` sem esquema não envia.** `localhost:8001/api/` (ou qualquer `host:porta` sem `http://`) lança `ZapmizerConnectException::invalidBaseUri()` antes de enviar (`ZapmizerApi.php:28-29`); antes o Guzzle completava com `http`. Ponha o esquema: `ZAPMIZER_BASE_URI=http://localhost:8001/api/`.

## Upgrade da 0.3 e da 0.4

A 0.4 só acrescentou o `syncMessages()` à 0.3, que segue igual aqui: a lista vale para as duas.

**Antes de tudo, ligue as rotas que o app usa** (desde a 2.0.0-beta.4 nenhuma vem ligada; veja "Rotas por grupo"): `ZAPMIZER_ROUTES_CONNECT=true` (connect e reconnect), `ZAPMIZER_ROUTES_WEBHOOK=true` (o webhook assinado, inclusive o por conexão) e, se o app manda o usuário para `zapmizer.verify_number`, `ZAPMIZER_ROUTES_VERIFY_NUMBER=true`. Sem isso o connect responde 404, e as entregas do webhook também (a Zapmizer tenta de novo e desiste: as mensagens se perdem). Código do app que monta a URL da lib (`route('zapmizer.webhook')`, `route('zapmizer.connect.callback')`, num `start()` ou `createSession()` próprio, num comando que cadastra o webhook) passa a lançar `Symfony\Component\Routing\Exception\RouteNotFoundException` com o grupo desligado. Uma `config/zapmizer.php` publicada não precisa de edição para isso.

1. **Zapmizer 1.148.10 ou superior.** O contrato que esta versão usa está todo nela (`user_id`/`team_id` no `connect/token`, reconnect, revogação e `partner/users` desde a 1.148.8; `appearance` da caixa e `user.email` do embed desde a 1.148.10). A 1.149.0 e a 1.150.0 citadas nas versões anteriores nunca foram lançadas.
2. **Procure `CouldNotSendNotification` no app.** A classe saiu, e um `catch` dela não dá erro: só deixa de casar, e a exceção escapa. Troque por `catch (ZapmizerException $e)` ou pelas classes de "Envio" abaixo.
3. **Cliente montado à mão:** o argumento do `GuzzleHttp\Client` em `new PartnerClient`, `new InstanceClient` e `new Zapmizer` vira `null` (ou um `Contracts\Transport`), e `Zapmizer::setHttpClient()` saiu. Quem resolve pelo container não muda. Veja "Transporte".
4. **Testes:** `MockHandler` num `GuzzleHttp\Client` não pega mais parceiro, instância nem envio. Para o envio, use o `Zapmizer::fake()` (veja "Testes: `Zapmizer::fake()`"); para parceiro e instância, `Http::fake()` e `Http::assertSent()`.
5. **`config/zapmizer.php` publicada:** o merge da config é raso, então as chaves novas não chegam sozinhas. Acrescente `connect.revoke_previous_token` (e ligue: `ZAPMIZER_CONNECT_REVOKE_PREVIOUS_TOKEN=true`), `webhook.per_connection` se for usar e `http.transport` se for trocar de transporte, e suba `connect.state_ttl_minutes` de 10 para 30 (ou apague a chave). Ou republique a config.
6. **Ao ligar `revoke_previous_token`, tire o observer `updating`** que a doc anterior ensinava para revogar o token anterior.
7. **`Contracts\Connectable` sem o trait:** implemente `zapmizerExternalId()`.
8. **Stubs e views publicados:** republique os stubs com `php artisan vendor:publish --tag=zapmizer-wizard --force` (guarde antes as suas edições: o `--force` sobrescreve); quem montava o reconnect com a view `connect-result` passa a ter a `reconnect-result`.
9. **Handler de exceções:** `ZapmizerUnavailableException`, `PartnerCredentialsException` e `NoConnectableException` não têm mais `render()`: as rotas da lib as tratam nos próprios métodos, e em todo o resto (código do app e método sobrescrito numa subclasse dos controllers da lib, veja o item 11) elas viram 500. Use `renderable()`.
10. **`base_uri` sem esquema** (`localhost:8001/api/`): ponha o esquema (`http://localhost:8001/api/`), senão nada sai.
11. **Código que usa `PartnerClient`, `ConnectToken`, `ConnectSession` ou `InstanceClient` direto, ou estende classes da lib:** confira as seções "Parceiro", "Instância", "Connect" e "Embed", e as notas de subclasses em cada uma. Quem **estende** as classes cai nestes casos, que não aparecem só lendo o de/para:
    - **Subclasse de `PartnerClient` ou `InstanceClient` com construtor que recebe `?GuzzleHttp\Client`** e repassa ao pai, registrada no container com `$app->make(HttpClient::class)` (o padrão da 0.x). Ao resolver (o `start` do connect, o `$connection->instanceClient()`, o `route:list`): `TypeError: PartnerClient::__construct(): Argument #3 ($transport) must be of type ?NotificationChannels\Zapmizer\Contracts\Transport, GuzzleHttp\Client given` (no `InstanceClient`, o argumento #2). Troque o tipo por `?Contracts\Transport` e passe `null` (a lib resolve o `Transport` do container), ou apague o construtor e o binding, se só existiam para isso.
    - **`createSession()` sobrescrito com a assinatura antiga** (`string $redirectUri, string $state, ?string $webhookUrl = null, ?int $expiresIn = null`) é **erro fatal ao carregar a classe**, não uma exceção: `Declaration of App\...::createSession(...) must be compatible with PartnerClient::createSession(...)`. Nenhum `catch` pega, e cai tudo que carrega a subclasse, não só o connect: o `route:list` (que instancia os controllers para ler o middleware, e com eles o `PartnerClient` do container) e o processo do PHPUnit no primeiro teste que a resolve, levando a suíte inteira. A assinatura nova, completa:

      ```php
      public function createSession(
          string $redirectUri,
          ?string $state = null,
          ?string $webhookUrl = null,
          ?int $expiresIn = null,
          ?string $externalId = null,
          ?string $webhookVersion = null,
      ): ConnectSession
      ```

      Repasse todos ao `parent::createSession()`: o `start` da lib manda `externalId` e o `webhookVersion` vem da config.
    - **Subclasse do `ConnectController` (ou teste) que monta `new ConnectToken(token: …, teamId: …)`**: `ArgumentCountError: ConnectToken::__construct(): Argument #2 ($userId) not passed`. Num `storeToken()` sobrescrito, ou num `exchangeCode()` falso, o `callback` da lib pega o erro: o popup mostra só `exchange_failed`, nada é gravado, e o erro só aparece no `report()`. Passe `userId` (o `user_id` do `connect/token`).
    - **`start()` sobrescrito numa subclasse do `ConnectController`** perde o 503: as exceções não têm mais `render()` (item 9), e isto vale **dentro** das rotas da lib, porque quem responde é o seu `start()`. Zapmizer fora do ar (`ZapmizerUnavailableException`), chave de parceiro recusada (`PartnerCredentialsException`, antes 503 `partner_unauthorized`), um 4xx (`ZapmizerApiException`, antes `ZapmizerUnavailableException`) e o resolver sem model (`NoConnectableException`, antes 403 `no_connectable`) viram 500. Prefira não sobrescrever: o `start()` da lib já manda `external_id` (do `zapmizerExternalId()`), o TTL e o webhook, e os ganchos `webhookUrl()` e `webhookKeyFor()` mudam a URL. Se precisar, repita os `catch` do `start()` da lib (`src/Http/Controllers/ConnectController.php`), nesta ordem, porque as primeiras estendem as de depois: `PartnerCredentialsException` → 503 `partner_unauthorized`; `ZapmizerUnavailableException` e `ZapmizerApiException` → 503 `zapmizer_unavailable`; `NoConnectableException` → 403 `no_connectable`; `ZapmizerConnectException` → 503 `zapmizer_unavailable`. Um `route('zapmizer.webhook')` nele lança `RouteNotFoundException` sem `ZAPMIZER_ROUTES_WEBHOOK=true`.
    - **`result()` próprio com mapa de mensagens fechado** (`$messages[$status]`): o callback da lib passa a mandar `subscription_required` e `rate_limited`, que a 0.3 e a 0.4 não tinham, e o mapa estoura com `Undefined array key` (500 no popup, e o opener fica esperando). Todos os `status` que o `result()` recebe da lib: `ok`, `denied`, `plan_limit`, `subscription_required`, `invalid_state`, `exchange_failed`, `rate_limited`, `webhook_failed`, `team_already_connected` e `no_connectable` (`qr_unavailable` saiu). Deixe a lib responder o que o seu mapa não tem:

      ```php
      protected function result(string $status): View
      {
          $messages = [
              'ok' => 'Número conectado.',
              'denied' => 'Autorização cancelada.',
              // ...
          ];

          if (!isset($messages[$status])) {
              return parent::result($status);
          }

          return view('zapmizer::connect-result', ['status' => $status, 'message' => $messages[$status]]);
      }
      ```

      O `result()` da lib responde qualquer `status`: um que ela não conhece vai com o próprio nome e a mensagem de `exchange_failed` (veja "Connect").
    - **Id externo próprio.** Quem mandava um `external_id` por conta própria (uma subclasse do `PartnerClient` ou do `ConnectController` que o punha no corpo) e passa a usar o connect da lib: o `start` manda `$connectable->zapmizerExternalId()`, que no trait `Connectable` é `(string) $this->getKey()` (`src/Connectable.php`). Se o id que a Zapmizer já conhece é outro, sobrescreva o método no model para devolver o mesmo de antes; senão o vínculo com o `external_id` antigo se perde em silêncio: o `subscription()` responde `null` (404) e o checkout nasce para um cliente novo. O id precisa ter de 1 a 191 caracteres entre `A-Z`, `a-z`, `0-9`, `_`, `.` e `-` (e não ser `.` nem `..`); fora disso, `InvalidArgumentException` antes de chamar a Zapmizer.
    - **`api-version` no parceiro.** Uma subclasse do `PartnerClient` que só existe para mandar `api-version` pode sair: as rotas de parceiro não leem o header (veja "Parceiro").
12. **Quem usa o retorno do envio** (`sendMessage()`, `sendMessageWithFile()`, `ZapmizerMessage::send()` ou um listener de `NotificationSent`): ele passa a ser um `Messages\SentMessage`. Troque `getBody()` por `$sent->raw()` ou pelos campos. Num listener, `$event->response['message'] instanceof ZapmizerMessage` falha em silêncio: troque por `instanceof SentMessage`. Uma subclasse de `ZapmizerMessage` que sobrescreve `send()` precisa declarar `: SentMessage`. Configure `ZAPMIZER_API_VERSION=2025-06-27` para acompanhar o envio. Veja "Envio tipado, acompanhamento e template" em "Envio".
13. **A mensagem é o contrato `2026-10-20`.** `InboundMessage` saiu. `MessageReceived`, o novo `MessageSent`, `syncMessages()` e o `media()`/`awaitMedia()` da `ZapmizerConnection` usam `Messages\Message` (o `InstanceClient::media(int $botInstanceId, string $messageId, int $timestamp)` segue com os escalares), com a forma do contrato de mensagem da Zapmizer, nos dois formatos de webhook. Troque o tipo nos listeners, jobs e testes e use o de/para abaixo. Veja "Mensagens".
14. **O `id` da mensagem muda para as mesmas mensagens.** Era o `id._serialized` do WhatsApp Web (`true_5521…@c.us_3AAA…`); agora é o id curto do WhatsApp (`3AAA…`), o mesmo que o contrato entrega e que o `GET /whatsapp-messages/media` aceita. Ele pode se repetir entre conversas: deduplique por `chat` + `id`. Um id guardado antes não casa com o novo: na transição, compare com o terceiro segmento do `_serialized` antigo separado por `_` (`<fromMe>_<conversa>_<id>`, e em grupo `_<participante>` no fim), a mesma regra com que a Zapmizer lê o `_serialized` no `GET /whatsapp-messages/media`.
15. **Fila:** um `MessageReceived` (ou um job seu com `InboundMessage`) serializado antes do deploy não volta: esvazie as filas desses listeners e jobs antes de atualizar.
16. **Webhooks e versão.** O connect e o `createWebhook()` criam o webhook em `2026-10-20`, e o `syncMessages()` pede `api-version: 2026-10-20`, qualquer que seja a config. Um webhook em `2024-02-27` continua funcionando, traduzido, e o single-tenant pode cadastrar o webhook à mão em qualquer das duas versões (a recomendada é `2026-10-20`, a que a tela sugere). `ZAPMIZER_API_VERSION` volta a valer só para o envio e as rotas de instância que não leem mensagem (as de parceiro não recebem `api-version`): `2025-06-27` para acompanhar o envio, ou `2026-10-20` a partir do Zapmizer 1.148.17 (antes dele, o envio só conhece `2025-06-27`). Acrescente `connect.webhook_version` a uma `config/zapmizer.php` publicada se quiser outra versão (sem a chave, vale `2026-10-20`).
17. **`handleMessage()` sobrescrito e filtros por `name`.** Uma subclasse do `WebhookController` com `handleMessage()` próprio só o vê rodar em webhook `2024-02-27`: em `2026-10-20` (o que o connect e o `createWebhook()` criam agora) a mensagem chega como `message_received`/`message_sent`, em `handleMessageReceived()`/`handleMessageSent()`. Sobrescreva esses também, ou ouça `MessageReceived`/`MessageSent`; a lib registra um `Log::warning` quando um `handleMessage()` sobrescrito fica de fora. Um listener de `WebhookReceived` que filtra `$payload['name'] === 'message'` precisa incluir `message_received` e `message_sent`.

De/para do `InboundMessage` para `Messages\Message`:

| `InboundMessage` | `Message` |
| --- | --- |
| `id` (`id._serialized`) | `id` (id curto do WhatsApp); deduplique por `chat` + `id` |
| `from` | `chat` (a conversa: o contato, o grupo ou `...@lid`) |
| `fromPhone` | `chatPhone()` (`null` em grupo, `@lid`, transmissão e canal; antes era `''` ou os dígitos do grupo) |
| `to` (o número que recebeu, do `X-Wid`) | `$event->envelope->wid`, `$event->connection`, `SyncedMessages::$wid` |
| `author` / `authorPhone` | `sender` / `senderPhone()`; fora de grupo, `sender` é o contato (ou o próprio número quando `fromMe`) |
| `body` | `text` (o texto, a legenda, a pergunta da enquete ou a opção escolhida) |
| `type` (`chat`, `ptt`, `vcard`, `multi_vcard`, `poll_creation`, `buttons_response`, `list_response`...) | `type` (`text`, `voice`, `contact`, `contact`, `poll`, `button_reply`, `list_reply`...; `Messages\MessageType`); o que o contrato não cobre vem `unknown`, com o original em `raw` |
| `fromMe` | `fromMe` (verdadeiro no `MessageSent` e no sync); `fromMeOtherDevice` é novo |
| `hasMedia` | `hasMedia()` |
| `mediaMetadata`, `mediaMimeType()` | `media->mime` (e `size`, `duration`, `width`, `height`, `pageCount`, `isGif`) |
| `mediaFilename()` | `media->filename` |
| `mediaMetadata['caption']` | `text` |
| `mediaMetadata` (`media_key`, `direct_path`, `filehash`...) | sem equivalente; o original segue em `$event->payload` (só no webhook `2024-02-27`) |
| `isGroup` / `isBroadcast` (propriedades) | `isGroup()` / `isBroadcast()` |
| `hasUnresolvedSender` | `chat` ou `sender` terminando em `@lid` (`chatPhone()`/`senderPhone()` `null`). A lib não procura mais o número em `_data.from`: segue a Zapmizer, que mantém o `@lid` |
| `sentAt` (propriedade `Carbon`) | `sentAt()` (`CarbonImmutable`) e `timestamp` (segundos) |
| `raw` (o objeto do WhatsApp Web) | `$event->payload['data'][0]` no `2024-02-27`; `raw` do `Message` só existe em `unknown` |
| `InboundMessage::fromEnvelope($payload, $wid)` / `fromMessage($message, $wid)` | `Message::fromLegacy($payload['data'][0])` / `Message::fromLegacy($message)`; o contrato, `Message::fromContract($data)` |
| aviso do WhatsApp (entrada em grupo, mensagem apagada, aviso de criptografia...) virava `MessageReceived` | não dispara nada, como no contrato |
| `message` sem `id` era ignorado em silêncio | dispara `MessageReceived` com `type` `unknown` e o original em `raw` |

## Instalação com um agente

Um agente de código instala o connect e a caixa de entrada com um prompt. Roteiro em `docs/instalar.md`; o README abre com ele.

- **`php artisan zapmizer:install`**, idempotente e seguro com `--no-interaction`: `--base-uri=` grava a `ZAPMIZER_BASE_URI` (que decide a marca do embed) quando ela falta, e nunca sobrescreve uma já presente (um valor diferente faz o comando falhar), como as credenciais; publica `config/zapmizer.php` e a migration da `zapmizer_connections` (uma vez; `--force` sobrescreve no lugar, sem segunda cópia), acrescenta só as chaves ausentes ao `.env` e ao `.env.example` (`ZAPMIZER_BASE_URI`, `ZAPMIZER_API_VERSION=2025-06-27`, `ZAPMIZER_PARTNER_ID` e `ZAPMIZER_PARTNER_SECRET` vazios), e imprime os próximos passos. `--model=App\Models\User` (ou `Team`) põe `implements Connectable` e `use ConnectsZapmizer;` no model; um arquivo de formato que ele não reconhece fica intacto, e o trecho para colar sai no aviso. `--inbox` liga a caixa (`ZAPMIZER_INBOX_ENABLED=true`) e copia os componentes Vue para `resources/js/zapmizer/` quando o `package.json` tem `vue`. `--migrate` roda as migrations. Sem a flag e com interação, ele pergunta.
- **`php artisan zapmizer:check`**: APP_KEY, `base_uri` (terminando em `/api`), credenciais de parceiro, a chave aceita pela Zapmizer (`GET /api/partner/users/zapmizer-check`, só leitura: 401 recusa; aceita só com 200 ou o 404 `Record not found.` de cliente desconhecido, e um 404 de rota falha), o `vue` >= 3.3 (aviso), a tabela, o model `Connectable` do resolver, as rotas do connect e da caixa, o `@zapmizer/embed` com tag fixa no `package.json` (aviso numa tag anterior à v0.1.3, a que traz os tipos para `moduleResolution: node`) e o `APP_URL`. Sai com código ≠ 0 quando algo crítico falha; sem rede (ou com `--offline`) pula a checagem remota com aviso; `--json` para agentes. Desde a 2.0.0-beta.5 confere também o envio (`api_token`, `send_api`, `from_number`) e só os grupos ligados (veja "`zapmizer:check` em app que só envia").
- **Caixa de entrada pronta, opt-in** (`zapmizer.inbox.enabled`, desligada por padrão): `POST zapmizer.inbox.session` (`throttle:30,1`) e `GET zapmizer.inbox.keepalive` (204), atrás de `zapmizer.inbox.middleware` (`web` e `auth`). A sessão abre para a conexão do model que o `zapmizer.connect.resolver` devolve, com o usuário logado como atendente (`user.id` e `user.name`, cortado em 100 caracteres), `parent_origin` da requisição ou de `ZAPMIZER_INBOX_PARENT_ORIGIN`, e responde pela `EmbedSessionResponse`. Códigos da lib: `409 not_connected` e `403 no_connectable` (também para um resolver que devolve model sem `Connectable`). O `403 missing_ability` e o `403 not_a_partner_connection` da Zapmizer (conexões anteriores à caixa) e um token que não decifra viram `422 reauth_required`, que o front mostra com o botão de conectar (`Http\\Responses\\InboxSessionResponse`). Uma `config/zapmizer.php` publicada recebe a chave `inbox` sozinha (o merge é raso, mas a chave é de primeiro nível).
- **Componentes:** num tag próprio, `zapmizer-inbox` (o `zapmizer-wizard` continua só com o connect, sem depender do `@zapmizer/embed`), `InboxScreen.vue` (a caixa com `@zapmizer/embed/vue`; sem conexão, ou com a conexão pedindo reconnect, mostra o `ConnectButton`), `zapmizerEmbed.ts` (`openSession`/`keepAlive` com o CSRF do Laravel, `listenToZapmizerLogout()` e `endZapmizerEmbeds()`) e `zapmizerRoute.ts`. Os componentes do connect passam a usar o `zapmizerRoute()`, que usa o `route()` do Ziggy quando existe e cai nos caminhos com o prefixo `/zapmizer` quando não: republique com `--force` para ganhar isso.
- **`person` da caixa vem do servidor** (desde a 2.0.0-beta.3): o `GET zapmizer.connect.show` responde `{connection, person}`, com `person` = `{usuário}:{model}:{id}` (o `getAuthIdentifier()` do usuário logado e o model do resolver). O `InboxScreen` usa esse valor, e a prop `person` vira opcional (só para sobrescrever): o app não precisa mais adivinhar a forma do usuário compartilhado (`auth.user.id`, `user_id`...). O caminho Blade do guia também lê o `person` do `show`.
- **Os textos do connect seguem a marca** (desde a 2.0.0-beta.3): `zapmizerBrand.ts` (no tag `zapmizer-wizard`) lê o `VITE_ZAPMIZER_BRAND` e dá o nome com o artigo ("Conectar com a Parli", "Conectar com o Zapmizer"); o `ConnectButton` e o `ConnectionPanel` não dizem mais "Zapmizer" fixo, e o `zapmizerEmbed.ts` reexporta o `ZAPMIZER_BRAND` de lá. No PHP, `Support\EmbedBrand::name()` dá o nome da marca para o Blade.
- **Os componentes numa pasta só, `resources/js/zapmizer/`** (desde a 2.0.0-beta.3): os tags `zapmizer-wizard` e `zapmizer-inbox` e o `zapmizer:install --inbox` copiam tudo para lá (`ConnectButton.vue`, `ConnectionPanel.vue`, `InboxScreen.vue`, `useZapmizerIntegration.ts`, `zapmizerBrand.ts`, `zapmizerEmbed.ts`, `zapmizerRoute.ts`, `types.ts`), e os arquivos se importam entre si por caminho relativo, sem `@/`. Assim compilam com qualquer alias do app (`@`, `~` ou nenhum) e não colidem com as pastas `Components/`/`components/` dele (no macOS, sem distinção de maiúsculas, colidiam). Até a 2.0.0-beta.2 iam para `resources/js/components/zapmizer`, `resources/js/composables` e `resources/js/types`: quem republicar (`--force`) ganha os arquivos no lugar novo e os antigos ficam onde estavam; troque os imports do app para `resources/js/zapmizer/...` e apague os antigos.
- **Marca do embed:** `zapmizer.inbox.brand` (`ZAPMIZER_EMBED_BRAND`); vazia, `Support\EmbedBrand::resolve()` deriva do host da `base_uri` (`parli` em `parlichat.com`, senão `zapmizer`). O iframe só manda eventos com `source: "<marca>-embed"`, cada deploy com a sua. O `zapmizerEmbed.ts` lê a marca de `VITE_ZAPMIZER_BRAND` (padrão `zapmizer`), que o `zapmizer:install` grava com o valor derivado, e o `zapmizer:check` falha (`embed_brand`) com a caixa ligada quando ela falta no `.env` ou diverge da config. Num host fora dos deploys conhecidos (`app.zapmizer.com`, `*.parlichat.com`), o install e o check leem a marca do próprio deploy em `/brand/manifest.json` (o `name` do manifest do PWA, que o servidor gera do mesmo `VITE_BRAND` do iframe); o install grava também `ZAPMIZER_EMBED_BRAND`, e sem resposta manda confirmar a marca com o humano, que o check também avisa (desde a 2.0.0-beta.3).
- **Agentes:** `llms.txt` na raiz, e diretriz e skill do Laravel Boost em `resources/boost/` (`guidelines/core.md` e `skills/zapmizer-whatsapp/SKILL.md`). O Boost só as carrega com o pacote escolhido no `boost:install` interativo, ou listado em `packages` no `boost.json` seguido de `boost:update`.

## Transporte

**Breaking** para quem constrói `PartnerClient`, `InstanceClient` ou `Zapmizer` à mão: o argumento do `GuzzleHttp\Client` virou o do transporte. Quem resolve os clientes pelo container (`app(PartnerClient::class)`, `$connection->instanceClient()`, `app(Zapmizer::class)`) não muda a construção.

- `PartnerClient`, `InstanceClient` e `Zapmizer` (o envio) mandam as requisições pelo `Http` do Laravel (`Connect\Transports\LaravelHttpTransport`), padrão e único transporte da lib: não há transporte Guzzle (o `GuzzleTransport` das versões de desenvolvimento deste ciclo saiu). Para trocar, `zapmizer.http.transport` nomeia uma classe que implementa `Contracts\Transport`. Veja `docs/transporte.md`.
- `zapmizer.http.connect_timeout` e `zapmizer.http.timeout` (`ZAPMIZER_HTTP_CONNECT_TIMEOUT`, `ZAPMIZER_HTTP_TIMEOUT`), `null` por padrão. Sem valor, vale o padrão do `Http` da versão: nenhum no Laravel 8; 30 s de total e 10 s de conexão no 9.52 e do 10 em diante. Valem nas chamadas JSON de parceiro e instância e no envio de texto: um app que já tinha essas chaves na config passa a aplicá-las ali.
- Quem publicou `config/zapmizer.php` com a chave `http` e quer trocar de transporte precisa acrescentar `'transport' => ...` dentro dela: o merge da config é só no primeiro nível.
- `illuminate/http` declarado como dependência; `ext-curl` sugerida.
- O `getPrevious()` da `ZapmizerUnavailableException` pode mudar de classe conforme a versão do Laravel: uma exceção do `Http` do Laravel (`ConnectionException` etc.) ou, do Laravel 8 ao 11 em alguns casos (corpo cortado), a do Guzzle.
- Um header passado nas opções da chamada (o `headers` de quem chama o `request()` numa subclasse) com o nome de um header que a lib fixa naquela chamada, em qualquer caixa, é descartado; vale o valor fixo, em vez de concatenar. A lib fixa `Accept` em toda chamada de parceiro, instância e envio; `X-Partner-Key` no `PartnerClient`; `Authorization` no `InstanceClient` e no envio; e `api-version` no `InstanceClient` e no envio só quando há versão (a da config, ou a que a rota fixa, como no `syncMessages()`). O `PartnerClient` não fixa `api-version`: um `api-version` que uma subclasse dele passa segue como veio (e a Zapmizer o ignora nas rotas de parceiro). Com `zapmizer.api_version` vazia, o mesmo vale para o `api-version` passado ao `InstanceClient` ou ao envio.
- `Http::fake()`, `Http::preventStrayRequests()`, os listeners de `RequestSending`/`ResponseReceived` e o Telescope passam a ver as chamadas de parceiro, instância e envio.
- Proxy sem `Http::globalOptions` (Laravel 8, 9 e 10 antes do 10.48) pede um transporte próprio (exemplo em `docs/transporte.md`).

Testes:

- Fake com `MockHandler` num `GuzzleHttp\Client` registrado no container deixa de pegar parceiro, instância e envio: troque por `Http::fake()` e `Http::assertSent()`. Esse `GuzzleHttp\Client` passa a valer só para o `VerificationClient`.
- Um `Http::fake()` sem resposta para a URL da Zapmizer responde 200 vazio: as chamadas que esperam JSON (`createSession`, `exchangeCode`, `subscription`, `checkout`, `connection`, `createWebhook`, `rotateWebhookSecret`, `reconnect`, `conversationSession`, `inboxSession`) e o `revokeToken()` (só 204 e 401 contam como revogado) falham com `unexpectedResponse`, mas `deleteWebhook()` passa em silêncio e `media()` devolve um download anexado vazio (`size === 0`). O envio também falha (veja "Envio").

De/para da construção:

- `new PartnerClient($id, $secret, $guzzle, $uri)` → `new PartnerClient($id, $secret, null, $uri)` (o transporte vem do container, com a config) ou com um `Contracts\Transport` no lugar do `null`.
- `new InstanceClient($token, $guzzle, $uri, $version)` → `new InstanceClient($token, null, $uri, $version)`, idem.
- `new Zapmizer($token, $guzzle, $uri, $version)`: veja "Envio".
- Um `GuzzleHttp\Client` nessa posição agora lança `TypeError`. Sem transporte, o cliente resolve `Contracts\Transport` no container: com o provider da lib registrado (sem ele, `BindingResolutionException`), e uma `zapmizer.http.transport` inválida falha já no construtor.

Subclasses: a propriedade `$http` não existe mais (as chamadas passam pelo `$transport`). `InstanceClient::spool()` continua com a assinatura da 0.3, mas rebobina o corpo e não o fecha.

## Erros

> **A quebra é silenciosa.** `CouldNotSendNotification` não existe mais, e em PHP um `catch (CouldNotSendNotification $e)` de uma classe que não existe não dá erro: só nunca casa, e a exceção volta a escapar. Procure esse nome no app antes de atualizar. O envio lança as mesmas exceções dos clientes Connect; o de/para está em "Envio".

- `Exceptions\ZapmizerException` (abstrata) é a raiz de todas as exceções da lib: `catch (ZapmizerException $e)` pega as do connect, do envio e do verify-number.
- `Exceptions\ZapmizerApiException`, filha de `ZapmizerConnectException`: a Zapmizer recusou a chamada (4xx). `status()`, `error()` (o código da API, ou `null`), `reason()`, `errors()`, `payload()` e `apiError()`. Mensagem: `Zapmizer refused the request (HTTP {status}[, {error}])[: {reason}].`
- `reason()` não repete o primeiro erro de campo quando o `message` já o traz (desde a 2.0.0-beta.4): o 422 do Laravel põe o primeiro erro no `message`, e a mensagem saía como `The to field is invalid. invalid.`.
- `ZapmizerApiException::context()`: o `report()` do Laravel loga `zapmizer_status`, `zapmizer_error` e, na `ZapmizerRateLimitedException`, `zapmizer_retry_after` (veja `docs/erros.md`).
- `Exceptions\ZapmizerRateLimitedException` (429), com `retryAfter(): ?int` lido do `Retry-After` em segundos; data HTTP, negativo ou texto dão `null`.
- `Exceptions\ErrorCode`: uma constante por código `error` do contrato da API (`ErrorCode::WINDOW_CLOSED`, `ErrorCode::BOT_OFFLINE`, ...). `ErrorCode::UNKNOWN_API_VERSION` (400 de uma `api-version` que a Zapmizer não conhece em `GET /whatsapp-messages` e `POST /webhooks`) entrou com o snapshot do contrato de 16/11. `error()` devolve string: um código novo chega ao app sem release da lib.
- `Support\ApiError` lê a resposta de erro nos quatro formatos do contrato; `Connect\ZapmizerApi::failure()` escolhe a exceção pelo status (401 → `ZapmizerUnauthorizedException`, 429 → `ZapmizerRateLimitedException`, outro 4xx → `ZapmizerApiException`).
- `ZapmizerUnauthorizedException`, `PartnerCredentialsException`, `InstanceGoneException` e `MediaRejectedException` passam a estender `ZapmizerApiException`; `MediaRateLimitedException` passa a estender `ZapmizerRateLimitedException`. Um `catch` de qualquer uma delas continua pegando.
- JSON com UTF-8 malformado no corpo (um texto cortado no meio de um caractere) lança o `InvalidArgumentException` do Guzzle como veio: é erro de quem chamou, não queda. `catch (ZapmizerException)` não pega esse caso.

Quebras:

- Um `catch (ZapmizerConnectException)` em volta de código que também envia passa a capturar falhas de envio (`ZapmizerApiException` e `ZapmizerUnavailableException` são filhas dela).
- `ZapmizerUnavailableException`, `PartnerCredentialsException` e `NoConnectableException` perdem o `render()`. Fora das rotas da lib (que respondem o mesmo JSON de hoje), quem chamava os clientes e contava com o 503/403 JSON automático passa a ter a resposta do handler do app; em troca, os callbacks `renderable` do app passam a valer para essas exceções.
- Quem chama `PartnerClient` fora do `ConnectController` perde o `render()` 503 `zapmizer_unavailable` nos 4xx que não são de credencial: `ZapmizerApiException` não se renderiza.
- `MediaRejectedException`, `MediaRateLimitedException`, `InstanceGoneException`, `ZapmizerUnauthorizedException` e `PartnerCredentialsException` mudam de construtor (recebem `Support\ApiError`); as fábricas `mediaRejected()`/`mediaRateLimited()` também.

Não quebra: `catch` de `ZapmizerConnectException`, `ZapmizerUnavailableException`, `ZapmizerUnauthorizedException`, `PartnerCredentialsException`, `InstanceGoneException`, `MediaRejectedException`, `MediaRateLimitedException`, `ZapmizerVerificationException` e filhas.

Subclasses de `ZapmizerApiException` ganham `context()`.

## Envio

`Zapmizer::sendMessage*` (e com eles o `ZapmizerMessage`, `send()` e `zapmizer:send-message`) passam pelo transporte, o `Http` do Laravel por padrão, e lançam as exceções dos clientes Connect.

| Antes | Agora |
| --- | --- |
| `CouldNotSendNotification` (4xx), `getPrevious()` `ClientException` | `ZapmizerApiException` (`status()`, `error()`, `reason()`, `errors()`); 401 → `ZapmizerUnauthorizedException`; 429 → `ZapmizerRateLimitedException` |
| `CouldNotSendNotification` (5xx, rede) | `ZapmizerUnavailableException` |
| `CouldNotSendNotification` (redirect, não-JSON) | `ZapmizerConnectException` (`unexpectedResponse`) |
| `CouldNotSendNotification` (sem token) | `ZapmizerUnauthorizedException` (`status()` 401, mesma mensagem) |
| `CouldNotSendNotification` (arquivo que não abre) | `ZapmizerConnectException` (`unreadableFile`) |
| `CouldNotSendNotification` (URL malformada, multipart inválido, exceção de transporte próprio ou de `preventStrayRequests`) | a exceção original, sem embrulho (não é `ZapmizerException`) |
| mensagem `` `{status} - {description}` `` | `Zapmizer refused the request (HTTP {status}[, {error}])[: {reason}].` |

- `catch (CouldNotSendNotification $e)` → `catch (ZapmizerException $e)` ou as classes da tabela. Quem lia o status do `getPrevious()` (`ClientException`) passa a usar `$e->status()` de `ZapmizerApiException`.
- `Http::fake` vê o envio, e os timeouts de `zapmizer.http.*` valem no envio de texto (sem valor, o padrão do `Http` da versão: nenhum no Laravel 8; 30 s no 9.52 e do 10 em diante).
- `Http::fake()` sem argumentos responde 200 sem corpo nem Content-Type, e o envio passa a lançar `unexpectedResponse`: fakeie com um corpo JSON.
- Upload de arquivo: limites próprios de 60 s para conectar e 600 s no total (`ZapmizerApi::TRANSFER_CONNECT_TIMEOUT`/`TRANSFER_TIMEOUT`, os mesmos da mídia), no lugar do timeout do `GuzzleHttp\Client` registrado.
- `Zapmizer`: o 2º argumento do construtor passa de `?GuzzleHttp\Client` a `?Contracts\Transport`; `setHttpClient()` e `httpClient()` saem. Para timeouts próprios num cliente, `app(Zapmizer::class, ['timeout' => 10, 'connect_timeout' => 5])`, ou um transporte inteiro com `['transport' => $transporte]` (desde a 2.0.0-beta.4; veja "Timeouts de um envio só" em `docs/transporte.md`). `new Zapmizer($token, $guzzle, $uri, $version)` → `new Zapmizer($token, null, $uri, $version)` (o transporte vem do container) ou com um `Contracts\Transport`. Sem o provider, `BindingResolutionException`. Um `zapmizer.http.transport` inválido passa a quebrar `new Zapmizer()` no construtor e `app(Zapmizer::class)`, e com eles o `ZapmizerMessage` (`send()`, `zapmizer:send-message`).
- Subclasses: `Zapmizer` ganhou `$transport` e `guardToken()`, que podem colidir com nomes de uma subclasse.

### Envio tipado, acompanhamento e template

**Breaking:** o envio devolve um DTO, e não mais a resposta HTTP.

| Antes | Agora |
| --- | --- |
| `Zapmizer::sendMessage()`/`sendMessageWithFile()` → `?ResponseInterface` | → `Messages\SentMessage` |
| `ZapmizerMessage::send()` → a própria mensagem (`$this`) | → `Messages\SentMessage` |
| `json_decode((string) $response->getBody(), true)` | `$sent->raw()` (o corpo decodificado, como veio) |
| `json_decode(...)['id']` | `$sent->id` (sempre `string`; o oficial respondia inteiro) |
| `json_decode(...)['links']['self']` | `$sent->selfUrl` |
| `json_decode(...)['wid_to']` | `$sent->widTo` |
| `$response->getStatusCode()` | sem equivalente: um envio que volta é 2xx; 4xx/5xx já lançam exceção |
| uma 2xx JSON que não é objeto (`[1, 2]`, `"ok"`, `null`, corpo vazio com `Content-Type` JSON) passava | `ZapmizerConnectException` (`unexpectedResponse`). `[]` continua aceito, como objeto vazio |
| listener de `NotificationSent`: `$event->response['message'] instanceof ZapmizerMessage` | `instanceof SentMessage`. O teste antigo **falha em silêncio**: o listener só deixa de rodar |
| listener: `$message->to` | `$sent->widTo` (o destino conferido pela Zapmizer, que pode diferir do `to`) |
| listener: `$message->params['metadata']['text']` | `$sent->raw()['metadata']['text']` (com `api-version`); `$sent->raw()['data']['metadata']['text']` (formato antigo) |
| subclasse de `ZapmizerMessage` com `send()` sem tipo de retorno | `send(): SentMessage`, senão erro fatal de assinatura incompatível |

- `Messages\SentMessage`: `id`, `selfUrl`, `widFrom`, `widTo`, `status`, `statusName`, `type`, `isTrackable()` e `raw()`. Todo campo pode vir `null`, porque o contrato não exige nenhum. No número oficial com fila, `id` e `selfUrl` vêm `null`. Sem `api-version` (formato antigo, número por QR), o corpo vem em `data` e é lido do mesmo jeito.
- **O canal envia o `ZapmizerMessage` que o `toZapmizer()` devolve sem `->send()`** (desde a 2.0.0-beta.4). Até a 2.0.0-beta.3 (e em toda a 0.x), essa mensagem não saía, sem erro, e a rota do notifiable era descartada. Agora o canal a envia, para o `to` dela ou, sem `to`, para o `routeNotificationFor('zapmizer')` do notifiable (sem os dois, nada sai), e o `NotificationSent` recebe o `SentMessage`. Não envia de novo um `SentMessage` nem uma mensagem que o `toZapmizer()` já enviou (`ZapmizerMessage::sentMessage()`, novo, diz o que o último `send()` respondeu). Quem devolvia o builder de propósito para não enviar passa a enviar: devolva `null`. Subclasses de `ZapmizerMessage` ganham a propriedade protegida `$sent` e o método `sentMessage()`, que podem colidir com os de uma subclasse.
- `SentMessage::fake(array $overrides = [])` (desde a 2.0.0-beta.4): um envio aceito para testes, com as chaves da resposta da API em `$overrides`. A classe é `final`, e um mock de `Zapmizer::sendMessage()` sem `andReturn()` quebra no envio: devolva `->andReturn(SentMessage::fake())`. Veja "Testes" em `docs/envio.md`.
- Numa notificação cujo `toZapmizer()` chama `->send()`, o `NotificationSent` passa a ter o `SentMessage` em `$event->response['message']`. O canal não muda.
- Acompanhamento:
  - `Zapmizer::track(SentMessage|string|null $sent)` segue o `links.self` pela `base_uri` configurada, nunca pelo host do link. Devolve `Messages\QrMessageStatus` (`GET /sent-messages/{id}`) ou `Messages\MetaMessageStatus` (`GET /meta-messages/{id}`). Devolve `null` quando não há o que seguir: `null`, envio oficial em fila ou `SentMessage` do formato antigo (`isTrackable()` é `false` nos dois últimos).
  - Também diretos: `sentMessage(string $id)` e `metaMessage(int|string $id)`.
  - Um link em string que não é de acompanhamento, ou um id vazio, `.` ou `..`, lança `InvalidArgumentException` antes de chamar a API.
  - Uma 200 sem `data`, `id` ou `message_status` inteiro lança `unexpectedResponse`.
- `Messages\MessageStatus`: as constantes da escala de `message_status` (`-4` a `4`) e `isFailure()`, `isFinal()` e `reached()`. Os DTOs têm `isFailed()`, `isDelivered()`, `isRead()` e `isFinal()`. Compare pelo número, não pelo `statusName`.
- Template (número oficial): `Zapmizer::sendTemplate(string $from, string $to, string $template, ?string $language = null, array $body = [], ?string $header = null): Messages\SentTemplate` (`POST /template-messages`), com `wamid`, `widFrom`, `widTo`, `previewText` e `raw()`. A doc ensina a cair para template no `409 window_closed`.
- Veja `docs/envio.md`.

Subclasses: `Zapmizer` ganhou os métodos públicos `sentMessage()`, `metaMessage()`, `track()` e `sendTemplate()` e os protegidos `request()`, `decode()` e `trackingLink()`, que podem colidir com nomes de uma subclasse. `post(array $options)` mantém a assinatura e continua sendo chamado no envio. Uma subclasse que sobrescreve `sendMessage()`/`sendMessageWithFile()` com o retorno `?ResponseInterface` dá erro fatal de assinatura incompatível.

## Connect

**Breaking no fluxo de connect.** O de/para está na tabela; veja também "Connect flow" em "Upgrading from 0.3.x or 0.4.x" (`docs/connect.md`).

| Antes | Agora |
| --- | --- |
| `Contracts\Connectable` sem `zapmizerExternalId()` | método obrigatório; quem usa o trait não muda |
| `start` sem `external_id` nem `expires_in` | manda os dois; o link vale o TTL |
| `state_ttl_minutes` padrão 10; `null` dava 0 | padrão 30; `null`/inválido = 30. Uma config publicada guarda o 10 |
| `?error=subscription_required` → `exchange_failed` | `subscription_required` |
| status `qr_unavailable` | sai (a Zapmizer não envia desde 1.148.8) |
| `?error=` respondido antes do `state` | `state` conferido antes; com `state` inválido, `invalid_state` |
| `start` sem `webhook_version` (o webhook nascia na versão padrão da conta de parceiro) | manda `webhook_version` de `zapmizer.connect.webhook_version`, `2026-10-20` por padrão |
| webhook reaproveitado em `2024-02-27`: silêncio | conecta (`ok`) e loga um `info`: as mensagens chegam traduzidas, e para migrar o webhook é preciso outra `webhook_url` ou revogar e conectar de novo |
| um callback com `state` errado consumia o pending | o pending só sai quando o `state` bate |
| token sem número gravado inativo (ou ativo sem instância) | `exchange_failed`, nada gravado, token revogado |
| `connect/token` sem `user_id`/`team_id` válidos gravava a conexão com `zapmizer_team_id` null | `exchange_failed`, nada gravado |
| `team_already_connected` deixava o token novo valendo | o token novo é revogado |
| `bot_instance_id`/`webhook_id` inválido virava 0 | `exchange_failed` |
| 429 na troca → `exchange_failed` | o popup espera e repete; `rate_limited` se não der |
| 429 de chamada de parceiro: `Log::error` com o corpo | `Log::warning` sem corpo |
| `webhook_failed` = inativa | `webhook_failed` = ativa, envia, sem secret para receber; mensagem nova ("Connected and sending, but incoming messages can't be verified…"); o stub `ConnectButton` emite `connected` e mostra o aviso |
| `destroy` não revogava | `destroy` revoga o token. Um app que usa o mesmo token fora da lib perde o acesso ao desconectar |
| `APP_KEY` trocado: callback `exchange_failed`, `destroy` e `show?live=1` 500 | o valor ilegível vale como ausente, com warning; é sobrescrito no connect seguinte (inclusive na troca de time); `show?live=1` responde `state: reauth_required` |
| view `connect-result` usada também por quem montava reconnect | view nova `reconnect-result` |

Outras mudanças nas rotas:

- O JSON do `start()` passa a normalizar `expires_at` (`Z` → `+00:00`, sem frações).
- `start()` reporta (`report()`) os 4xx do parceiro; um app com `ZapmizerUnavailableException` em `dontReport` passa a ver esses 4xx reportados, porque agora são `ZapmizerApiException`.
- `NoConnectableException` nas rotas da lib deixa de ir para o `report()`.
- **Resolver próprio que devolve model sem `Connectable` (ou `null`)** (desde a 2.0.0-beta.5): as rotas do connect, do reconnect e da caixa respondem `403 {"code": "no_connectable"}` (e os callbacks do popup, `status: "no_connectable"`), como já faziam com o resolver padrão e um usuário sem `Connectable`, com o `Log::warning` `zapmizer: the connect resolver returned a model that is not Connectable.`, cujo contexto traz `resolver` (a classe configurada em `zapmizer.connect.resolver`) e `returned` (a classe devolvida, ou `null`). Até a 2.0.0-beta.4 era `TypeError` (500). Só o tipo de retorno do `resolve()` entra aqui: um `TypeError` de dentro do resolver continua 500.
- **Status sem mensagem no popup** (desde a 2.0.0-beta.6): um `status` que o `result()` da lib não conhece (vindo do `POPUP_ERRORS` ou do `popupStatus()` de uma subclasse, como o `'qr_unavailable' => 'qr_unavailable'` copiado da 0.4) vai ao opener com o próprio nome e a mensagem de `exchange_failed`, com o `Log::warning` `zapmizer: connect result without a message.` (contexto `status`). Até a 2.0.0-beta.5 era 500 (`Undefined array key`), e o opener ficava esperando.
- Na rota `zapmizer.connect.start`, a `base_uri` inválida, a resposta inesperada e a configuração ausente respondem 503 `zapmizer_unavailable` com `report()` (veja "Base da API").

Correções (já não funcionavam com token de connect, 403 `missing_ability`):

- o callback tentava rotacionar o secret: a conexão ficava inativa e o connect seguinte repetia a falha;
- `destroy` e troca de time tentavam apagar o webhook remoto, só logavam e deixavam o token valendo;
- a doc ensinava a rotação (§7).

Novo:

- rotas de reconnect (`zapmizer.connect.reconnect` e `zapmizer.connect.reconnect.callback`);
- `revoke_previous_token` (desligada por padrão; recomendado ligar). Uma config publicada não tem a chave dentro de `connect` (o merge é raso): acrescente `'revoke_previous_token' => env('ZAPMIZER_CONNECT_REVOKE_PREVIOUS_TOKEN', false),` ou republique a config, senão a variável de ambiente não faz efeito. Ao ligar, tire o observer `updating` que a doc anterior ensinava, senão o token é revogado duas vezes;
- eventos `NumberConnected` e `ReconnectRefused`;
- `has_webhook_secret` na serialização da conexão (aditivo);
- callback que não responde 500 com parâmetro em array;
- `show?live=1` grava o número trocado no WhatsApp: com `state` `connected` e um número diferente do `phone_number` gravado, grava o número novo, `is_active = true` (a regra do connect) e `connected_at` = agora (a hora em que o painel viu a troca). Número igual, número nulo e qualquer outro estado não gravam nada. Sem isso o webhook recusava os eventos do número novo até um connect novo. A gravação é condicional (transação com a linha travada, só se `phone_number` e `bot_instance_id` ainda forem os lidos): um connect que gravou outro pareamento no meio ganha. Ela passa pelo `save()`, com os eventos do model. A resposta já traz o número novo; uma gravação que falha é reportada e a resposta segue com o número gravado. Método `protected syncConnectedNumber()` para subclasses (por exemplo, para não reativar uma conexão que o app desativou);
- `show?live=1` no número sem a rota de QR (o número oficial: a Zapmizer responde 404 em `/connection` ao time sem o QR) passa de `instance_gone` ao `is_online` de `GET /bot-instances/{id}`: `state` `connected`; offline, `connecting` enquanto o processo da Zapmizer sobe (`unprovisioned`, `booting`, `provisioning`, `syncing`, `rebooting`) e `disconnected` no resto, inclusive o processo desligado. Só um 404 nas duas rotas é `instance_gone`.

Stubs: o `useZapmizerIntegration` ganha `startPolling()`, `stopPolling()` e `beginTurnOn()`. O polling consulta `show?live=1` a cada 15 s, ou a cada 5 s por 2 minutos depois de religar, até o número voltar a `connected`. Nos 30 s depois de religar, ele ignora o `off`, e o painel mostra "Conectando...". Uma resposta atrasada é descartada, e o polling para quando o componente desmonta, quando a conexão some, em `reauth_required`, em `instance_gone` e no 401, 403 ou 419 do `show`; um `reload()` no meio de um poll não o interrompe. O `ConnectionPanel` passa a emitir `relinked`, e não mais `refresh`, quando o religar dá certo. Ligue `@relinked="beginTurnOn()"`.

Stubs: republicar com `php artisan vendor:publish --tag=zapmizer-wizard --force`. Atenção: o `--force` sobrescreve as edições locais dos arquivos publicados; guarde-as antes e reaplique.

Subclasses do `ConnectController`: saem `obtainWebhookSecret()` e `forgetRemoteWebhook()`, e os métodos de estado vão para o trait `HandlesPopupState` com as mesmas assinaturas. O `popupStatus()` lê `static::POPUP_ERRORS`. Um `result()` próprio com mapa de mensagens passa a receber `subscription_required` e `rate_limited` (e não recebe mais `qr_unavailable`): acrescente as duas chaves.

## Parceiro

**Breaking no cliente de parceiro:** `ConnectToken`, `ConnectSession::$expiresAt` e a assinatura do `createSession()` mudaram.

- `PartnerClient::subscription(string $externalId): ?PartnerSubscription` lê `GET /partner/users/{externalId}`; 404 devolve `null`. `Connect\PartnerSubscription` tem `userId`, `teamId`, `externalId`, `subscribed`, `quantity` (`null` quando não veio número), `trialEndsAt` (`?CarbonImmutable`), `paymentIncomplete` e `hasAccess(?DateTimeInterface $now = null)`.
- `PartnerClient::checkout(string $externalId, string $redirectUri, ?string $state = null): PartnerCheckout` cria o checkout (`POST /partner/users/{externalId}/checkout`). `Connect\PartnerCheckout` tem `url` e `expiresAt` (`?CarbonImmutable`). 404 → `ZapmizerApiException` com `status()` 404; 409 → `ZapmizerApiException` com `error()` `already_subscribed` ou `payment_incomplete`; 422 do redirect → `errors()['redirect_uri']`.
- `createSession()` ganha `externalId` (o id do cliente no app, chave das duas chamadas acima) e limita `expires_in` a 900..86400 (`PartnerClient::MAX_EXPIRES_IN`).
- `ConnectToken` ganha `userId` e `hasNumber()` (`false` quando `phone_number` ou `bot_instance_id` vieram `null`).
- `createSession()` ganha `webhookVersion` no fim e, com `webhookUrl`, manda `webhook_version`: o argumento ou `zapmizer.connect.webhook_version` (`ZAPMIZER_WEBHOOK_VERSION`, padrão `2026-10-20`). Vazio deixa o campo de fora, e o webhook nasce na versão padrão da conta de parceiro. Zapmizer anterior à 1.148.17 ignora o campo. Sem `webhookUrl`, nada muda no corpo.
- `ConnectToken` ganha `webhookVersion` (`?string`, o `webhook_version` do `connect/token`; `null` sem webhook ou em Zapmizer anterior à 1.148.17) e `hasLegacyWebhookVersion()` (o webhook reaproveitado ficou em `2024-02-27`).
- **O `PartnerClient` não manda `api-version`, de propósito** (como na 0.x). As rotas de parceiro da Zapmizer (`connect/sessions`, `connect/token`, `partner/users/{externalId}` e o checkout) não leem o header: nem o `auth.partner` nem os controllers delas consultam a versão, e a resposta é a mesma com ou sem ele (a versão do webhook que o connect cria vai no corpo, em `webhook_version`). Não é preciso estender a classe para mandar a versão. Uma subclasse que já manda continua funcionando, sem efeito nenhum.
- Datas ilegíveis (`expires_at`, `trial_ends_at`) viram `null` e logam `zapmizer: unreadable date.` (warning) com `field`, `value` e `external_id`.

Quebras:

- `createSession()`: `state` passa a ser opcional e o 2º parâmetro; entram `externalId` e `webhookVersion` no fim. Quem chama com argumentos nomeados não muda; posicionais seguem na mesma ordem. Subclasse que sobrescreve `createSession()` com a assinatura antiga dá erro fatal de assinatura incompatível ao carregar e precisa atualizar a assinatura.
- `createSession()`, `subscription()` e `checkout()` lançam `InvalidArgumentException` para `external_id`/`state` inválidos (`state` vazio, `"0"` ou com espaço no começo ou no fim) antes de chamar a API.
- `createSession()`: `expires_in` acima de 86400 é cortado; `redirect_uri`/`webhook_url` vazios vão no corpo (422 da API) em vez de sumirem.
- `ConnectSession::$expiresAt` passa de `?string` a `?CarbonImmutable`; o JSON segue ISO 8601.
- `ConnectToken`: `teamId` deixa de ser nulo; entra `userId` (2º parâmetro do construtor); `user_id`/`team_id` inválidos lançam `unexpectedResponse` (o callback responde `exchange_failed`, veja "Connect").
- 404 (fora do `exchangeCode`), 409, 422 e 429 deixam de ser `ZapmizerUnavailableException`.
- 404 e 409 de chamadas de parceiro deixam de logar `zapmizer: partner call failed.`; nos outros 4xx, o log passa a registrar o corpo cortado em 500 caracteres (antes, inteiro). O 429 vai como warning, sem corpo (veja "Connect").

De/para:

- `new ConnectToken($token, $teamId, ...)` → `new ConnectToken($token, $userId, $teamId, ...)`.
- `new ConnectToken(token: …, teamId: …)` com argumentos nomeados e sem `userId` agora dá `ArgumentCountError`: `userId` é obrigatório.
- `$session->expiresAt` (string) → `$session->expiresAt?->toIso8601String()`.
- `new ConnectSession($url, '2026-…')` com string no `expiresAt` agora dá `TypeError`: passe um `CarbonImmutable` (ou `null`).

Subclasses: `PartnerClient` ganhou os métodos públicos `subscription()` e `checkout()`, a constante `MAX_EXPIRES_IN` e os métodos protegidos `guardExternalId()`, `guardState()`, `withoutNulls()` e `clampExpiresIn()`, que podem colidir com nomes de uma subclasse. Os três últimos vêm do trait `Connect\Concerns\BuildsZapmizerRequests`, comum ao `InstanceClient`, com os mesmos métodos `protected`.

## Instância

- `InstanceClient::reconnect(int $botInstanceId, string $redirectUri, ?string $state = null, ?int $expiresIn = null): ReconnectResult` religa o número (`POST /bot-instances/{id}/reconnect`). `Connect\ReconnectResult` tem `status` (`ReconnectResult::ONLINE`, `STARTING` ou `NEEDS_CLIENT`), `url`, `expiresAt` (`?CarbonImmutable`), `isOnline()`, `isStarting()` e `needsClient()`: 200 → online, 202 → starting, 409 `needs_reconnect` → `needsClient()` com a `url` para onde mandar o cliente. 404 → `InstanceGoneException` (número inexistente ou fora desta conexão); 402, 403, 422 e outro 409 → `ZapmizerApiException` com `status()` e `error()`; 429 → `ZapmizerRateLimitedException`. `state` e `expires_in` seguem as regras do `createSession()`.
- `InstanceClient::revokeToken(): void` revoga o token da conexão (`DELETE /connect/token`). 204 e 401 voltam normalmente (revogado agora; o token já não valia); 404 → `ZapmizerApiException` com `status()` 404 (o token não veio de um connect e continua valendo).
- `InstanceClient::instance(int $botInstanceId): BotInstance` lê o número (`GET /bot-instances/{id}`) e `InstanceClient::instances(): array` lista os números que o token vê (`GET /bot-instances`, só a primeira página; com token de connect, só o número da conexão). `Connect\BotInstance` tem `id`, `state` (o estado do processo na Zapmizer, não o do pareamento), `stateLabel`, `isOnline`, `isUp`, `supportsGroups`, `supportsTemplates`, `createdAt` (`?CarbonImmutable`), `selfUrl` e `isOfficial()` (`supportsTemplates`: número oficial, da API da Meta). Um booleano ausente vale `false`. `is_online` é o jeito que a Zapmizer recomenda de confirmar o estado do número, inclusive no oficial. 404 → `InstanceGoneException`; 403 (`missing_ability`, token sem `numbers:read`) e outros 4xx → `ZapmizerApiException`; 429 → `ZapmizerRateLimitedException`.
- `InstanceClient::conversationSettings(int $botInstanceId, string $wid): ConversationSettings` e `InstanceClient::updateConversationSettings(int $botInstanceId, string $wid, bool $autoTranscribeAudio): ConversationSettings` leem e mudam os ajustes de uma conversa (`GET`/`PUT /bot-instances/{id}/conversation-settings`). `Connect\ConversationSettings` tem `wid` (o que a Zapmizer devolve, normalizado) e `autoTranscribeAudio`, que liga o evento `message_transcribed` no webhook. O token de connect tem as abilities das duas rotas; um token sem `messages:read`/`messages:create` recebe 403 `missing_ability` (`ZapmizerApiException`). 404 → `InstanceGoneException`; 422 → `ZapmizerApiException` com `errors()['wid']`.
- Veja "9. Reconnect and revoke" em `docs/connect.md`, com a ordem dos `catch`. Os métodos do embed estão em "Embed".
- Os métodos novos não mudam comportamento existente.

Quebras:

- `InstanceClient::connection()`: 4xx que não é 401/404 deixa de ser `InstanceGoneException` (no `show?live=1`, 403/409/422/429 passam de `instance_gone` a `zapmizer_unavailable`; o contrato só declara 401 e 404 nessa rota).
- `InstanceClient` (`createWebhook`, `rotateWebhookSecret`, `deleteWebhook`, `media` fora de 422/429): 4xx deixa de ser `unexpectedResponse`.

Mídia recebida:

- Download de mídia: 60 s para conectar e 600 s no total (`ZapmizerApi::TRANSFER_CONNECT_TIMEOUT`/`TRANSFER_TIMEOUT`), no lugar do limite de 60 s por leitura e de qualquer timeout de um `GuzzleHttp\Client` registrado. O teto total pede a extensão `curl`. O corpo passa por `php://temp` (memória até 2 MB, disco depois) e é copiado para o arquivo temporário.
- Um download de mídia cortado (erro de transporte, falha de leitura ou de escrita no meio da cópia, ou corpo menor que o `Content-Length` sem `Transfer-Encoding`) lança `ZapmizerUnavailableException` e não deixa arquivo. Antes lançava um `RuntimeException` cru, ou devolvia um `attached` truncado quando a conexão fechava limpa.
- Sem conseguir criar ou abrir o arquivo temporário, uma resposta `200` de mídia lança `ZapmizerUnavailableException` (antes estourava `ErrorException`); os outros status não criam arquivo.
- `MediaDownload::$size` passa a ser o total gravado no arquivo e deixa de ser `null` num download anexado (com gzip o `Content-Length` some da resposta).
- `ZapmizerConnection::media()` e `syncMessages()` sem instância pareada passam de `ZapmizerUnauthorizedException` a `ZapmizerConnectException` (`notPaired()`).
- `Retry-After` não numérico na mídia passa de `0` para `null` (mensagem sem `Retry in`). O `reason()` da mídia corta HTML em 500 caracteres e deixa de incluir valor escalar não-string de `errors` (número).

Mensagens do número:

- `syncMessages()` pede sempre `api-version: 2026-10-20` e lê o contrato (veja "Mensagens"). Na 0.4.2 ele fixava `2025-06-27` e recusava a resposta no contrato; agora a recusa vale só para uma `version` que a lib não conhece.
- `createWebhook()` (e com ele `registerWebhook()`) cria o webhook em `2026-10-20` e manda `api-version: 2026-10-20`; na 0.4.2 era `2024-02-27` com `api-version: 2025-06-27`. Zapmizer anterior à 1.148.17 ignora os dois e cria em `2024-02-27`.
- Constantes: sai `InstanceClient::LEGACY_MESSAGE_API_VERSION` (da 0.4.2); entra `InstanceClient::MESSAGE_CONTRACT_VERSION` (`2026-10-20`, igual a `Message::CONTRACT_VERSION`); `LEGACY_WEBHOOK_VERSION` (`2024-02-27`) fica, agora para reconhecer o webhook legado.
- `ZapmizerConnection::media()` e `awaitMedia()` recebem `Messages\Message` e pedem com `id` e `timestamp`. `InstanceClient::media()` não muda: recebe `int $botInstanceId, string $messageId, int $timestamp`.

Subclasses: `InstanceClient::reasonFrom()` saiu (a regra está em `Support\ApiError`). Subclasses de `InstanceClient` ganham `reconnect()`, `revokeToken()`, `conversationSession()`, `inboxSession()` e, do trait `Connect\Concerns\BuildsZapmizerRequests`, `guardState()`, `withoutNulls()` e `clampExpiresIn()`. Esses nomes podem colidir com métodos já existentes nelas.

## Embed

- `InstanceClient::conversationSession(string $phone, string $parentOrigin, array $appearance = [], ?string $userId = null, ?string $userName = null, ?string $userEmail = null)` e `inboxSession(string $parentOrigin, array $appearance = [], ?string $userId = null, ?string $userName = null, ?string $userEmail = null)` criam a sessão do iframe (`POST /embed/sessions`) e devolvem `Connect\EmbedSession`, com `url`, `origin` (a origem do iframe como o navegador mostra), `expiresAt`, `resumeUrl` e `resumeUntil`; `resumeUntil` é `null` sempre que `resumeUrl` for `null`. Uma `url` que não seja http(s) segura lança `unexpectedResponse`; um `resume_url` inseguro ou de outra origem vira `null` e loga `zapmizer: unexpected resume url.` (warning) só com as origens.
- `userEmail` é o último parâmetro. Um e-mail que não passa no `FILTER_VALIDATE_EMAIL` (inclusive acima de 254 bytes) sai do corpo, sem erro.
- Novo `Http\Responses\EmbedSessionResponse` (`Responsable`): `open(fn () => $client->inboxSession(...), onRefusal: fn (ZapmizerApiException $e) => ...)` devolve 200 `{url, origin, resume_url, resume_until}` ou `{code}`:
  - 401/419 → 422 `reauth_required`;
  - 429 → 429 `rate_limited` + `Retry-After`;
  - outro 4xx → o mesmo status com `code` = `error()`;
  - 5xx, rede ou resposta inesperada → 503 `unavailable`.

  `code('customer_without_phone')` responde o código do app. A política se troca por subclasse (`refusalStatus()`, `refusalCode()`, `sessionPayload()`). Veja `docs/embed.md`.
- **De/para:** a resposta montada à mão (o exemplo antigo de `docs/connect.md` §9 ou o de `docs/backend.md` do `@zapmizer/embed`) → `EmbedSessionResponse::open(...)`. Diferenças: o 429 sai com `code: rate_limited`, e não com o `error` da Zapmizer; uma resposta inesperada vira 503 `unavailable` e é reportada.
- **Subclasses:** uma subclasse de `InstanceClient` que sobrescreve `conversationSession()` ou `inboxSession()` precisa aceitar o parâmetro `$userEmail`, senão dá erro fatal de assinatura.

## Webhook

Webhook com endereço por conexão, opt-in. Desligado, nada muda: as conexões existentes seguem na rota única até um connect novo.

- `zapmizer.webhook.per_connection` (`ZAPMIZER_WEBHOOK_PER_CONNECTION`), desligado por padrão. Quem publicou `config/zapmizer.php` precisa acrescentar a chave, porque o merge da config é raso. Use junto com `zapmizer.connect.revoke_previous_token=true`.
- Migration opcional `--tag=zapmizer-migrations-webhook-key` (coluna `webhook_key`).
- Rota `POST {prefix}/webhook/{chave}` (`zapmizer.webhook.connection`) e middleware `VerifyConnectionWebhookSignature`, que descarta com 200 o evento cujo `wid` não é o número da conexão.
- `ZapmizerConnection::$hidden` inclui `webhook_key`.

Veja "Per-connection webhook URL (opt-in)" em `docs/connect.md`.

**Versão do webhook:**

- `Webhook\Envelope::$version` (`?string`): a versão do webhook que veio no envelope (`null` em Zapmizer anterior à 1.148.17).
- As mensagens das duas versões viram `Messages\Message`: veja "Mensagens". O aviso de "webhook em `2026-10-20` não suportado", que entrou neste ciclo, saiu (com a constante `WebhookController::MESSAGE_CONTRACT_EVENTS` e o método `warnAboutTheMessageContract()`).

### Eventos do catálogo e deduplicação

Só acréscimos: a rota, os middlewares, `WebhookReceived`, `WebhookHandled` e as chaves que já existiam em `zapmizer.webhook.*` não mudam (o `MessageReceived` muda por outro motivo, veja "Mensagens"; e o `name` que o `WebhookReceived` traz para mensagens passa a ser `message_received`/`message_sent` nos webhooks `2026-10-20`, item 17 do upgrade). Veja `docs/webhook.md`.

- Eventos tipados para os 13 eventos do catálogo além de `message`, disparados logo depois do `WebhookReceived`: `MessageStatusUpdated`, `ConnectionStateChanged`, `MessageReacted`, `MessageEdited`, `MessageRevoked`, `MessageTranscribed`, `CallReceived`, `PollVoted`, `ContactNumberChanged`, `GroupMembershipChanged`, `GroupAdminsChanged`, `GroupJoinRequested` e `GroupUpdated`. Todos estendem `Events\WebhookEvent` (`envelope`, `connection`, `payload`) e trazem um DTO só leitura de `Webhook\*`. Esses eventos continuam respondendo `Webhook Received`, sem `WebhookHandled`, e passando pelo `missingMethod`.
- `MessageStatusUpdated`: `$event->status` é um `Webhook\StatusUpdate`, com o `ack` na escala de `Messages\MessageStatus` (só de -1 a 4 no webhook), `status` derivado do `ack` e os atalhos do `SentMessage` (`isFailed()`, `isDelivered()`, `isRead()`, `isFinal()`, `reached()`); `unknown` ou `ack` fora da faixa não disparam o evento.
- `Messages\Concerns\HasMessageStatus` lê o número por um método abstrato, `messageStatusCode(): ?int`, e não mais por `$this->status`: uma classe do app que usa o trait precisa implementá-lo.
- `ConnectionStateChanged`: `state`, `reason`, `kind` (com `ConnectionState::KIND_BANNED`, `KIND_LOGGED_OUT`, `KIND_CONFLICT`, `KIND_CONNECTION_LOST` e `KIND_WRONG_NUMBER`, o QR lido por outro número), e `isAttributable()` falso quando o `wid` vem nulo. A lib não guarda o estado; a doc traz as regras do `wid` nulo e do `created_at` mais recente.
- Um `handle*` que o app já tinha numa subclasse para um desses eventos continua rodando, e o evento tipado sai também. `WebhookController::catalogEvents()` troca ou desliga os eventos.
- **Subclasses do `WebhookController`:** o controller ganhou os métodos protegidos `processWebhook()`, `catalogEvents()`, `dispatchCatalogEvent()`, `duplicateMethod()` e `deduplicator()`. Uma subclasse que já tinha um método com um desses nomes passa a sobrescrevê-lo (e, com assinatura incompatível, dá erro fatal): renomeie o seu.
- **Listeners curinga e testes:** um `Event::listen('*', ...)`, um listener de `NotificationChannels\Zapmizer\Events\*` ou um `Event::fake()` sem lista de eventos passam a ver os eventos novos em cada entrega do catálogo. Uma asserção que contava todos os eventos disparados muda de número.
- **Correção:** um `message` com `timestamp` fora de uma faixa sensata (≤ 0 ou ≥ 2^37, como `PHP_INT_MAX`) dava 500, porque o Carbon lançava exceção; agora `sentAt()` cai em `now()`, como num `timestamp` que não é número. Nos eventos novos, `occurredAt` fica `null` nesse caso.
- `Webhook\Envelope` (`id`, `name`, `createdAt`, `wid`, `version`) nos eventos novos e, como argumento opcional no fim, no `MessageReceived` e no `MessageSent` (`$event->envelope`).
- Deduplicação opcional pelo `id` do envelope, desligada por padrão: `zapmizer.webhook.deduplicate`, `deduplicate_ttl` (21600 s) e `deduplicate_store` (`ZAPMIZER_WEBHOOK_DEDUPLICATE*`). Uma repetição responde 200 sem disparar nada. Em duas fases: o `id` fica reservado por 120 s enquanto a entrega roda e só é gravado pelo TTL cheio depois de uma resposta que não é 5xx; uma falha (exceção ou 5xx) apaga a reserva, e a retentativa passa. Uma retentativa que chega enquanto a primeira ainda roda recebe 200, e se a primeira falhar depois disso o evento se perde. TTL vazio ou ≤ 0 vale 21600. Com o cache fora do ar a entrega é processada e logada. `verify_number.*` nunca é deduplicado. Uma `config/zapmizer.php` publicada precisa das chaves acrescentadas à mão.

## Mensagens

**Breaking:** `InboundMessage` saiu, e toda mensagem é um `Messages\Message`, com a forma do contrato de mensagem da Zapmizer (`2026-10-20`, Zapmizer 1.148.17 ou posterior). O de/para está em "Upgrade da 0.3 e da 0.4". A mínima continua 1.148.10: com Zapmizer anterior, tudo chega no formato do WhatsApp Web e é traduzido.

- `Messages\Message`: `id`, `chat`, `sender`, `senderName`, `fromMe`, `fromMeOtherDevice`, `timestamp`, `type`, `text`, `quoted` (`QuotedMessage`), `forwarded`, `viewOnce`, `mentions`, `media` (`MessageMedia`), `location` (`MessageLocation`), `contacts` (lista de `{name, vcard}`), `poll` (`MessagePoll`), `reply` (`MessageReply`), `linkPreview` (`LinkPreview`) e `raw` (só em `unknown`), com `sentAt()`, `chatPhone()`, `senderPhone()`, `isGroup()`, `isBroadcast()` e `hasMedia()`. `Messages\MessageType` tem os tipos do contrato. `Message::fromContract()` é tolerante (campo desconhecido é ignorado; `null` só sem `id`).
- `Message::fromLegacy()` e `Messages\LegacyMessageTranslator` traduzem a mensagem do WhatsApp Web (o `data[0]` do `message` e o sync de Zapmizer anterior à 1.148.17) para o contrato. É um port da tradução da Zapmizer (`from-wwebjs.ts` + `toPartnerMessage()`), com o mesmo mapa de avisos (`LegacyMessageTranslator::NOTICES`): `forEvent()` deixa de fora todo aviso, como o webhook do contrato; `forSync()` só os de sistema, e os outros viram `unknown`, como o sync. Os testes comparam campo a campo com as fixtures do servidor (`tests/contract/message-contract`, atualizadas por `update.php` com o commit de origem).
- Webhook: `message_received` → `MessageReceived`; `message_sent` → o novo `Events\MessageSent`, para tudo o que o número envia (API, bot ou celular; `fromMeOtherDevice` separa o celular); `message` (`2024-02-27`) → `MessageReceived`, traduzido. Um aviso do WhatsApp não dispara nada. Um evento de mensagem sem mensagem responde 200 e loga `zapmizer: a message event could not be read, and nothing fired.`. Uma `version` desconhecida é lida do mesmo jeito, com um warning, porque o webhook não repete.
- `syncMessages()`: com `version` `2026-10-20` lê o contrato, em número por QR e oficial; sem `version` traduz o legado; com outra `version` lança `unexpectedResponse`. `SyncedMessages` ganha `wid` e `version`. Uma página pode vir sem mensagens e com `hasMore`: a Zapmizer deixa os avisos de fora, mas o cursor passa por eles. Entrada que não é mensagem sai com warning.
- `ZapmizerConnection::media()`/`awaitMedia()` recebem `Message` (o `InstanceClient::media()` segue com `$botInstanceId`, `$messageId` e `$timestamp`). No número oficial a mídia não vem pela lib (422, `MediaRejectedException`).
- `zapmizer.connect.webhook_version` (`ZAPMIZER_WEBHOOK_VERSION`, padrão `2026-10-20`): veja "Parceiro" e "Connect".
- Veja "Mensagens" em `docs/webhook.md` e "The webhook version" e "Recovering what the webhook missed" em `docs/connect.md`.

Subclasses do `WebhookController`: entram os métodos protegidos `handleMessageReceived()`, `handleMessageSent()`, `dispatchContractMessage()`, `unreadableMessage()` e `messageLogContext()`, que podem colidir com os de uma subclasse; um `handleMessageReceived()` que o app já tinha passa a substituir o da lib.

## Base da API

- `ZapmizerApi::send()`, e com ele `PartnerClient`, `InstanceClient` e `Zapmizer`, recusa antes de enviar uma URL que não seja http(s) absoluta com host e sem `user:pass` (inclusive host não ASCII ou com espaço). A exceção é `ZapmizerConnectException::invalidBaseUri()`.
- **De/para:**
  - uma `base_uri` como `app.zapmizer.com/api/`, `''` ou `/api/`: antes, o resultado dependia da versão do Guzzle (`ZapmizerUnavailableException` no 7.15+; no 7.10, a chamada ia para `http://localhost/...` ou para um host resolvido por DNS, com o token); agora é `invalidBaseUri()`, e nada sai;
  - `localhost:8001/api/` (ou qualquer `host:porta` sem esquema): antes, a chamada ia para `http://localhost:8001/...`, com o token, no 7.10 e no 7.15, porque o Guzzle põe `http` quando a URI tem host e não tem esquema (`Client.php:305-306`); agora é `invalidBaseUri()`, e nada sai. Para usar essa base, ponha o esquema: `http://localhost:8001/api/`;
  - `http://:80/api`: antes, `MalformedUriException` sem embrulho; agora `invalidBaseUri()`;
  - na rota `zapmizer.connect.start`, a base inválida continua 503 `zapmizer_unavailable` (agora em qualquer Guzzle, com `report()`), e um `unexpectedResponse` (redirect, não-JSON) passa de 500 a 503 `zapmizer_unavailable` com `report()`;
  - na mesma rota, sem `zapmizer.partner.id`/`secret` (`partnerCredentialsNotProvided()`): de 500 a 503 `zapmizer_unavailable` com `report()`;
  - com um usuário autenticado sem `Connectable` no resolvedor padrão (`notConnectable()`, agora `Exceptions\NotConnectableException`, filha de `ZapmizerConnectException`): todas as rotas do connect e do reconnect (`show`, `destroy`, `start`, `callback`, `reconnect` e o callback dele) respondem 403 `no_connectable` (o popup, `status: "no_connectable"`), como a caixa de entrada, com o warning `zapmizer: the connect resolver returned a model that is not Connectable.` e sem `report()`. Até a 2.0.0-beta.3, `show`, `destroy` e os callbacks davam 500 e o `start` 503 `zapmizer_unavailable`;
  - o `VerificationClient` não muda.
- Host com rótulo punycode (`xn--`) que não decodifica, como `xn--ao-bia.com`, deixa de passar: o `new URL()` do navegador o recusa. Um `base_uri` assim é `invalidBaseUri()`, e nada sai. Nas URLs que a Zapmizer devolve, a `url` do embed e a do reconnect (`needs_reconnect`) viram `unexpectedResponse`, e o `resume_url` vira `null` com o warning `zapmizer: unexpected resume url.`, como as outras inseguras. A decodificação usa `idn_to_utf8` (UTS #46): da `ext-intl` ou, sem ela, do `symfony/polyfill-intl-idn`, agora declarado como dependência (já vem em todo app Laravel). Não é preciso instalar a `ext-intl`.

# 0.4.2

Sem breaking. A partir do Zapmizer 1.148.17, `api-version: 2026-10-20` muda o formato do `GET /whatsapp-messages` e dos webhooks novos para o contrato de mensagens, que esta versão não lê.

- `syncMessages()` manda sempre `api-version: 2025-06-27`, qualquer que seja `zapmizer.api_version`. Antes, com `2026-10-20` na config, toda mensagem vinha no formato novo, era descartada e o cursor avançava: as mensagens se perdiam sem erro.
- Se a resposta vier no contrato de mensagens mesmo assim, `syncMessages()` lança `ZapmizerConnectException` (`unexpectedResponse`) em vez de devolver uma página vazia.
- `createWebhook()` (e com ele `registerWebhook()`) cria o webhook na versão `2024-02-27`, a do evento `message` que a lib lê, e manda `api-version: 2025-06-27`. Antes o webhook herdava a versão da config e, com `2026-10-20`, o `MessageReceived` nunca disparava. Zapmizer anterior à 1.148.17 ignora o campo.
- Webhook cadastrado à mão na tela de Webhooks do Zapmizer: escolha a versão `2024-02-27`. A tela sugere `2026-10-20`, e com ela o evento `message` não chega.

# 0.4.1

Só documentação. `syncMessages()` devolve as mensagens **gravadas** depois do cursor: as que chegam no sync de uma reconexão entram, mas a edição de uma mensagem que já existia não volta (o Zapmizer pagina pela chave primária). O texto da 0.4.0 dizia "editadas, apagadas".

# 0.4.0

Sem breaking. **Recuperar mensagens exige o Zapmizer 1.148.15 ou superior** (`GET /api/whatsapp-messages`).

- **Sincronizar as mensagens do número.** `ZapmizerConnection::syncMessages($cursor = null, $since = null, $limit = null): Connect\SyncedMessages` lê tudo o que mudou nas mensagens do número desde o cursor (recebidas e enviadas, editadas, apagadas, as do sync na reconexão), cada uma traduzida como no webhook (`InboundMessage::fromMessage()`, motor wwebjs ou Rust). O Zapmizer não filtra nem deduplica: pule `fromMe` e deduplique por `$message->id`. `SyncedMessages` traz `messages`, `payloads` (o objeto cru, na mesma ordem), `cursor` (opaco; mantém o anterior quando nada veio) e `hasMore`. Entrada sem id é descartada sem travar a página. Sem `bot_instance_id` lança `ZapmizerUnauthorizedException`. Veja "Recovering what the webhook missed" em `docs/connect.md`.
- `Connect\InstanceClient::syncMessages(int $botInstanceId, ?string $cursor = null, ?int $since = null, ?int $limit = null)`.
- Laravel do 8.83 ao 13, com PHP 8.1.

# 0.3.1

Sem breaking.

- **Exceções próprias pra mídia.** O 422 de `InstanceClient::media()` vira `Exceptions\MediaRejectedException` (`reason()` com o motivo do Zapmizer) e o 429 vira `Exceptions\MediaRateLimitedException` (`retryAfter(): ?int`, segundos do `Retry-After`). As duas estendem `ZapmizerConnectException` — `catch` no base continua pegando; `mediaRejected()` e `mediaRateLimited()` devolvem as subclasses.
- `ZapmizerConnection::awaitMedia()` trata o 429 como `downloading` em vez de propagar: espera o `Retry-After` ou o próximo da lista (o maior) e pergunta de novo; se a lista acabar ainda limitado, devolve `downloading`. O 422 continua propagando. `media()` não muda — propaga os dois.

# 0.3.0

Sem breaking. **Mídia recebida exige Zapmizer 1.150.0 ou superior** (endpoint `GET /api/whatsapp-messages/media`) — *confirmar a release que inclui `api-media-mensagem`*.

- **Mídia de mensagem recebida.** `ZapmizerConnection::media(InboundMessage $message): Connect\MediaDownload` busca os bytes na API do Zapmizer (`bot_instance_id` da conexão, `id` e `sentAt` da mensagem). O webhook `message` chega **antes** do bot terminar o download, então a resposta tem três estados: `attached` (bytes gravados num arquivo temporário — `path()`, `stream()`, `contents()`; apagado no destruct), `downloading` (tentar de novo) e `unavailable` (não vai chegar: fora da janela de 600 s, tipo não baixado, view-once). Conexão sem `bot_instance_id` lança `ZapmizerUnauthorizedException`.
- `ZapmizerConnection::awaitMedia($message, array $waitsSeconds = [2, 5, 10, 20, 30], ?Closure $sleep = null)` repete `media()` enquanto `downloading`, dormindo conforme a lista; devolve o último resultado. Em job vale mais `media()` + `release($delay)` — veja "Receiving media" em `docs/connect.md`.
- `Connect\InstanceClient::media(int $botInstanceId, string $messageId, int $timestamp)` — o `timestamp` é obrigatório (a janela de 600 s conta a partir dele; não pode ser futuro). O 200 desse endpoint é binário por desenho: o guard de JSON não roda nele (redirect continua recusado); 202/404 são JSON com `media_state`. 422 (timestamp futuro, instância Meta Cloud) e 429 (limite de 60 req/min por usuário + instância) viram `ZapmizerConnectException` com o motivo — `mediaRejected()` e `mediaRateLimited()`.
- `InboundMessage::mediaFilename()` e `mediaMimeType()` — o nome original e o tipo que o webhook trouxe em `_data`.
- `Support\JsonResponse::redirected()` — só a parte de redirect do `problem()`.

# 0.2.0

**Breaking** (0.x: a minor bump is the breaking bump) — leia "Upgrading from 0.1.x" em `docs/connect.md`. **Exige Zapmizer 1.149.0+** (pareamento hospedado).

- **Connect sem wizard.** A página hospedada do Zapmizer autoriza o time **e pareia o número** antes de devolver o `code`; `POST /api/connect/token` responde `phone_number`, `bot_instance_id`, `webhook_id` e `webhook_secret` junto com o token. O `callback` grava tudo e a conexão nasce **ativa** (`is_active = true` quando veio número). Sem número (Zapmizer antigo) fica inativa.
- **Rotas removidas:** `zapmizer.connect.instance`, `zapmizer.connect.instances`, `zapmizer.connect.connection` (404). Com elas saem os códigos `reauth_required`, `choice_required`, `booting`, `not_connected`, `no_instance`, `plan_limit` (422), `instance_unavailable`, `qr_not_available`. Sobram `show`, `start`, `callback`, `destroy`.
- **`show?live=1`:** consulta `GET /bot-instances/{id}/connection` e devolve `connection.state` (estado do Zapmizer, ou `reauth_required` / `instance_gone` / `zapmizer_unavailable`; `null` sem o que consultar) e `connection.is_online`. Rota com `throttle:60,1`.
- **Novos statuses do `postMessage` do callback:** `plan_limit`, `qr_unavailable` (o popup termina com `?error=`) e `webhook_failed`. Continuam `ok`, `denied`, `invalid_state`, `exchange_failed`, `team_already_connected`, `no_connectable`.
- **Webhook:** `start` manda `webhook_url = route('zapmizer.webhook')` na sessão; o Zapmizer registra o webhook no time durante o pareamento. Quando ele reaproveita um webhook que já existia (`webhook_secret: null`), o pacote mantém o secret guardado se for o mesmo `webhook_id`, senão rotaciona na hora (`POST /api/webhooks/{id}/secret`); rotação falhando → conexão inativa, log e `webhook_failed`. Webhook trocado no mesmo time apaga o antigo lá (best-effort), como já acontecia ao trocar de time.
- **`expires_in` mínimo 900:** `PartnerClient::createSession($redirectUri, $state, $webhookUrl = null, $expiresIn = null)` — `$webhookUrl` é o **terceiro** argumento — nunca pede menos que `PartnerClient::MIN_EXPIRES_IN` (900 s), o piso do Zapmizer.
- `ConnectToken` ganha `phoneNumber`, `botInstanceId`, `webhookId`, `webhookSecret` e `needsWebhookSecret()`.
- `Connect\InstanceClient` fica só com `connection()`, `createWebhook()`, `rotateWebhookSecret()`, `deleteWebhook()`. Removidos `instances()`, `createInstance()`, `Connect\InstanceSummary`, `Exceptions\InstanceBootingException`, `Exceptions\InstancePlanLimitException`.
- **Stubs do wizard removidos:** `ConnectionWizard.vue`, `StepAuthorize.vue`, `StepQr.vue`, `StepDone.vue`, `InstancePicker.vue`, `QrCanvas.vue`, `useZapmizerConnection.ts`. Ficam `ConnectButton.vue` (o antigo `StepAuthorize`, com os statuses novos), `ConnectionPanel.vue` (número, estado via `live`, desconectar), `useZapmizerIntegration.ts` (`reload({ live })`, `start`, `disconnect`, `openZapmizerConnect`) e `types/zapmizer.ts` enxuto. A dependência `qrcode` não é mais necessária. A tag `--tag=zapmizer-wizard` mantém o nome.

# 0.1.1

- **Envio com token inválido não "dá certo" mais.** `Zapmizer::sendMessage()`/`sendMessageWithFile()` (e `VerificationClient`, `Connect\PartnerClient`, `Connect\InstanceClient`) mandam `Accept: application/json` e não seguem redirect. Com token revogado o Zapmizer redirecionava pra página de login, o Guzzle seguia e a página HTML voltava 200 — mensagem perdida em silêncio. Agora um 3xx ou uma resposta que não é JSON lança `CouldNotSendNotification` (`zapmizerRespondedUnexpectedly`) / `ZapmizerVerificationException` / `ZapmizerConnectException` (`unexpectedResponse`).
- **Migrations com tag por fluxo:** `zapmizer-migrations-verify` (`whatsapp_verifieds`) e `zapmizer-migrations-connect` (`zapmizer_connections`); `--tag=migrations` continua publicando as duas. O webhook responde `Webhook Received` a um `verify_number.*` quando a tabela `whatsapp_verifieds` não existe (app só com connect), em vez de 500 — mesmo cache de `Schema::hasTable` que o middleware usa pra `zapmizer_connections` (`Support\TableExists`).
- **Resolver sem connectable:** `ZapmizerConnectException::noConnectable()` (`NoConnectableException`, `render()` → 403 `{"code": "no_connectable"}`) é o que um `ResolvesConnectable` lança quando não há o que conectar (usuário sem time); antes o `TypeError` virava 500. `ResolvesAuthenticatedUser` lança quando não há usuário; o callback do popup captura e responde a página com `status: "no_connectable"`.

# 0.1.0

**Breaking** (0.x: a minor bump is the breaking bump) — leia "Upgrading from 0.0.x" em `docs/connect.md`.

- **Webhook: eventos do bot (`message` etc.) só entram assinados.** Todo webhook do Zapmizer tem secret e toda entrega do bot vem com `X-Zapmizer-Signature` (HMAC-SHA256 de `"{timestamp}.{raw body}"`, secret atual + anterior, janela de 5 min). App single-tenant informa o secret do webhook que registrou em `ZAPMIZER_WEBHOOK_SECRET` (`zapmizer.webhook.secret`); sem ele, todo evento do bot vira 401 com log. `verify_number.*` continua chegando e sendo aceito sem assinatura — é o único evento que o Zapmizer manda assim. Body que não é JSON responde 401 (antes 400).
- Webhook: `WebhookReceived`/`WebhookHandled` ganham `$connection` (segundo argumento, opcional). Middleware `VerifyWebhookSignature` aplicado à rota pelo pacote; testa o secret da aplicação primeiro, depois as `ZapmizerConnection` (as do `X-Wid` antes, scan completo só no miss); funciona sem a tabela `zapmizer_connections`.
- Connect (multi-tenant): trait `Connectable` + contrato `Contracts\Connectable` + model `ZapmizerConnection` (tabela `zapmizer_connections`, credenciais cifradas com `$casts` — Laravel 10 também; `zapmizer_team_id` único) — cada model conecta o próprio número do Zapmizer. `ResolvesConnectable::resolve()` devolve `Model&Connectable`.
- Connect: `ConnectController` com o fluxo hospedado (`start`/`callback`/`instance`/`instances`/`connection`/`show`/`destroy`), rotas `zapmizer.connect.*`, resolver configurável (`zapmizer.connect.resolver`). Códigos: `reauth_required`, `choice_required`, `booting`, `not_connected`, `no_instance`, `plan_limit`, `instance_unavailable`, `qr_not_available`, `zapmizer_unavailable`, `partner_unauthorized`; callback: `ok`, `denied`, `invalid_state`, `exchange_failed`, `team_already_connected`.
- Connect: reconectar em outro time do Zapmizer zera número/instância/webhook (e apaga o webhook remoto antigo); `destroy` apaga o webhook remoto (best-effort); 423 grava o `bot_instance_id` que o Zapmizer devolve; instância `disconnected`/`off` é re-bootada pelo id em vez de criar outra; registro do webhook sob lock de linha.
- Connect: clients `Connect\PartnerClient` e `Connect\InstanceClient` (Guzzle injetado; token obrigatório no `InstanceClient`), registro, rotação (`rotateWebhookSecret()`) e remoção (`deleteRemoteWebhook()`) do webhook.
- Webhook: `handleMessage` traduz o evento `message` em `InboundMessage` (`from`, `author`/`authorPhone` em grupo, `isBroadcast`, mídia sem bytes) e dispara `MessageReceived($message, $connection, $payload)`; `$connection` é `null` quando a entrega foi assinada com o secret single-tenant. O consumidor deduplica por `$message->id` (o Zapmizer faz retry 3x).
- `Support\PhoneNumber` (`normalize` com `zapmizer.default_country_code`, default `55`; `digits`, `variants`, `fromWid`, `isBroadcastWid`).
- Stubs Inertia + Vue do wizard, publicáveis com `--tag=zapmizer-wizard`; view do popup publicável com `--tag=views`.
- Config: `zapmizer.api_version`, `zapmizer.default_country_code`, `zapmizer.partner.*`, `zapmizer.connect.*`, `zapmizer.webhook.{secret,tolerance}`, `zapmizer.models.connection`.
- Docs: `docs/connect.md` (com "Upgrading from 0.0.x"); `docs/verify-number.md` corrigido — `verify_number.*` é entregue sem assinatura, e é o único.

# 0.0.4
- Configurações: Permitir informar api_token.
- Mensagens: Permitir que código cliente altere a versão da api. header: `api-version`.

# 0.0.3

# 0.0.2

# 0.0.1