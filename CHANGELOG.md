# Changelog

# Unreleased

**Breaking** para quem constrói `PartnerClient`, `InstanceClient` ou `Zapmizer` à mão: o argumento do `GuzzleHttp\Client` virou o do transporte. Quem resolve os clientes pelo container (`app(PartnerClient::class)`, `$connection->instanceClient()`, `app(Zapmizer::class)`) não muda a construção.

**Breaking nos erros:** o envio lança as mesmas exceções dos clientes Connect e `CouldNotSendNotification` foi removida. Veja "Erros" abaixo, com o de/para.

**Breaking no cliente de parceiro:** `ConnectToken`, `ConnectSession::$expiresAt` e a assinatura do `createSession()` mudaram. Veja "Cliente de parceiro" abaixo.

- **Transporte HTTP plugável.** `PartnerClient` e `InstanceClient` mandam as requisições por um transporte: `zapmizer.http.transport` nomeia uma classe que implementa `Contracts\Transport`. Vêm `GuzzleTransport` (padrão, com o `GuzzleHttp\Client` registrado no container) e `LaravelHttpTransport` (`Http::fake`, `preventStrayRequests`, middleware global).
- Com o `LaravelHttpTransport`, o `getPrevious()` da `ZapmizerUnavailableException` pode mudar de classe conforme a versão do Laravel: uma exceção do `Http` do Laravel (`ConnectionException` etc.) ou, do Laravel 8 ao 11 em alguns casos (corpo cortado), a do Guzzle. É opt-in.
- `zapmizer.http.connect_timeout` e `zapmizer.http.timeout` (`ZAPMIZER_HTTP_CONNECT_TIMEOUT`, `ZAPMIZER_HTTP_TIMEOUT`), `null` por padrão.
- `illuminate/http` declarado como dependência.
- Um app que já tinha `zapmizer.http.connect_timeout`/`timeout` na config agora aplica esses timeouts nas chamadas JSON de partner e instância.
- O download de mídia grava direto no arquivo temporário (`sink`) com 60 s pra conectar e 600 s no total, no lugar do limite de 60 s por leitura e de qualquer timeout de um `GuzzleHttp\Client` registrado.
- Um download de mídia cortado (erro de transporte, ou corpo menor que o `Content-Length` sem `Transfer-Encoding`) lança `ZapmizerUnavailableException` e não deixa arquivo. Antes lançava um `RuntimeException` cru, ou devolvia um `attached` truncado quando a conexão fechava limpa.
- Com o diretório temporário sem escrita, uma resposta `200` de mídia lança `unexpectedResponse` (antes estourava `ErrorException`); os outros status seguem como antes.
- Um header passado na chamada com o mesmo nome de `Accept`, `X-Partner-Key`, `Authorization` ou `api-version`, em qualquer caixa, é descartado; vale o valor fixo, em vez de concatenar.
- Pasta `examples/` com código de app: teste com `Http::fake` via `LaravelHttpTransport`, um transporte próprio e um listener que guarda a mídia recebida num disk. Rodam na suíte do pacote.

**Erros:**

> **A quebra é silenciosa.** `CouldNotSendNotification` não existe mais, e em PHP um `catch (CouldNotSendNotification $e)` de uma classe que não existe não dá erro: só nunca casa, e a exceção volta a escapar. Procure esse nome no app antes de atualizar.

- `Exceptions\ZapmizerException` (abstrata) é a raiz de todas as exceções da lib: `catch (ZapmizerException $e)` pega as do connect, do envio e do verify-number.
- `Exceptions\ZapmizerApiException`, filha de `ZapmizerConnectException`: a Zapmizer recusou a chamada (4xx). `status()`, `error()` (o código da API, ou `null`), `reason()`, `errors()`, `payload()` e `apiError()`. Mensagem: `Zapmizer refused the request (HTTP {status}[, {error}])[: {reason}].`
- `Exceptions\ZapmizerRateLimitedException` (429), com `retryAfter(): ?int` lido do `Retry-After` em segundos; data HTTP, negativo ou texto dão `null`.
- `Exceptions\ErrorCode`: uma constante por código `error` do contrato da API (`ErrorCode::WINDOW_CLOSED`, `ErrorCode::BOT_OFFLINE`, ...). `error()` devolve string: um código novo chega ao app sem release da lib.
- `Support\ApiError` lê a resposta de erro nos quatro formatos do contrato; `Connect\ZapmizerApi::failure()` escolhe a exceção pelo status (401 → `ZapmizerUnauthorizedException`, 429 → `ZapmizerRateLimitedException`, outro 4xx → `ZapmizerApiException`).
- `ZapmizerUnauthorizedException`, `PartnerCredentialsException`, `InstanceGoneException` e `MediaRejectedException` passam a estender `ZapmizerApiException`; `MediaRateLimitedException` passa a estender `ZapmizerRateLimitedException`. Um `catch` de qualquer uma delas continua pegando.
- O envio (`Zapmizer::sendMessage()`, `sendMessageWithFile()`, `ZapmizerMessage::send()`) passa pelo transporte configurado em `zapmizer.http.transport`, como os clientes Connect.

De/para do envio:

| Antes | Agora |
| --- | --- |
| `CouldNotSendNotification` (4xx), `getPrevious()` `ClientException` | `ZapmizerApiException` (`status()`, `error()`, `reason()`, `errors()`); 401 → `ZapmizerUnauthorizedException`; 429 → `ZapmizerRateLimitedException` |
| `CouldNotSendNotification` (5xx, rede) | `ZapmizerUnavailableException` |
| `CouldNotSendNotification` (redirect, não-JSON) | `ZapmizerConnectException` (`unexpectedResponse`) |
| `CouldNotSendNotification` (sem token) | `ZapmizerUnauthorizedException` (`status()` 401, mesma mensagem) |
| `CouldNotSendNotification` (arquivo que não abre) | `ZapmizerConnectException` (`unreadableFile`) |
| `CouldNotSendNotification` (URL malformada, multipart inválido, exceção de transporte próprio ou de `preventStrayRequests`) | a exceção original, sem embrulho (não é `ZapmizerException`) |
| mensagem `` `{status} - {description}` `` | `Zapmizer refused the request (HTTP {status}[, {error}])[: {reason}].` |

Outras mudanças que podem quebrar:

- Um `catch (ZapmizerConnectException)` em volta de código que também envia passa a capturar falhas de envio (`ZapmizerApiException` e `ZapmizerUnavailableException` são filhas dela).
- `ZapmizerUnavailableException`, `PartnerCredentialsException` e `NoConnectableException` perdem o `render()`. Fora das rotas da lib (que respondem o mesmo JSON de hoje), quem chamava os clientes e contava com o 503/403 JSON automático passa a ter a resposta do handler do app; em troca, os callbacks `renderable` do app passam a valer para essas exceções.
- Quem chama `PartnerClient` fora do `ConnectController` perde o `render()` 503 `zapmizer_unavailable` nos 4xx que não são de credencial: `ZapmizerApiException` não se renderiza.
- `start()` reporta (`report()`) os 4xx do parceiro; um app com `ZapmizerUnavailableException` em `dontReport` passa a ver esses 4xx reportados, porque agora são `ZapmizerApiException`.
- `NoConnectableException` nas rotas da lib deixa de ir para o `report()`.
- O log `zapmizer: partner call failed.` passa a registrar o corpo cortado em 500 caracteres (antes, inteiro).
- Parceiro: 404 (fora do `exchangeCode`), 409, 422 e 429 deixam de ser `ZapmizerUnavailableException`.
- `InstanceClient::connection()`: 4xx que não é 401/404 deixa de ser `InstanceGoneException` (no `show?live=1`, 403/409/422/429 passam de `instance_gone` a `zapmizer_unavailable`; o contrato só declara 401 e 404 nessa rota).
- `InstanceClient` (`createWebhook`, `rotateWebhookSecret`, `deleteWebhook`, `media` fora de 422/429): 4xx deixa de ser `unexpectedResponse`.
- `ZapmizerConnection::media()` sem instância pareada passa de `ZapmizerUnauthorizedException` a `ZapmizerConnectException` (`notPaired()`).
- `MediaRejectedException`, `MediaRateLimitedException`, `InstanceGoneException`, `ZapmizerUnauthorizedException` e `PartnerCredentialsException` mudam de construtor (recebem `Support\ApiError`); as fábricas `mediaRejected()`/`mediaRateLimited()` também.
- `Retry-After` não numérico na mídia passa de `0` para `null` (mensagem sem `Retry in`). O `reason()` da mídia corta HTML em 500 caracteres e deixa de incluir valor escalar não-string de `errors` (número).
- Upload de arquivo: limites próprios de 60 s para conectar e 600 s no total (`Zapmizer::UPLOAD_CONNECT_TIMEOUT`/`UPLOAD_TIMEOUT`), no lugar do timeout do `GuzzleHttp\Client` registrado.
- `Zapmizer::sendMessage*` passam pelo transporte configurado: `Http::fake` passa a ver o envio com o `LaravelHttpTransport`, e os timeouts de `zapmizer.http.*` passam a valer no envio de texto (com o `LaravelHttpTransport` sem timeout configurado vale o padrão do `Http`, 30 s do Laravel 9 em diante). `Http::fake()` sem argumentos responde 200 sem corpo nem Content-Type, e o envio passa a lançar `unexpectedResponse`: fakeie com um corpo JSON.
- `Zapmizer`: o 2º argumento do construtor passa de `?GuzzleHttp\Client` a `?Contracts\Transport`; `setHttpClient()` e `httpClient()` saem. Um `zapmizer.http.transport` inválido passa a quebrar também `app(Zapmizer::class)` e, com ele, `ZapmizerMessage`.
- Subclasses: `InstanceClient::reasonFrom()` saiu (a regra está em `Support\ApiError`); `Zapmizer` ganhou `$transport`, `guardToken()` e as constantes `UPLOAD_CONNECT_TIMEOUT`/`UPLOAD_TIMEOUT`, que podem colidir com nomes de uma subclasse.
- Não quebra: `catch` de `ZapmizerConnectException`, `ZapmizerUnavailableException`, `ZapmizerUnauthorizedException`, `PartnerCredentialsException`, `InstanceGoneException`, `MediaRejectedException`, `MediaRateLimitedException`, `ZapmizerVerificationException` e filhas.

**Cliente de parceiro:**

- `PartnerClient::subscription(string $externalId): ?PartnerSubscription` lê `GET /partner/users/{externalId}`; 404 devolve `null`. `Connect\PartnerSubscription` tem `userId`, `teamId`, `externalId`, `subscribed`, `quantity` (`null` quando não veio número), `trialEndsAt` (`?CarbonImmutable`), `paymentIncomplete` e `hasAccess(?DateTimeInterface $now = null)`.
- `PartnerClient::checkout(string $externalId, string $redirectUri, ?string $state = null): PartnerCheckout` cria o checkout (`POST /partner/users/{externalId}/checkout`). `Connect\PartnerCheckout` tem `url` e `expiresAt` (`?CarbonImmutable`). 404 → `ZapmizerApiException` com `status()` 404; 409 → `ZapmizerApiException` com `error()` `already_subscribed` ou `payment_incomplete`; 422 do redirect → `errors()['redirect_uri']`.
- `createSession()` ganha `externalId` (o id do cliente no app, chave das duas chamadas acima) e limita `expires_in` a 900..86400 (`PartnerClient::MAX_EXPIRES_IN`).
- `ConnectToken` ganha `userId` e `hasNumber()` (`false` quando `phone_number` ou `bot_instance_id` vieram `null`).
- Datas ilegíveis (`expires_at`, `trial_ends_at`) viram `null` e logam `zapmizer: unreadable date.` (warning) com `field`, `value` e `external_id`.

Quebras:

- `createSession()`: `state` passa a ser opcional e o 2º parâmetro; entra `externalId` no fim. Quem chama com argumentos nomeados não muda; posicionais seguem na mesma ordem. Subclasse que sobrescreve `createSession()` com a assinatura antiga dá erro fatal de assinatura incompatível ao carregar e precisa atualizar a assinatura.
- `ConnectSession::$expiresAt` passa de `?string` a `?CarbonImmutable`; o JSON segue ISO 8601.
- `ConnectToken`: `teamId` deixa de ser nulo; entra `userId` (2º parâmetro do construtor); `user_id`/`team_id` inválidos lançam `unexpectedResponse`.
- `createSession()`, `subscription()` e `checkout()` lançam `InvalidArgumentException` para `external_id`/`state` inválidos antes de chamar a API.
- O JSON do `start()` passa a normalizar `expires_at` (`Z` → `+00:00`, sem frações).
- `connect/token` sem `user_id`/`team_id` válidos: o callback da lib responde `exchange_failed` e não grava a conexão (antes gravava com `zapmizer_team_id` null).
- `createSession()`: `expires_in` acima de 86400 é cortado; `redirect_uri`/`webhook_url` vazios vão no corpo (422 da API) em vez de sumirem.
- 404 e 409 de chamadas de parceiro deixam de logar `zapmizer: partner call failed.`.
- Subclasses: `PartnerClient` ganhou os métodos públicos `subscription()` e `checkout()`, a constante `MAX_EXPIRES_IN` e os métodos protegidos `guardExternalId()`, `guardState()` e `withoutNulls()`, que podem colidir com nomes de uma subclasse.

**Upgrade da 0.3:**

- `new PartnerClient($id, $secret, $guzzle, $uri)` → `new PartnerClient($id, $secret, new GuzzleTransport($guzzle), $uri)`.
- `new InstanceClient($token, $guzzle, $uri, $version)` → `new InstanceClient($token, new GuzzleTransport($guzzle), $uri, $version)`.
- `null` nessa posição continua valendo (um `GuzzleTransport` novo, sem a config); um `GuzzleHttp\Client` ali agora lança `TypeError`. Construído à mão, o cliente não lê `zapmizer.http.*`: para isso, resolva pelo container ou passe `app(Contracts\Transport::class)`.
- Subclasses: a propriedade `$http` e `InstanceClient::spool()` não existem mais; as chamadas passam pelo `$transport`.
- Quem publicou `config/zapmizer.php` com a chave `http` e quer trocar de transporte precisa acrescentar `'transport' => ...` dentro dela: o merge da config é só no primeiro nível.
- `new Zapmizer($token, $guzzle, $uri, $version)` → `new Zapmizer($token, new GuzzleTransport($guzzle), $uri, $version)`; `$zapmizer->setHttpClient($guzzle)` não existe mais: construa com o transporte.
- `catch (CouldNotSendNotification $e)` → `catch (ZapmizerException $e)` ou as classes da tabela "De/para do envio". Quem lia o status do `getPrevious()` (`ClientException`) passa a usar `$e->status()` de `ZapmizerApiException`.
- `new ConnectToken($token, $teamId, ...)` → `new ConnectToken($token, $userId, $teamId, ...)`.
- `$session->expiresAt` (string) → `$session->expiresAt?->toIso8601String()`.

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