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

1. **URL da API da conta de parceiro** (`ZAPMIZER_BASE_URI`): `https://app.zapmizer.com/api/` ou `https://app.parlichat.com/api/`, ou outra (um ambiente local, por exemplo). A conta de parceiro, as credenciais e a [marca do embed](#a-marca-do-embed) são de um deploy só. Pergunte junto das credenciais. **Quando a URL não é uma das duas, pergunte também a marca do deploy** (`zapmizer` ou `parli`): o instalador tenta lê-la do próprio deploy, mas, se ele não responder, só o humano sabe.
2. **Credenciais de parceiro**: `ZAPMIZER_PARTNER_ID` (só dígitos) e `ZAPMIZER_PARTNER_SECRET`. A Zapmizer emite as duas para a aplicação parceira. Nunca invente valores, não as coloque em código nem em commit: elas vão só no `.env`. Sem elas o connect não abre.
3. **Host liberado na conta de parceiro**: o host do `APP_URL` precisa estar na lista de hosts da conta de parceiro na Zapmizer, porque é ele que vai no `redirect_uri` e (com o webhook ligado) no `webhook_url` do connect e no `parent_origin` da caixa. Quem libera é a equipe da Zapmizer. Em produção a Zapmizer só aceita `https`, e o `redirect_uri` e o `webhook_url` precisam de um endereço público (`localhost` não serve).
4. **Quem é dono do número**: o usuário logado (`App\Models\User`, o padrão) ou outro model, como o time. Se o app tem times (Jetstream, `currentTeam`), pergunte. Sem times, use o usuário.

## Roteiro

### 1. Instalar o pacote

```bash
composer require "zapmizer/laravel-notification-zapmizer:^2.0.0-beta.4@beta"
```

### 2. Rodar o instalador

A `ZAPMIZER_BASE_URI` vem antes do instalador, porque é dela que ele tira a marca do embed (`VITE_ZAPMIZER_BRAND`). Passe-a em `--base-uri` (ou ponha no `.env` antes):

```bash
php artisan zapmizer:install --base-uri=https://app.parlichat.com/api/ --model=App\\Models\\User --inbox --no-interaction
```

- `--base-uri`: a URL da API da conta de parceiro, terminando em `/api`. Grava `ZAPMIZER_BASE_URI` quando a chave falta no `.env`. Uma chave que já tem valor nunca é sobrescrita: um `--base-uri` diferente dela faz o comando avisar e sair com erro (troque à mão, se for o caso). Sem a flag e sem a chave, ele grava `https://app.zapmizer.com/api/` e pede, nos próximos passos, que você confirme o deploy com o humano.

- `--model`: o model dono do número. Aceita o nome curto (`--model=Team` procura `App\Models\Team` e depois `App\Team`). Liga as [rotas do connect](#as-rotas-da-lib) (`ZAPMIZER_ROUTES_CONNECT=true`).
- `--inbox`: liga a caixa de entrada (`ZAPMIZER_ROUTES_INBOX=true`, e o connect junto, de que ela depende) e publica os componentes Vue. Tire-o se o app não usa Vue (veja [Sem Inertia/Vue](#sem-inertiavue)).
- `--webhook`: liga o webhook da lib (`ZAPMIZER_ROUTES_WEBHOOK=true`), a rota pública assinada por onde as conexões recebem mensagens e eventos. Sem ele o connect funciona, mas as conexões só enviam. Veja [Webhook](#webhook-opcional).
- `--migrate`: roda as migrations no fim. Sem ele, rode `php artisan migrate` depois.
- `--force`: sobrescreve a config, a migration e os componentes já publicados. Sem ele, o que já existe fica como está.

O comando é idempotente: rodar de novo só faz o que falta. Sem `--no-interaction` ele pergunta o que as flags não disseram. O que ele faz:

| Passo | Resultado |
| --- | --- |
| config | `config/zapmizer.php`, se ainda não existe |
| migration | `database/migrations/*_create_zapmizer_connections_table.php`, uma vez só |
| `.env` e `.env.example` | acrescenta só as chaves ausentes (as presentes, inclusive as credenciais, ficam como estão): `ZAPMIZER_BASE_URI`, `ZAPMIZER_API_VERSION=2025-06-27`, `ZAPMIZER_PARTNER_ID=` e `ZAPMIZER_PARTNER_SECRET=` vazios, `VITE_ZAPMIZER_BRAND` (a [marca do embed](#a-marca-do-embed); num host fora dos deploys conhecidos, também `ZAPMIZER_EMBED_BRAND`, quando o deploy diz a marca) e as chaves das [rotas](#as-rotas-da-lib) que as flags pediram: `ZAPMIZER_ROUTES_CONNECT=true` com `--model` ou `--inbox`, `ZAPMIZER_ROUTES_WEBHOOK=true` com `--webhook`, `ZAPMIZER_ROUTES_INBOX=true` com `--inbox` (uma dessas já presente com outro valor é ligada) |
| `--model` | `implements Connectable` e `use ConnectsZapmizer;` no model, com os `use` no topo |
| `--inbox` | os componentes em `resources/js/zapmizer/` (os mesmos de `vendor:publish --tag=zapmizer-wizard --tag=zapmizer-inbox`), se o `package.json` tem `vue`. Sem `vue`, nada é publicado e os próximos passos apontam para o [caminho Blade](#sem-inertiavue) |

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
npm install github:zapmizer/embed#v0.1.3 axios lucide-vue-next   # package-lock.json ou nenhum lockfile
bun add github:zapmizer/embed#v0.1.3 axios lucide-vue-next       # bun.lock
pnpm add github:zapmizer/embed#v0.1.3 axios lucide-vue-next      # pnpm-lock.yaml
yarn add github:zapmizer/embed#v0.1.3 axios lucide-vue-next      # yarn.lock
```

O `@zapmizer/embed/vue` pede **Vue 3.3 ou mais novo**: com um `vue` mais antigo no `package.json`, suba-o antes (o `zapmizer:check` avisa).

A partir da v0.1.3 os tipos do embed resolvem também com `moduleResolution: node` no `tsconfig.json`: não acrescente `paths` para o `@zapmizer/embed` nem mexa no `tsconfig.json` por causa dele. Com uma tag anterior, o `vue-tsc` de um app em `moduleResolution: node` não acha os tipos: suba para a v0.1.3 (o `zapmizer:check` avisa em `embed_package`).

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
import InboxScreen from '@/zapmizer/InboxScreen.vue';
</script>

<template>
  <AuthenticatedLayout>
    <div style="height: calc(100vh - 8rem)">
      <InboxScreen />
    </div>
  </AuthenticatedLayout>
</template>
```

- Use o layout autenticado que o app já tem; o caminho e o nome mudam de app para app (o Breeze usa `resources/js/Pages` e `@/Layouts/AuthenticatedLayout.vue`; os starter kits do Laravel 12 em diante, `resources/js/pages` e `@/layouts/AppLayout.vue`).
- Não passe `person`: o `InboxScreen` o recebe do servidor, no `GET zapmizer.connect.show` (`{connection, person}`). É quem atende, `{usuário}:{model}:{id}`, montado com o `getAuthIdentifier()` do usuário logado (qualquer que seja o nome da chave primária, `id`, `user_id`, um uuid) e o model que o resolver devolve. O `@zapmizer/embed` guarda a retomada da caixa na chave `<marca>-inbox:<person>` e fecha a caixa quando ele muda: outro usuário, ou o mesmo em outro time, não retoma a caixa deste. Não leia o usuário de `$page.props` para isso. A prop `person` existe só para sobrescrever (`null` desliga a retomada).
- A caixa ocupa a altura do pai (no mínimo 560 px): dê altura ao contêiner.
- Sem conexão, o componente mostra o botão de conectar. Conectou, a caixa abre. Uma conexão revogada, ou anterior à caixa (`missing_ability`), volta ao botão.
- Os componentes ficam todos em `resources/js/zapmizer/`, uma pasta só deles, e se importam entre si por caminho relativo: funcionam com qualquer alias (ou nenhum) e não colidem com as pastas `components/`/`Components/` do app. A página os importa como o app já importa de `resources/js`: `@/zapmizer/...` com o alias `@` (o do `laravel-vite-plugin` e dos starter kits), `~/zapmizer/...` num app com `~`, ou o caminho relativo. Não mova os arquivos nem crie alias por causa deles.
- Com o Ziggy no app, as URLs saem do `route()`. Sem ele, de `resources/js/zapmizer/zapmizerRoute.ts`, com o prefixo padrão `/zapmizer`.

Logout, nas duas pontas ([logout-e-multiaba.md](https://github.com/zapmizer/embed/blob/v0.1.3/docs/logout-e-multiaba.md) do embed):

```ts
// resources/js/app.ts (ou app.js), uma vez por aba
import { router } from '@inertiajs/vue3';
import { endZapmizerEmbeds, listenToZapmizerLogout } from '@/zapmizer/zapmizerEmbed';

// O logout feito em outra aba fecha a caixa desta.
listenToZapmizerLogout();

// Antes do POST /logout, venha ele do menu ou de um router.post().
router.on('before', (event) => {
  if (event.detail.visit.method === 'post' && event.detail.visit.url.pathname === '/logout') {
    endZapmizerEmbeds();
  }
});
```

Com o Inertia 0.x (comum no Laravel 8 e 9: `@inertiajs/inertia` e `@inertiajs/inertia-vue3`), o evento é o mesmo, no objeto `Inertia`:

```js
import { Inertia } from '@inertiajs/inertia';
import { endZapmizerEmbeds, listenToZapmizerLogout } from '@/zapmizer/zapmizerEmbed';

listenToZapmizerLogout();

Inertia.on('before', (event) => {
  if (event.detail.visit.method === 'post' && event.detail.visit.url.pathname === '/logout') {
    endZapmizerEmbeds();
  }
});
```

- Ajuste o caminho se o logout do app não for `/logout`.
- Sem Inertia, chame `endZapmizerEmbeds()` no handler do logout, antes do POST.

Por fim, confira que o front compila: `npm run build` (ou o `build` do gerenciador do app). O build também grava no bundle o `VITE_ZAPMIZER_BRAND` do `.env`: mudou a marca ou a `ZAPMIZER_BASE_URI`, rebuilde.

### A marca do embed

O iframe marca cada evento com `source: "<marca>-embed"`, e o `@zapmizer/embed` descarta qualquer evento de outra marca (`src/messages.ts`, linha 12, do embed). Cada deploy da Zapmizer emite a sua: o servidor monta o `source` com o `VITE_BRAND` do deploy (`resources/js/Pages/Embed/embedBridge.ts` e `config/app.php` do servidor, padrão `zapmizer`), e o deploy da Parli (`app.parlichat.com`) manda `parli-embed`. Com a marca errada a caixa abre o iframe e nunca recebe o `ready`: depois de 45 s vira `ready_timeout`.

A regra: **a marca é a do deploy da `ZAPMIZER_BASE_URI`**.

| `ZAPMIZER_BASE_URI` | Marca |
| --- | --- |
| `https://app.zapmizer.com/api/` (o padrão) | `zapmizer` |
| `https://app.parlichat.com/api/` (qualquer host em `parlichat.com`) | `parli` |
| outro host (um servidor local, um staging) | a que o deploy publica em `/brand/manifest.json`; sem resposta, pergunte ao humano |

Num host fora da tabela, a lib pergunta a marca ao próprio deploy: `GET {raiz web}/brand/manifest.json` (a `ZAPMIZER_BASE_URI` sem o `/api`, ex.: `http://localhost:8001/brand/manifest.json`), um arquivo estático, sem login e sem efeito colateral, cujo `name` é o nome da marca (`Parli`, `Zapmizer`). É o mesmo `VITE_BRAND` que o iframe usa: no servidor, o build do Vite liga `public/brand` a `resources/brands/${VITE_BRAND}/public/assets` (`resources/bundler/plugins/brand-public-assets-plugin.ts`), e a página do embed monta o `source` com o mesmo `VITE_BRAND` (`resources/js/app-name.ts`, `brandKey`). O `manifest.json` é o do PWA, linkado no `<head>` de `resources/views/app.blade.php`; não é um contrato de API, por isso um deploy que não o serve (ou que nomeia uma marca que a lib não conhece) cai na pergunta ao humano.

- O `zapmizer:install` lê o manifest quando o host não está na tabela e grava a marca em `VITE_ZAPMIZER_BRAND` e em `ZAPMIZER_EMBED_BRAND` (para o backend, que derivaria `zapmizer` do host). Sem resposta, grava `zapmizer` e manda, nos próximos passos, confirmar a marca com o humano.

- No backend, `zapmizer.inbox.brand` (`ZAPMIZER_EMBED_BRAND`) vale quando definida; vazia, a lib deriva do host da `base_uri` (`NotificationChannels\Zapmizer\Support\EmbedBrand::resolve()`). Defina-a só para um deploy fora da tabela.
- O front precisa da marca já no boot (o `listenToLogout` roda antes de qualquer sessão), então ela vem do `VITE_ZAPMIZER_BRAND` do `.env`, lido no build. O `zapmizer:install` grava o valor derivado, só se a chave não existir. Trocou a `base_uri`, troque o `VITE_ZAPMIZER_BRAND` e rebuilde.
- O `zapmizer:check` falha (`embed_brand`) quando a caixa está ligada e o `VITE_ZAPMIZER_BRAND` do `.env` falta ou é outro que a marca da config. Num host fora da tabela ele lê o manifest do deploy: falha se o front tem outra marca, avisa se só a config (o caminho Blade) diverge, e avisa para confirmar com o humano quando o deploy não diz a marca (ou com `--offline`).
- A retomada da caixa fica na chave `<marca>-inbox:<person>` do `sessionStorage`, e o logout entre abas usa o canal da marca: use a mesma marca no `endEmbeds` e no `listenToLogout`.

### 5. Conferir

```bash
php artisan zapmizer:check          # para humanos: ✓, ✗, !, - e ○
php artisan zapmizer:check --json   # para agentes
php artisan zapmizer:check --offline
```

O check confere **só o que está ligado**: o envio sempre; o connect, o webhook, a caixa de entrada e o verify-number só com o grupo ligado (`ZAPMIZER_ROUTES_*`). Um grupo desligado aparece como `off` (○), com a dica de como ligar, e não conta como falha nem como aviso: um app que só envia passa com todos os grupos em `off`. As dependências entre os grupos continuam valendo: a caixa ligada sem o connect falha, e o connect ligado sem o webhook avisa.

Sai com código diferente de zero só quando algo crítico falha (`fail`); `ok`, `warn`, `skip` e `off` saem com 0. As chamadas externas só leem:

- `GET {ZAPMIZER_BASE_URI}/bot-instances` com o `ZAPMIZER_API_TOKEN`, quando ele está preenchido: a lista de números que o token vê, sem efeito colateral. `401` recusa o token; uma lista vazia avisa que nada sai até o time conectar um número.
- Com o connect ligado, `GET {ZAPMIZER_BASE_URI}/partner/users/zapmizer-check` com a chave de parceiro. A Zapmizer responde `401` para uma chave que não aceita; aceitando, responde `200` com o cliente ou o `404` de cliente desconhecido, `{"message": "Record not found.", "details": "Record not found."}`. Só esses dois provam a chave: um 404 de rota (uma `base_uri` sem `/api`, ou outro servidor) falha com a dica de conferir a `ZAPMIZER_BASE_URI`. A evidência está no servidor: `ResolvesPartnerUser` faz `abort_if(..., 404, 'Record not found.')`, e o handler de 404 de `api/*` (`bootstrap/app.php`) responde `message: "Record not found."` com a mensagem original em `details`.
- Com a caixa ligada num host fora dos deploys conhecidos, o `GET /brand/manifest.json` do deploy (veja [A marca do embed](#a-marca-do-embed)).

Sem rede, ou com `--offline`, as checagens remotas são puladas (`skip`, ou aviso quando a Zapmizer não responde).

| `id` | Confere | Quando | Crítico |
| --- | --- | --- | --- |
| `app_key` | `APP_KEY` definida (o token das conexões é gravado cifrado) | connect | sim |
| `config` | `config/zapmizer.php` publicada | sempre | não (aviso com algum grupo ligado; `skip` num app que só envia) |
| `base_uri` | `ZAPMIZER_BASE_URI` é uma URL http(s) absoluta terminando em `/api` | sempre | sim |
| `api_token` | `ZAPMIZER_API_TOKEN` preenchido | sempre | sim sem o connect ou com o verify-number; `skip` com o connect (cada conexão envia com o próprio token) |
| `send_api` | a Zapmizer aceita o `ZAPMIZER_API_TOKEN` (`GET /bot-instances`) | com o token | sim no `401`, num `4xx` ou numa resposta que não é a API; aviso sem rede, em `5xx`, `429` ou sem número |
| `from_number` | `ZAPMIZER_FROM_NUMBER`, quando definido, é um número (10 a 15 dígitos, máscara e `@c.us` à parte) | sempre | sim; `skip` vazio |
| `partner_credentials` | `ZAPMIZER_PARTNER_ID` (só dígitos) e `ZAPMIZER_PARTNER_SECRET` preenchidos | connect | sim |
| `partner_api` | a Zapmizer aceita as credenciais (`200` com o cliente ou `404 Record not found.`) | connect | sim no `401`/`403`, num 404 de rota ou numa resposta que não é a API; aviso sem rede, em `5xx` ou `429` |
| `migration` | a tabela `zapmizer_connections` existe | connect | sim |
| `connectable` | o model que o resolver usa implementa `Connectable` | connect | sim |
| `connect_routes` | as rotas do connect e do reconnect registradas | connect | sim |
| `webhook_routes` | a rota do webhook registrada (e a por conexão, com `ZAPMIZER_WEBHOOK_PER_CONNECTION`) | webhook | sim com a rota ausente; aviso com o connect ligado e o webhook não (as conexões não recebem mensagens) |
| `inbox_routes` | o connect ligado e as rotas da caixa registradas | caixa | sim; aviso quando o app ainda tem a `ZAPMIZER_INBOX_ENABLED`, que não liga mais nada |
| `verify_number_routes` | a rota `zapmizer.verify_number` registrada, o model de usuário com `MustVerifyWhatsapp` e a tabela `whatsapp_verifieds` | verify-number | sim |
| `embed_package` | `@zapmizer/embed` no `package.json`, com tag fixa a partir da v0.1.3 | caixa | sim sem o pacote; aviso sem tag ou com tag anterior à v0.1.3 |
| `vue_version` | aparece só com a caixa ligada e um `vue` < 3.3 no `package.json` | caixa | não (aviso) |
| `embed_brand` | `VITE_ZAPMIZER_BRAND` do `.env` igual à marca do deploy da `ZAPMIZER_BASE_URI` (pelo host conhecido, ou lida de `/brand/manifest.json` do deploy) | caixa | sim; aviso num host fora da tabela que não diz a marca |
| `app_url` | `APP_URL` absoluto; aviso com `http` ou host local | algum grupo | sim só se não for URL |
| `parent_origin` | `ZAPMIZER_INBOX_PARENT_ORIGIN`, quando definido, é uma origem | caixa | sim |

Fora do "Quando", o item sai como `off`. No JSON, cada item traz `id`, `status`, `message` e `hint` (a dica, ou `null`); `ok` diz se nada falhou. O `status` é um destes, e a lista não muda sem aviso no CHANGELOG:

| `status` | Significa | Exit code |
| --- | --- | --- |
| `ok` | conferido e certo | 0 |
| `warn` | funciona, mas vale olhar | 0 |
| `skip` | não deu ou não precisou conferir (sem rede, `--offline`, sem o valor opcional) | 0 |
| `off` | o grupo está desligado: nada dele foi conferido; o `hint` diz como ligar (desde a 2.0.0-beta.5) | 0 |
| `fail` | crítico | 1 |

### 6. O que fica para o humano

- Preencher as credenciais de parceiro no `.env` e pedir a liberação do host (veja [O que só o humano tem](#o-que-só-o-humano-tem)).
- Conectar o número: abrir a tela, clicar em conectar e ler o QR code com o celular. O agente não faz essa parte.
- Testar o connect em desenvolvimento **contra o deploy de produção** (`app.zapmizer.com`, `app.parlichat.com`) pede uma URL pública `https` (um túnel) liberada na conta de parceiro, com o `APP_URL` apontando para ela. Contra um Zapmizer local, `http` funciona: o `https` só é exigido em produção.

## Quando parar e pedir

| Sinal | O que fazer |
| --- | --- |
| `zapmizer:check` com `partner_credentials` em `fail` | Pare e peça as credenciais ao humano. Não invente, não procure em outro lugar. |
| `partner_api` em `fail` (`401`) | As credenciais estão erradas ou são de outro ambiente (`ZAPMIZER_BASE_URI`). Peça ao humano para conferir. |
| O connect responde `partner_unauthorized` (503) | Igual ao anterior. |
| A caixa mostra "Este endereço ainda não foi liberado" (`origin_not_allowed`) ou o connect falha com host não permitido | O host não está liberado na conta de parceiro. Peça ao humano para pedir a liberação à Zapmizer. |
| A caixa fica em "Demorou demais para abrir" (`ready_timeout`) | Confira a [marca do embed](#a-marca-do-embed): `zapmizer:check` (`embed_brand`) e rebuild do front. |
| A caixa volta ao botão de conectar com "A conexão com o WhatsApp precisa ser refeita" | O token foi revogado, não decifra mais (APP_KEY trocada) ou é de uma conexão anterior à caixa (`missing_ability`, `not_a_partner_connection`): o usuário conecta de novo. |
| `partner_api` falha com "a rota de parceiro não existe" | A `ZAPMIZER_BASE_URI` não é a API (falta o `/api`?). Confirme o deploy com o humano. |
| O instalador não reconheceu o model | Cole o trecho que ele imprimiu e rode o instalador de novo. |
| O app não tem Vue | Siga [Sem Inertia/Vue](#sem-inertiavue) e confirme com o humano onde a caixa entra. |
| Qualquer `fail` que você não entende | Mostre a saída de `zapmizer:check --json` ao humano. |

## Sem Inertia/Vue

As rotas da lib (connect e caixa) servem a qualquer front. Numa página Blade, com o Vite do app e só o `@zapmizer/embed` instalado (`npm install github:zapmizer/embed#v0.1.3`, ou o gerenciador do lockfile), são três peças: a página, o script da página e o logout no layout.

A página:

```blade
{{-- resources/views/atendimento.blade.php, numa rota com o middleware auth --}}
<meta name="csrf-token" content="{{ csrf_token() }}">

<div id="zapmizer"
     data-brand="{{ \NotificationChannels\Zapmizer\Support\EmbedBrand::resolve() }}"
     data-show-url="{{ route('zapmizer.connect.show') }}"
     data-start-url="{{ route('zapmizer.connect.start') }}"
     data-session-url="{{ route('zapmizer.inbox.session') }}"
     data-keepalive-url="{{ route('zapmizer.inbox.keepalive') }}">
  <div data-connect hidden>
    @php($marca = \NotificationChannels\Zapmizer\Support\EmbedBrand::name())
    <p>Na janela que abre você entra na sua conta {{ $marca }}, autoriza e lê o QR code com o celular.</p>
    <p data-connect-message role="alert"></p>
    <button type="button" data-connect-start>Conectar com {{ $marca }}</button>
    <button type="button" data-connect-recheck>Já conectei</button>
  </div>
  <div data-inbox hidden style="position: relative; height: 80vh"></div>
  <p data-inbox-message role="status"></p>
</div>

@vite('resources/js/zapmizer.js')
```

O script (não precisa ler nenhum código da lib):

```js
// resources/js/zapmizer.js
import { createInbox } from '@zapmizer/embed/inbox';
import { defaultMessages } from '@zapmizer/embed/errors';

const root = document.getElementById('zapmizer');
const { brand, showUrl, startUrl, sessionUrl, keepaliveUrl } = root.dataset;
const connectBox = root.querySelector('[data-connect]');
const connectMessage = root.querySelector('[data-connect-message]');
const inboxBox = root.querySelector('[data-inbox]');
const inboxMessage = root.querySelector('[data-inbox-message]');
const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
let inbox = null;

// Quem atende, como o servidor diz: a chave da retomada da caixa.
let person = null;

// GET zapmizer.connect.show: { connection, person }; connection é null sem conexão.
async function currentConnection() {
  const response = await fetch(showUrl, { headers: JSON_HEADERS, credentials: 'same-origin' });
  if (!response.ok) throw new Error(`show ${response.status}`);
  const body = await response.json();
  person = body.person ?? null;
  return body.connection;
}

function showConnect(message = '') {
  inbox?.destroy();
  inbox = null;
  inboxBox.hidden = true;
  inboxMessage.textContent = '';
  connectBox.hidden = false;
  connectMessage.textContent = message;
}

function showInbox() {
  connectBox.hidden = true;
  inboxBox.hidden = false;
  if (inbox) return;

  inbox = createInbox({
    container: inboxBox,
    brand, // a marca do deploy da ZAPMIZER_BASE_URI
    person, // do show: o usuário logado e o model da conexão
    openSession: async () => {
      const response = await fetch(sessionUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { ...JSON_HEADERS, 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: '{}',
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw { status: response.status, code: typeof body.code === 'string' ? body.code : null, retryAfter: Number(response.headers.get('Retry-After')) || undefined };
      }
      return body;
    },
    keepAlive: async () => {
      const response = await fetch(keepaliveUrl, { headers: JSON_HEADERS, credentials: 'same-origin' });
      if (!response.ok) throw { status: response.status };
    },
    frame: { configure: (iframe) => Object.assign(iframe.style, { position: 'absolute', inset: '0', width: '100%', height: '100%', border: '0' }) },
    onState: (state) => {
      if (state.status === 'error' && (state.code === 'not_connected' || state.action === 'reconnect')) {
        // Sem conexão, token revogado ou conexão anterior à caixa: conectar de novo.
        showConnect(defaultMessages[state.code] ?? 'Conecte o WhatsApp para abrir a caixa de entrada.');
        return;
      }
      inboxMessage.textContent = state.status === 'opening' || state.status === 'loading'
        ? 'Abrindo a caixa de entrada…'
        : state.status === 'error' ? (defaultMessages[state.code] ?? 'Não foi possível abrir a caixa de entrada.') : '';
      if (state.status === 'error' && state.action === 'retry') inboxMessage.append(retryButton());
      if (state.status === 'error' && state.action === 'reload') inboxMessage.append(button('Recarregar a página', () => window.location.reload()));
    },
  });
}

function button(text, onClick) {
  const element = document.createElement('button');
  element.type = 'button';
  element.textContent = text;
  element.addEventListener('click', onClick);
  return element;
}

function retryButton() {
  return button('Tentar de novo', () => inbox?.retry());
}

// Confere o estado no servidor e mostra a caixa ou o connect.
async function refresh(message = '') {
  try {
    (await currentConnection()) ? showInbox() : showConnect(message);
  } catch {
    showConnect('Não foi possível carregar o estado da integração. Recarregue a página.');
  }
}

// Conectar: POST zapmizer.connect.start devolve { url }; ela abre num popup.
root.querySelector('[data-connect-start]').addEventListener('click', async () => {
  connectMessage.textContent = '';
  const response = await fetch(startUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { ...JSON_HEADERS, 'X-CSRF-TOKEN': csrf() },
  });
  if (!response.ok) {
    connectMessage.textContent = response.status === 503
      ? 'O WhatsApp está indisponível no momento. Tente de novo em instantes.'
      : 'Não foi possível iniciar a conexão. Tente de novo.';
    return;
  }
  const popup = window.open((await response.json()).url, 'zapmizer-connect', 'width=520,height=720');
  if (!popup) {
    connectMessage.textContent = 'O navegador bloqueou a janela: libere popups para este site e tente de novo.';
    return;
  }
  // O popup fechado à mão não manda mensagem: confere o estado quando ele fecha.
  const watch = setInterval(() => {
    if (popup.closed) {
      clearInterval(watch);
      refresh();
    }
  }, 1000);
});

// "Já conectei": o usuário diz que terminou; quem decide é o servidor.
root.querySelector('[data-connect-recheck]').addEventListener('click', () => refresh('Ainda não há número conectado.'));

// O popup fala com esta página por postMessage: { source: 'zapmizer-connect', status, message }.
window.addEventListener('message', (event) => {
  if (event.origin !== window.location.origin || event.data?.source !== 'zapmizer-connect') return;
  if (event.data.status === 'ok' || event.data.status === 'webhook_failed') {
    // webhook_failed também conecta: o número envia, só não recebe pelo webhook.
    refresh();
    return;
  }
  connectMessage.textContent = event.data.message || 'Não foi possível concluir a conexão. Tente de novo.';
});

refresh();
```

O logout, no layout de todas as páginas autenticadas:

```blade
{{-- no <head> do layout --}}
<meta name="zapmizer-brand" content="{{ \NotificationChannels\Zapmizer\Support\EmbedBrand::resolve() }}">
@vite('resources/js/zapmizer-logout.js')
```

```js
// resources/js/zapmizer-logout.js
import { endEmbeds, listenToLogout } from '@zapmizer/embed/logout';

const brand = document.querySelector('meta[name="zapmizer-brand"]')?.content || 'zapmizer';

// O logout de outra aba fecha a caixa desta.
listenToLogout({ brand });

// Antes do POST /logout: fecha a caixa, apaga a retomada e avisa as outras abas.
document.addEventListener('submit', (event) => {
  if (event.target instanceof HTMLFormElement && new URL(event.target.action).pathname === '/logout') {
    endEmbeds({ brand });
  }
});
```

- Os textos seguem a marca do deploy: `EmbedBrand::name()` dá `Parli` ou `Zapmizer` (a mesma marca do `data-brand`). Nos componentes Vue, `resources/js/zapmizer/zapmizerBrand.ts` faz o mesmo a partir do `VITE_ZAPMIZER_BRAND` ("Conectar com a Parli", "Conectar com o Zapmizer").
- Ponha `resources/js/zapmizer.js` e `resources/js/zapmizer-logout.js` no `input` do `laravel-vite-plugin` em `vite.config.js`.
- Os `status` do popup que não conectam são `denied`, `plan_limit`, `subscription_required`, `invalid_state`, `exchange_failed`, `rate_limited`, `team_already_connected` e `no_connectable`; o `message` que vem junto já é um texto para a pessoa.
- O guia do front do embed: [caixa-de-entrada.md](https://github.com/zapmizer/embed/blob/v0.1.3/docs/caixa-de-entrada.md) e [backend.md](https://github.com/zapmizer/embed/blob/v0.1.3/docs/backend.md).

## As rotas da caixa

| Rota | Método | Resposta |
| --- | --- | --- |
| `zapmizer.inbox.session` (`/zapmizer/inbox/session`) | POST, `throttle:30,1` | `200 {url, origin, resume_url, resume_until}` ou `{code}` |
| `zapmizer.inbox.keepalive` (`/zapmizer/inbox/keepalive`) | GET | `204` |

- Ficam atrás de `zapmizer.inbox.middleware` (padrão `web` e `auth`), sob o prefixo `zapmizer.routes.prefix`. O `web` traz a sessão e o CSRF.
- A sessão abre para a conexão do model que o `zapmizer.connect.resolver` devolve, a mesma do connect. Quem atende é o usuário logado: `user.id` é o `getAuthIdentifier()` e `user.name` o atributo `name` (até 100 caracteres).
- `parent_origin` é a origem da requisição (`getSchemeAndHttpHost()`). Atrás de um proxy que o app não confia (sem `TrustProxies`), ela sai `http://` ou com o host interno, e a Zapmizer responde `origin_not_allowed`: defina `ZAPMIZER_INBOX_PARENT_ORIGIN=https://seu-app.com`.
- O corpo do POST aceita só `theme` (`light` ou `dark`). Outro campo é ignorado.
- As respostas de erro seguem a [tabela do embed.md](embed.md#4-a-resposta) (a `EmbedSessionResponse`), com estas diferenças:
  - `403 {"code": "no_connectable"}`: o resolver não acha o que abrir, inclusive quando devolve um model que não é `Connectable`;
  - `409 {"code": "not_connected"}`: o model ainda não tem conexão;
  - `422 {"code": "reauth_required"}` também para o `403 missing_ability` e o `403 not_a_partner_connection` da Zapmizer (o token não tem `inbox:attend`, ou não veio do connect: conexões anteriores à caixa) e para um token que não decifra mais. Os dois 403 só se resolvem com um connect novo, então o front mostra o botão de conectar (a ação `reconnect` do `@zapmizer/embed`). O log `zapmizer: inbox session refused.` guarda o código original. No servidor: `EmbedSessionController` (`not_a_partner_connection`, `MissingAbilityException` sem `inbox:attend`) e o handler de `AccessDeniedHttpException` em `bootstrap/app.php` (`error: missing_ability`). O `origin_not_allowed` (host não liberado) segue como `403`: não é caso de reconectar.
- Ligue com `ZAPMIZER_ROUTES_INBOX=true` (o `--inbox` liga), junto com `ZAPMIZER_ROUTES_CONNECT=true`. Até a 2.0.0-beta.3 a chave era `ZAPMIZER_INBOX_ENABLED`, que não liga mais nada. `ZAPMIZER_ROUTES_ENABLED=false` desliga estas junto com todas as outras.

## As rotas da lib

A lib registra as rotas em grupos, e **cada grupo só existe se for ligado**. Todos vêm desligados: um app que só envia mensagens não ganha nenhuma rota (`php artisan route:list` não mostra nada da lib). O `zapmizer:install` liga os grupos que as flags pediram.

| Grupo | Chave e env | Rotas | Middleware |
| --- | --- | --- | --- |
| connect | `zapmizer.routes.connect`, `ZAPMIZER_ROUTES_CONNECT` | `zapmizer.connect.show`, `.destroy`, `.start`, `.callback`, `.reconnect`, `.reconnect.callback` | `zapmizer.routes.middleware` (`web`, `auth`) |
| webhook | `zapmizer.routes.webhook`, `ZAPMIZER_ROUTES_WEBHOOK` | `zapmizer.webhook` (`POST /zapmizer/webhook`, pública e assinada) e, com `ZAPMIZER_WEBHOOK_PER_CONNECTION=true`, `zapmizer.webhook.connection` | `zapmizer.routes.webhook_middleware` (nenhum) |
| inbox | `zapmizer.routes.inbox`, `ZAPMIZER_ROUTES_INBOX` | `zapmizer.inbox.session`, `zapmizer.inbox.keepalive` | `zapmizer.inbox.middleware` (`web`, `auth`) |
| verify_number | `zapmizer.routes.verify_number`, `ZAPMIZER_ROUTES_VERIFY_NUMBER` | `zapmizer.verify_number` | `zapmizer.routes.middleware` |

- `zapmizer.routes.enabled` (`ZAPMIZER_ROUTES_ENABLED`, padrão `true`) continua sendo a chave mestra: com `false`, nenhuma rota da lib, com os grupos ligados ou não, e o app monta as suas.
- Os valores aceitam `true`/`false`, `1`/`0`, `on`/`off`.
- A caixa abre para a conexão que o connect fez: com a caixa ligada e o connect não, o `zapmizer:check` falha.
- **Connect sem webhook** é uma configuração válida: o connect não pede webhook à Zapmizer (a sessão sai sem `webhook_url`), e as conexões enviam, mas não recebem mensagens nem eventos. O `zapmizer:check` avisa. Ligar o webhook depois não dá webhook às conexões já feitas: elas precisam conectar de novo.
- Uma `config/zapmizer.php` publicada antes dos grupos não tem as chaves deles em `routes`: o provider as completa com as da lib (e as envs), sem mexer nas que o app tem. Não é preciso republicar.
- Com o grupo webhook ligado, `ZAPMIZER_WEBHOOK_SECRET` (`zapmizer.webhook.secret`) assina a rota `zapmizer.webhook`. Se o app já usa essa chave para outro fim, renomeie a dele antes de ligar.
- Depois de mudar o `.env` com a config em cache, rode `php artisan config:clear` (ou `config:cache`); com as rotas em cache, `php artisan route:clear`.

## Webhook (opcional)

Com o grupo webhook ligado (`ZAPMIZER_ROUTES_WEBHOOK=true`, o `--webhook` do instalador), o connect manda `route('zapmizer.webhook')` como `webhook_url`, e a lib recebe e confere as entregas assinadas. Desligado, o connect não pede webhook e as conexões só enviam. Para o app reagir às mensagens, ouça os eventos `MessageReceived` e `MessageSent`. Nada disso é preciso para o connect e a caixa funcionarem. Veja [connect.md](connect.md), seção 6, e [webhook.md](webhook.md).

## Laravel Boost

A lib traz diretrizes e uma skill para o [Laravel Boost](https://github.com/laravel/boost), em `resources/boost/`:

- `resources/boost/guidelines/core.md`: o resumo que entra no `CLAUDE.md`/`AGENTS.md` do app;
- `resources/boost/skills/zapmizer-whatsapp/SKILL.md`: a receita (instalar, conectar número, caixa de entrada, enviar WhatsApp).

Sem o Boost no app, nada disso é carregado: o agente só acha este roteiro se alguém apontar para ele (o prompt sugerido acima faz isso).

Com o Boost, o pacote precisa ser escolhido. O Boost só oferece pacotes que são dependência direta do app (no `composer.json`), e só inclui os escolhidos:

- `php artisan boost:install` interativo mostra a lista de pacotes de terceiros com diretrizes ou skills; marque `zapmizer/laravel-notification-zapmizer`;
- sem interação (`boost:install --no-interaction`), ele só inclui os pacotes já listados em `packages` no `boost.json`. Acrescente `"zapmizer/laravel-notification-zapmizer"` ao `packages` do `boost.json` e rode `php artisan boost:update`. O `boost:update` sem interação não pergunta por pacotes novos.

Conferido num app Laravel 13 novo com o Boost v2.10.4: depois do `boost:install --no-interaction` o `CLAUDE.md` não citava a lib; com `packages` no `boost.json` e `boost:update`, a diretriz entrou no `CLAUDE.md` e a skill em `.claude/skills/zapmizer-whatsapp/`. No código do Boost v2.10.4: `src/Console/InstallCommand.php` (linhas 263 a 276, a escolha dos pacotes de terceiros e o `--no-interaction`), `src/Install/ThirdPartyPackage.php` (linha 73, só dependências diretas), `src/Console/UpdateCommand.php` (linha 77, sem pergunta fora do modo interativo) e `src/Install/GuidelineComposer.php` (linhas 322 a 328, que leem `*.md` e `*.blade.php` de `resources/boost/guidelines`).
