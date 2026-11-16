# Instalar com um agente

Este é o roteiro para um agente de código (Claude Code, Cursor, Codex e afins) instalar a lib num app Laravel 8.83 a 13 e sair com duas coisas funcionando:

- **connect**: cada usuário (ou time) conecta o próprio número de WhatsApp numa janela da Zapmizer;
- **caixa de entrada embutida**: o atendimento do número abre dentro do app, num iframe.

O webhook (receber as mensagens no backend do app) é opcional e fica fora deste roteiro: veja [Webhook](#webhook-opcional) no fim.

Leia a página inteira antes de começar. Ela diz o que perguntar ao humano e quando parar.

## Prompt sugerido

```text
Instale a lib zapmizer/laravel-notification-zapmizer (2.0 beta) neste app, com o connect e a caixa de entrada embutida. Siga o roteiro de docs/instalar.md da lib.
```

## O que só o humano tem

Pergunte uma coisa por vez, e só o que você não conseguir descobrir lendo o app.

1. **Credenciais de parceiro**: `ZAPMIZER_PARTNER_ID` (só dígitos) e `ZAPMIZER_PARTNER_SECRET`. A Zapmizer emite as duas para a aplicação parceira. Nunca invente valores, não as coloque em código nem em commit: elas vão só no `.env`. Sem elas o connect não abre.
2. **Host liberado na conta de parceiro**: o host do `APP_URL` precisa estar na lista de hosts da conta de parceiro na Zapmizer, porque é ele que vai no `redirect_uri` e no `webhook_url` do connect e no `parent_origin` da caixa. Quem libera é a equipe da Zapmizer. Em produção a Zapmizer só aceita `https`, e o `redirect_uri` e o `webhook_url` precisam de um endereço público (`localhost` não serve).
3. **Quem é dono do número**: o usuário logado (`App\Models\User`, o padrão) ou outro model, como o time. Se o app tem times (Jetstream, `currentTeam`), pergunte. Sem times, use o usuário.

## Roteiro

### 1. Instalar o pacote

```bash
composer require "zapmizer/laravel-notification-zapmizer:^2.0@beta"
```

### 2. Rodar o instalador

```bash
php artisan zapmizer:install --model=App\\Models\\User --inbox --no-interaction
```

- `--model`: o model dono do número. Aceita o nome curto (`--model=Team` procura `App\Models\Team` e depois `App\Team`).
- `--inbox`: liga a caixa de entrada e publica os componentes Vue. Tire-o se o app não usa Vue (veja [Sem Inertia/Vue](#sem-inertiavue)).
- `--migrate`: roda as migrations no fim. Sem ele, rode `php artisan migrate` depois.
- `--force`: sobrescreve a config, a migration e os componentes já publicados. Sem ele, o que já existe fica como está.

O comando é idempotente: rodar de novo só faz o que falta. Sem `--no-interaction` ele pergunta o que as flags não disseram. O que ele faz:

| Passo | Resultado |
| --- | --- |
| config | `config/zapmizer.php`, se ainda não existe |
| migration | `database/migrations/*_create_zapmizer_connections_table.php`, uma vez só |
| `.env` e `.env.example` | acrescenta só as chaves ausentes: `ZAPMIZER_BASE_URI`, `ZAPMIZER_API_VERSION=2025-06-27`, `ZAPMIZER_PARTNER_ID=` e `ZAPMIZER_PARTNER_SECRET=` vazios, e `ZAPMIZER_INBOX_ENABLED=true` com `--inbox` |
| `--model` | `implements Connectable` e `use ConnectsZapmizer;` no model, com os `use` no topo |
| `--inbox` | os componentes em `resources/js` (os mesmos de `vendor:publish --tag=zapmizer-wizard`), se o `package.json` tem `vue` |

No fim ele imprime os **próximos passos** do app: siga-os.

Se ele avisar que não reconheceu o formato do model, ele não mexe no arquivo e imprime o trecho para colar. Cole à mão:

```php
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;

class User extends Authenticatable implements Connectable
{
    use ConnectsZapmizer;
}
```

### 3. Model que não é o usuário logado

O connect e a caixa resolvem o dono do número pelo `zapmizer.connect.resolver`, que por padrão devolve o usuário logado. Para conectar o time, crie um resolver e aponte a config para ele:

```php
<?php

namespace App\Zapmizer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;

class ResolvesCurrentTeam implements ResolvesConnectable
{
    public function resolve(Request $request): Model&Connectable
    {
        return $request->user()?->currentTeam
            ?? throw ZapmizerConnectException::noConnectable('The user has no current team.');
    }
}
```

```php
// config/zapmizer.php
'connect' => [
    'resolver' => App\Zapmizer\ResolvesCurrentTeam::class,
    // ...
],
```

Mais em [connect.md](connect.md), seção 4.

### 4. O front

Instale o `@zapmizer/embed` com o gerenciador do lockfile do app, fixando a tag. O instalador já imprime o comando certo, com `axios` e `lucide-vue-next` quando faltam (os componentes do connect usam os dois):

```bash
npm install github:zapmizer/embed#v0.1.2 axios lucide-vue-next   # package-lock.json ou nenhum lockfile
bun add github:zapmizer/embed#v0.1.2 axios lucide-vue-next       # bun.lock
pnpm add github:zapmizer/embed#v0.1.2 axios lucide-vue-next      # pnpm-lock.yaml
yarn add github:zapmizer/embed#v0.1.2 axios lucide-vue-next      # yarn.lock
```

Monte o `InboxScreen` numa página autenticada. Num app Inertia (Breeze ou Jetstream com Vue):

```php
// routes/web.php
Route::get('/atendimento', fn () => Inertia::render('Atendimento'))
    ->middleware('auth')
    ->name('atendimento');
```

```vue
<!-- resources/js/Pages/Atendimento.vue -->
<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InboxScreen from '@/components/zapmizer/InboxScreen.vue';
</script>

<template>
  <AuthenticatedLayout>
    <div style="height: calc(100vh - 8rem)">
      <InboxScreen :person="String($page.props.auth.user.id)" />
    </div>
  </AuthenticatedLayout>
</template>
```

- Use o layout autenticado que o app já tem; o caminho e o nome mudam de app para app (o Breeze usa `resources/js/Pages` e `@/Layouts/AuthenticatedLayout.vue`; os starter kits do Laravel 12 em diante, `resources/js/pages` e `@/layouts/AppLayout.vue`).
- `person` é quem atende, estável e por pessoa: `String(user.id)`, ou `` `${user.id}:${team.id}` `` quando a conexão é do time (a troca de time não reabre a caixa do outro).
- A caixa ocupa a altura do pai (no mínimo 560 px): dê altura ao contêiner.
- Sem conexão, o componente mostra o botão de conectar. Conectou, a caixa abre. Uma conexão revogada volta ao botão.
- Com o Ziggy no app, as URLs saem do `route()`. Sem ele, de `resources/js/composables/zapmizerRoute.ts`, com o prefixo padrão `/zapmizer`.

Logout, nas duas pontas ([logout-e-multiaba.md](https://github.com/zapmizer/embed/blob/v0.1.2/docs/logout-e-multiaba.md) do embed):

```ts
// resources/js/app.ts (ou app.js), uma vez por aba
import { router } from '@inertiajs/vue3';
import { endZapmizerEmbeds, listenToZapmizerLogout } from '@/composables/zapmizerEmbed';

// O logout feito em outra aba fecha a caixa desta.
listenToZapmizerLogout();

// Antes do POST /logout, venha ele do menu ou de um router.post().
router.on('before', (event) => {
  if (event.detail.visit.method === 'post' && event.detail.visit.url.pathname === '/logout') {
    endZapmizerEmbeds();
  }
});
```

- Ajuste o caminho se o logout do app não for `/logout`.
- Sem Inertia, chame `endZapmizerEmbeds()` no handler do logout, antes do POST.

Por fim, confira que o front compila: `npm run build` (ou o `build` do gerenciador do app).

### 5. Conferir

```bash
php artisan zapmizer:check          # para humanos: ✓, ✗, ! e -
php artisan zapmizer:check --json   # para agentes
php artisan zapmizer:check --offline
```

Sai com código diferente de zero quando algo crítico falha. A única chamada externa é `GET /api/partner/users/zapmizer-check` com a chave de parceiro: ela só lê, e a Zapmizer responde `401` para uma chave que não aceita e `200` ou `404` para uma que aceita. Sem rede, ou com `--offline`, essa checagem é pulada com aviso.

| `id` | Confere | Crítico |
| --- | --- | --- |
| `app_key` | `APP_KEY` definida (o token das conexões é gravado cifrado) | sim |
| `config` | `config/zapmizer.php` publicada | não |
| `base_uri` | `ZAPMIZER_BASE_URI` é uma URL http(s) absoluta | sim |
| `partner_credentials` | `ZAPMIZER_PARTNER_ID` (só dígitos) e `ZAPMIZER_PARTNER_SECRET` preenchidos | sim |
| `partner_api` | a Zapmizer aceita as credenciais | sim no `401`; aviso sem rede, em `5xx` ou `429` |
| `migration` | a tabela `zapmizer_connections` existe | sim |
| `connectable` | o model que o resolver usa implementa `Connectable` | sim |
| `connect_routes` | rotas do connect e do webhook registradas | sim |
| `inbox_routes` | rotas da caixa registradas, com `ZAPMIZER_INBOX_ENABLED=true` | sim |
| `embed_package` | `@zapmizer/embed` no `package.json`, com tag fixa | sim sem o pacote; aviso sem tag |
| `app_url` | `APP_URL` absoluto; aviso com `http` ou host local | sim só se não for URL |
| `parent_origin` | `ZAPMIZER_INBOX_PARENT_ORIGIN`, quando definido, é uma origem | sim |

No JSON, cada item traz `id`, `status` (`ok`, `fail`, `warn` ou `skip`), `message` e `hint`, e `ok` diz se nada falhou.

### 6. O que fica para o humano

- Preencher as credenciais de parceiro no `.env` e pedir a liberação do host (veja [O que só o humano tem](#o-que-só-o-humano-tem)).
- Conectar o número: abrir a tela, clicar em conectar e ler o QR code com o celular. O agente não faz essa parte.
- Testar o connect em desenvolvimento pede uma URL pública `https` (um túnel) liberada na conta de parceiro, com o `APP_URL` apontando para ela.

## Quando parar e pedir

| Sinal | O que fazer |
| --- | --- |
| `zapmizer:check` com `partner_credentials` em `fail` | Pare e peça as credenciais ao humano. Não invente, não procure em outro lugar. |
| `partner_api` em `fail` (`401`) | As credenciais estão erradas ou são de outro ambiente (`ZAPMIZER_BASE_URI`). Peça ao humano para conferir. |
| O connect responde `partner_unauthorized` (503) | Igual ao anterior. |
| A caixa mostra "Este endereço ainda não foi liberado" (`origin_not_allowed`) ou o connect falha com host não permitido | O host não está liberado na conta de parceiro. Peça ao humano para pedir a liberação à Zapmizer. |
| A caixa responde `missing_ability` (403) | A conexão foi feita antes de a caixa existir. O usuário precisa conectar de novo. |
| O instalador não reconheceu o model | Cole o trecho que ele imprimiu e rode o instalador de novo. |
| O app não tem Vue | Siga [Sem Inertia/Vue](#sem-inertiavue) e confirme com o humano onde a caixa entra. |
| Qualquer `fail` que você não entende | Mostre a saída de `zapmizer:check --json` ao humano. |

## Sem Inertia/Vue

As rotas da caixa (`zapmizer.inbox.session` e `zapmizer.inbox.keepalive`) servem a qualquer front. Numa página Blade, com o Vite do app:

```blade
{{-- resources/views/atendimento.blade.php --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<div id="caixa" style="position: relative; height: 80vh"
     data-person="{{ auth()->id() }}"
     data-session="{{ route('zapmizer.inbox.session') }}"
     data-keepalive="{{ route('zapmizer.inbox.keepalive') }}"></div>
<p id="caixa-estado"></p>
@vite('resources/js/atendimento.js')
```

```js
// resources/js/atendimento.js
import { createInbox } from '@zapmizer/embed/inbox';
import { defaultMessages } from '@zapmizer/embed/errors';

const el = document.getElementById('caixa');
const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

createInbox({
  container: el,
  brand: 'zapmizer',
  person: el.dataset.person,
  openSession: async () => {
    const response = await fetch(el.dataset.session, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: '{}',
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw { status: response.status, code: body.code ?? null, retryAfter: Number(response.headers.get('Retry-After')) || undefined };
    return body;
  },
  keepAlive: async () => {
    const response = await fetch(el.dataset.keepalive, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw { status: response.status };
  },
  frame: { configure: (iframe) => Object.assign(iframe.style, { position: 'absolute', inset: '0', width: '100%', height: '100%', border: '0' }) },
  onState: (state) => {
    document.getElementById('caixa-estado').textContent = state.status === 'error' ? (defaultMessages[state.code] ?? state.code) : '';
  },
});
```

- `not_connected` (409) quer dizer que o usuário ainda não conectou: mostre o connect. O botão de conectar faz `POST route('zapmizer.connect.start')` (com o CSRF), abre a `url` da resposta numa janela de 520×720 e espera o `postMessage` com `source: 'zapmizer-connect'` e `status: 'ok'`, conferindo `event.origin === window.location.origin`. Veja o `ConnectButton.vue` em `stubs/inertia-vue` como referência.
- No logout: `endEmbeds({ brand: 'zapmizer' })` de `@zapmizer/embed/logout` antes do POST, e `listenToLogout({ brand: 'zapmizer' })` em cada página.
- O guia completo do front está na doc do embed: [caixa-de-entrada.md](https://github.com/zapmizer/embed/blob/v0.1.2/docs/caixa-de-entrada.md) e [backend.md](https://github.com/zapmizer/embed/blob/v0.1.2/docs/backend.md).

## As rotas da caixa

| Rota | Método | Resposta |
| --- | --- | --- |
| `zapmizer.inbox.session` (`/zapmizer/inbox/session`) | POST, `throttle:30,1` | `200 {url, origin, resume_url, resume_until}` ou `{code}` |
| `zapmizer.inbox.keepalive` (`/zapmizer/inbox/keepalive`) | GET | `204` |

- Ficam atrás de `zapmizer.inbox.middleware` (padrão `web` e `auth`), sob o prefixo `zapmizer.routes.prefix`. O `web` traz a sessão e o CSRF.
- A sessão abre para a conexão do model que o `zapmizer.connect.resolver` devolve, a mesma do connect. Quem atende é o usuário logado: `user.id` é o `getAuthIdentifier()` e `user.name` o atributo `name` (até 100 caracteres).
- `parent_origin` é a origem da requisição (`getSchemeAndHttpHost()`). Atrás de um proxy que o app não confia (sem `TrustProxies`), ela sai `http://` ou com o host interno, e a Zapmizer responde `origin_not_allowed`: defina `ZAPMIZER_INBOX_PARENT_ORIGIN=https://seu-app.com`.
- O corpo do POST aceita só `theme` (`light` ou `dark`). Outro campo é ignorado.
- As respostas de erro seguem a [tabela do embed.md](embed.md#4-a-resposta) (a `EmbedSessionResponse`), mais duas da lib: `403 {"code": "no_connectable"}` quando o resolver não acha o que abrir, e `409 {"code": "not_connected"}` quando o model ainda não tem conexão.
- Desligue com `ZAPMIZER_INBOX_ENABLED=false`. `ZAPMIZER_ROUTES_ENABLED=false` desliga estas junto com as do connect.

## Webhook (opcional)

O connect já manda `route('zapmizer.webhook')` como `webhook_url`, e a lib recebe e confere as entregas assinadas. Para o app reagir às mensagens, ouça os eventos `MessageReceived` e `MessageSent`. Nada disso é preciso para o connect e a caixa funcionarem. Veja [connect.md](connect.md), seção 6, e [webhook.md](webhook.md).

## Laravel Boost

A lib traz diretrizes e uma skill para o [Laravel Boost](https://github.com/laravel/boost), em `resources/boost/`:

- `resources/boost/guidelines/core.md`: o resumo que entra no `CLAUDE.md`/`AGENTS.md` do app;
- `resources/boost/skills/zapmizer-whatsapp/SKILL.md`: a receita (instalar, conectar número, caixa de entrada, enviar WhatsApp).

Sem o Boost no app, nada disso é carregado: o agente só acha este roteiro se alguém apontar para ele (o prompt sugerido acima faz isso).

Com o Boost, o pacote precisa ser escolhido. O Boost só oferece pacotes que são dependência direta do app (no `composer.json`), e só inclui os escolhidos:

- `php artisan boost:install` interativo mostra a lista de pacotes de terceiros com diretrizes ou skills; marque `zapmizer/laravel-notification-zapmizer`;
- sem interação (`boost:install --no-interaction`), ele só inclui os pacotes já listados em `packages` no `boost.json`. Acrescente `"zapmizer/laravel-notification-zapmizer"` ao `packages` do `boost.json` e rode `php artisan boost:update`. O `boost:update` sem interação não pergunta por pacotes novos.

Conferido num app Laravel 13 novo com o Boost v2.10.4: depois do `boost:install --no-interaction` o `CLAUDE.md` não citava a lib; com `packages` no `boost.json` e `boost:update`, a diretriz entrou no `CLAUDE.md` e a skill em `.claude/skills/zapmizer-whatsapp/`. No código do Boost v2.10.4: `src/Console/InstallCommand.php` (linhas 263 a 276, a escolha dos pacotes de terceiros e o `--no-interaction`), `src/Install/ThirdPartyPackage.php` (linha 73, só dependências diretas), `src/Console/UpdateCommand.php` (linha 77, sem pergunta fora do modo interativo) e `src/Install/GuidelineComposer.php` (linhas 322 a 328, que leem `*.md` e `*.blade.php` de `resources/boost/guidelines`).
