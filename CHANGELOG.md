# Changelog

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