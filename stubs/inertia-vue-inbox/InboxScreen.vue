<script setup lang="ts">
import { defaultMessages } from '@zapmizer/embed/errors';
import { useInboxHost, useInboxSlot } from '@zapmizer/embed/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConnectButton from './ConnectButton.vue';
import { useZapmizerIntegration } from './useZapmizerIntegration';
import { listenToZapmizerLogout, ZAPMIZER_BRAND, zapmizerKeepAlive, zapmizerOpenSession } from './zapmizerEmbed';
import { zapmizerRoute } from './zapmizerRoute';

/**
 * A caixa de entrada do WhatsApp embutida, contra as rotas da lib
 * (`zapmizer.inbox.session` e `zapmizer.inbox.keepalive`, ligadas por
 * `ZAPMIZER_INBOX_ENABLED=true`). Sem conexão, ou com a conexão pedindo
 * para ser refeita, mostra o ConnectButton; conectou, abre a caixa.
 *
 * `person` é quem atende, estável e por pessoa: a chave da retomada
 * (`zapmizer-inbox:${person}`). Use `String(user.id)`, ou
 * `${user.id}:${team.id}` quando a conexão é do time. `null` (deslogou)
 * fecha a caixa.
 *
 * A tela reserva a altura: o componente ocupa 100% do pai, com no mínimo
 * `minHeight`. Para a caixa sobreviver à navegação entre telas, mova o
 * `useInboxHost` para o layout (veja docs/vue.md do @zapmizer/embed).
 */
const props = withDefaults(
  defineProps<{
    person: string | null;
    theme?: 'light' | 'dark';
    sessionUrl?: string;
    keepaliveUrl?: string;
    minHeight?: string;
  }>(),
  { theme: undefined, sessionUrl: undefined, keepaliveUrl: undefined, minHeight: '560px' },
);

const { integration, loading, errorMessage, reload } = useZapmizerIntegration();

/** A sessão voltou `not_connected` ou com ação `reconnect` (token revogado, número removido). */
const needsConnect = ref(false);
const reauthRequired = ref(false);

const person = computed(() => props.person);
const enabled = computed(() => !loading.value && integration.value !== null && !needsConnect.value);

const inbox = useInboxHost({
  brand: ZAPMIZER_BRAND,
  openSession: zapmizerOpenSession(props.sessionUrl ?? zapmizerRoute('zapmizer.inbox.session'), () =>
    props.theme ? { theme: props.theme } : {},
  ),
  keepAlive: zapmizerKeepAlive(props.keepaliveUrl ?? zapmizerRoute('zapmizer.inbox.keepalive')),
  person,
  enabled,
  frame: {
    configure: (iframe) =>
      Object.assign(iframe.style, { position: 'absolute', inset: '0', width: '100%', height: '100%', border: '0' }),
  },
});

Object.assign(inbox.host.overlay.style, { position: 'absolute', inset: '0', pointerEvents: 'none' });
inbox.host.element.style.zIndex = '5';
inbox.host.element.setAttribute('role', 'region');
inbox.host.element.setAttribute('aria-label', 'Caixa de entrada do WhatsApp');

const slot = useInboxSlot(inbox.host, { person, enabled });

const state = computed(() => inbox.state.value);
const error = computed(() => (state.value.status === 'error' ? state.value : null));
const message = computed(() =>
  error.value ? (defaultMessages[error.value.code] ?? 'Não foi possível abrir a caixa de entrada. Avise o suporte.') : '',
);

watch(error, (current) => {
  if (current && (current.code === 'not_connected' || current.action === 'reconnect')) {
    reauthRequired.value = current.code !== 'not_connected';
    needsConnect.value = true;
  }
});

/** O popup do connect respondeu `ok` (ou `webhook_failed`, que também conecta). */
async function onConnected() {
  await reload();
  reauthRequired.value = false;
  needsConnect.value = false;
}

function reloadPage() {
  window.location.reload();
}

let stopListening: (() => void) | null = null;

onMounted(() => {
  // O ideal é chamar listenToZapmizerLogout() uma vez no boot do app; aqui
  // cobre ao menos o tempo em que a tela está aberta.
  stopListening = listenToZapmizerLogout();
  reload();
});

onBeforeUnmount(() => stopListening?.());
</script>

<template>
  <div :style="{ position: 'relative', height: '100%', minHeight }">
    <p v-if="loading" role="status">Carregando…</p>

    <p v-else-if="errorMessage" role="alert">
      {{ errorMessage }}
      <button type="button" @click="reload()">Tentar de novo</button>
    </p>

    <ConnectButton
      v-else-if="integration === null || needsConnect"
      :reauth-required="reauthRequired"
      @connected="onConnected"
      @recheck="onConnected"
    />

    <section v-else ref="slot" :style="{ position: 'relative', height: '100%', minHeight }" />

    <Teleport :to="inbox.host.overlay">
      <p
        v-if="state.status === 'opening' || state.status === 'loading'"
        role="status"
        style="pointer-events: auto; margin: 1rem"
      >
        Abrindo a caixa de entrada…
      </p>
      <div v-else-if="error && !needsConnect" role="alert" style="pointer-events: auto; margin: 1rem">
        <p>{{ message }}</p>
        <button v-if="error.action === 'retry'" type="button" @click="inbox.retry()">Tentar de novo</button>
        <button v-else-if="error.action === 'reload'" type="button" @click="reloadPage">
          Recarregar a página
        </button>
        <!-- `checkout` (assinatura inativa): leve o cliente ao checkout do seu produto
             (PartnerClient::checkout(), docs/connect.md seção 8) e, quando voltar, chame inbox.retry(). -->
      </div>
    </Teleport>
  </div>
</template>
