# Changelog

# Unreleased

**Breaking** para quem constrói `PartnerClient` ou `InstanceClient` à mão: o argumento do `GuzzleHttp\Client` virou o do transporte. Quem resolve os clientes pelo container (`app(PartnerClient::class)`, `$connection->instanceClient()`) não muda a construção.

- **Transporte HTTP.** `PartnerClient` e `InstanceClient` mandam as requisições pelo `Http` do Laravel (`Connect\Transports\LaravelHttpTransport`), padrão e único transporte da lib. Para trocar, `zapmizer.http.transport` nomeia uma classe que implementa `Contracts\Transport`. Veja `docs/transporte.md`.
- O `getPrevious()` da `ZapmizerUnavailableException` pode mudar de classe conforme a versão do Laravel: uma exceção do `Http` do Laravel (`ConnectionException` etc.) ou, do Laravel 8 ao 11 em alguns casos (corpo cortado), a do Guzzle.
- `zapmizer.http.connect_timeout` e `zapmizer.http.timeout` (`ZAPMIZER_HTTP_CONNECT_TIMEOUT`, `ZAPMIZER_HTTP_TIMEOUT`), `null` por padrão. Sem valor, vale o padrão do `Http` da versão: nenhum no Laravel 8; 30 s de total e 10 s de conexão no 9.52 e do 10 em diante.
- `illuminate/http` declarado como dependência; `ext-curl` sugerida.
- Um app que já tinha `zapmizer.http.connect_timeout`/`timeout` na config agora aplica esses timeouts nas chamadas JSON de parceiro e instância.
- Download de mídia: 60 s para conectar e 600 s no total (`ZapmizerApi::TRANSFER_CONNECT_TIMEOUT`/`TRANSFER_TIMEOUT`), no lugar do limite de 60 s por leitura e de qualquer timeout de um `GuzzleHttp\Client` registrado. O teto total pede a extensão `curl`. O corpo passa por `php://temp` (memória até 2 MB, disco depois) e é copiado para o arquivo temporário.
- Um download de mídia cortado (erro de transporte, falha de leitura ou de escrita no meio da cópia, ou corpo menor que o `Content-Length` sem `Transfer-Encoding`) lança `ZapmizerUnavailableException` e não deixa arquivo. Antes lançava um `RuntimeException` cru, ou devolvia um `attached` truncado quando a conexão fechava limpa.
- Sem conseguir criar ou abrir o arquivo temporário, uma resposta `200` de mídia lança `ZapmizerUnavailableException` (antes estourava `ErrorException`); os outros status não criam arquivo.
- `MediaDownload::$size` passa a ser o total gravado no arquivo e deixa de ser `null` num download anexado (com gzip o `Content-Length` some da resposta).
- Um header passado na chamada com o mesmo nome de `Accept`, `X-Partner-Key`, `Authorization` ou `api-version`, em qualquer caixa, é descartado; vale o valor fixo, em vez de concatenar.
- JSON com UTF-8 malformado no corpo (um texto cortado no meio de um caractere) lança o `InvalidArgumentException` do Guzzle como veio: é erro de quem chamou, não queda. `catch (ZapmizerConnectException)` não pega esse caso.

**Com o `Http` do Laravel como padrão:**

- `Http::fake()`, `Http::preventStrayRequests()`, os listeners de `RequestSending`/`ResponseReceived` e o Telescope passam a ver as chamadas de parceiro e instância. Um `Http::fake()` sem resposta para a URL da Zapmizer responde 200 vazio: as chamadas que esperam JSON (`createSession`, `exchangeCode`, `connection`, `createWebhook`, `rotateWebhookSecret`) falham com `unexpectedResponse`, mas `deleteWebhook()` passa em silêncio e `media()` devolve um download anexado vazio (`size === 0`).
- Fake com `MockHandler` num `GuzzleHttp\Client` registrado no container deixa de pegar parceiro e instância: use `Http::fake()`. Esse `GuzzleHttp\Client` passa a valer só para o `Zapmizer` (envio) e o `VerificationClient`.
- Proxy sem `Http::globalOptions` (Laravel 8, 9 e 10 antes do 10.48) pede um transporte próprio (exemplo em `docs/transporte.md`).
- O `GuzzleTransport` que existiu nas versões de desenvolvimento deste ciclo saiu.

**Upgrade da 0.3:**

- `new PartnerClient($id, $secret, $guzzle, $uri)` → `new PartnerClient($id, $secret, null, $uri)` (o transporte vem do container, com a config) ou com um `Contracts\Transport` no lugar do `null`.
- `new InstanceClient($token, $guzzle, $uri, $version)` → `new InstanceClient($token, null, $uri, $version)`, idem.
- Um `GuzzleHttp\Client` nessa posição agora lança `TypeError`. Sem transporte, o cliente resolve `Contracts\Transport` no container: com o provider da lib registrado (sem ele, `BindingResolutionException`), e uma `zapmizer.http.transport` inválida falha já no construtor.
- Testes com `MockHandler` para parceiro e instância: troque por `Http::fake()` e `Http::assertSent()`.
- Subclasses: a propriedade `$http` não existe mais (as chamadas passam pelo `$transport`). `InstanceClient::spool()` continua com a assinatura da 0.3, mas rebobina o corpo e não o fecha.
- Quem publicou `config/zapmizer.php` com a chave `http` e quer trocar de transporte precisa acrescentar `'transport' => ...` dentro dela: o merge da config é só no primeiro nível.

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