<script setup lang="ts">
import axios from 'axios';
import { AlertCircleIcon, ArrowRightIcon, MessageCircleIcon } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

withDefaults(defineProps<{ reauthRequired?: boolean }>(), { reauthRequired: false });

const emit = defineEmits<{ authorized: []; recheck: [] }>();

const starting = ref(false);
const error = ref('');

const MESSAGES: Record<string, string> = {
  denied: 'Autorização cancelada. Tente novamente quando quiser conectar.',
  invalid_state: 'O link de autorização expirou. Tente novamente.',
  exchange_failed: 'Não foi possível concluir a autorização. Tente novamente.',
  team_already_connected: 'Essa conta do Zapmizer já está conectada a outra conta aqui. Desconecte lá primeiro ou autorize outro time do Zapmizer.',
};

/**
 * O popup do callback fala com esta página por postMessage. Confiar no payload
 * antes de conferir a origem deixaria qualquer aba aberta em outro site
 * declarar "autorizado" e pular o passo.
 */
function onMessage(event: MessageEvent) {
  if (event.origin !== window.location.origin) return;
  if (event.data?.source !== 'zapmizer-connect') return;

  if (event.data.status === 'ok') {
    error.value = '';
    emit('authorized');

    return;
  }

  error.value = MESSAGES[event.data.status] || event.data.message || 'Não foi possível concluir a autorização. Tente novamente.';
}

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));

async function connect() {
  error.value = '';
  starting.value = true;

  try {
    const { data } = await axios.post<{ url: string; expires_at: string }>(
      route('zapmizer.connect.start'),
      {},
      { headers: { Accept: 'application/json' } },
    );

    const popup = window.open(data.url, 'zapmizer-connect', 'width=520,height=720');

    if (!popup) error.value = 'O navegador bloqueou a janela — libere popups para este site e tente de novo.';
  } catch (e) {
    const response = (e as { response?: { status?: number; data?: { message?: string } } }).response;
    const status = response?.status ?? 0;

    // Mensagem do servidor só vale em 4xx (validação, throttle). Em 5xx ela é o
    // texto da exceção — em dev vaza stack do Zapmizer, em produção vira
    // "Server Error"; nenhum dos dois diz ao usuário o que fazer.
    error.value =
      status === 503
        ? 'O Zapmizer está indisponível no momento. Tente novamente em instantes.'
        : status >= 500 || status === 0
          ? 'Não foi possível falar com o Zapmizer agora. Tente novamente em instantes.'
          : (response?.data?.message ?? 'Não foi possível iniciar a autorização. Tente novamente.');
  } finally {
    starting.value = false;
  }
}
</script>

<template>
  <div>
    <div class="gp-eyebrow">Passo 1</div>
    <h3 class="mt-1 text-base font-semibold tracking-tight text-gp-text">Autorize o sistema no Zapmizer</h3>
    <p class="mt-1 text-[12px] text-gp-muted">
      É a conta do Zapmizer que fala com o WhatsApp. Depois de autorizar, você escolhe qual número vai conversar com o
      time.
    </p>

    <p
      v-if="reauthRequired"
      class="mt-3 flex items-start gap-2 rounded border border-gp-amber/40 bg-gp-amber/10 px-3 py-2 text-[12px] text-gp-amber"
    >
      <AlertCircleIcon class="mt-px size-3.5 shrink-0" />
      A autorização com o Zapmizer expirou. Autorize novamente para continuar.
    </p>

    <p
      v-if="error"
      class="mt-3 flex items-start gap-2 rounded border border-gp-coral/40 bg-gp-coral/10 px-3 py-2 text-[12px] text-gp-coral"
    >
      <AlertCircleIcon class="mt-px size-3.5 shrink-0" />
      {{ error }}
    </p>

    <div class="mt-4 flex flex-col items-center rounded border border-gp-border bg-gp-panel-2 px-6 py-8 text-center">
      <div class="flex size-10 items-center justify-center rounded border border-gp-teal/40 bg-gp-teal/10 text-gp-teal">
        <MessageCircleIcon class="size-5" />
      </div>
      <p class="mt-3 text-[13px] font-medium text-gp-text">Uma janela do Zapmizer vai abrir</p>
      <p class="mx-auto mt-1 max-w-sm text-[12px] text-gp-muted">
        Entre (ou crie sua conta) e autorize o acesso. Você volta para cá automaticamente.
      </p>
      <button
        type="button"
        class="mt-4 inline-flex items-center gap-1.5 rounded border border-gp-teal bg-gp-teal/10 px-3 py-1.5 text-[12px] text-gp-teal transition-colors hover:bg-gp-teal/20 disabled:opacity-50"
        :disabled="starting"
        @click="connect"
      >
        {{ starting ? 'Abrindo o Zapmizer...' : 'Conectar com o Zapmizer' }}
        <ArrowRightIcon class="size-3.5" />
      </button>
      <!-- Popup fechado na mão, ou bloqueado e reaberto por fora: sem esta saída
           o usuário fica olhando o passo 1 com a autorização já concluída.
           Emite `recheck`, não `authorized`: quem decide se avançou é o estado
           que voltar do servidor, não o clique. -->
      <button
        type="button"
        class="mt-2 rounded px-2 py-1 text-[11px] text-gp-muted transition-colors hover:text-gp-text"
        @click="emit('recheck')"
      >
        Já autorizei
      </button>
    </div>
  </div>
</template>
