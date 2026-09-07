<script setup lang="ts">
import { AlertCircleIcon, ClockIcon, ExternalLinkIcon, Loader2Icon, RefreshCwIcon } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import InstancePicker from '@/components/zapmizer/InstancePicker.vue';
import QrCanvas from '@/components/zapmizer/QrCanvas.vue';
import { useZapmizerConnection } from '@/composables/useZapmizerConnection';
import type { ZapmizerInstanceConnection } from '@/types/zapmizer';

const props = withDefaults(defineProps<{ swapMode?: boolean }>(), { swapMode: false });

const emit = defineEmits<{ connected: [ZapmizerInstanceConnection | null]; reauth: [] }>();

const { connection, status, errorMessage, instances, busy, start, restart, stopPolling, loadChoices, adopt, createNew } =
  useZapmizerConnection();

/** Depois disso sem QR na tela, oferece reiniciar em vez de deixar girando. */
const RESTART_AFTER_MS = 60000;

const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
  clock = setInterval(() => (now.value = Date.now()), 1000);
  // Trocar de número começa pela lista; conexão nova deixa o backend resolver.
  props.swapMode ? loadChoices() : start();
});

onUnmounted(() => {
  if (clock) clearInterval(clock);
});

const selectedInstance = ref<number | null>(null);

// Um 422 instance_unavailable devolve a lista sem o id morto — a seleção tem
// que morrer junto, senão o radio some e o confirmar re-submete o morto.
watch(instances, (list) => {
  if (selectedInstance.value !== null && !list.some((instance) => instance.id === selectedInstance.value)) {
    selectedInstance.value = null;
  }
});

const qrValue = computed(() => {
  const qr = connection.value?.qrcode;
  if (!qr) return null;

  const expiresAt = connection.value?.qrcode_expires_at;
  if (expiresAt && new Date(expiresAt).getTime() <= now.value) return null;

  return qr;
});

const expiresIn = computed(() => {
  const expiresAt = connection.value?.qrcode_expires_at;
  if (!qrValue.value || !expiresAt) return null;

  const seconds = Math.max(0, Math.floor((new Date(expiresAt).getTime() - now.value) / 1000));

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

const waitingSince = ref<number | null>(Date.now());

watch(qrValue, (value) => {
  waitingSince.value = value ? null : (waitingSince.value ?? Date.now());
});

const showRestart = computed(
  () =>
    !qrValue.value &&
    waitingSince.value !== null &&
    now.value - waitingSince.value > RESTART_AFTER_MS &&
    !['plan_limit', 'error', 'choice_required'].includes(status.value),
);

const stateLabel = computed(() => {
  if (connection.value?.state_label) return connection.value.state_label;
  if (status.value === 'booting') return 'Preparando a conexão...';

  return 'Verificando conexão...';
});

const heading = computed(() => {
  if (status.value === 'choice_required') {
    return { title: 'Escolha o número', subtitle: 'Qual número WhatsApp conversa com o time.' };
  }

  if (status.value === 'plan_limit') {
    return { title: 'Limite de números atingido', subtitle: 'Sua conta no Zapmizer não comporta um número novo.' };
  }

  if (status.value === 'error') {
    return { title: 'Não foi possível conectar', subtitle: 'Algo deu errado ao falar com o Zapmizer.' };
  }

  return { title: 'Escaneie com o WhatsApp', subtitle: 'Use o celular do número que vai conversar com o time.' };
});

watch(status, (value) => {
  if (value === 'connected') emit('connected', connection.value);
  if (value === 'reauth_required') emit('reauth');
});

function tryAgain() {
  waitingSince.value = Date.now();
  restart();
}

function backToPicker() {
  stopPolling();
  errorMessage.value = '';
  status.value = 'choice_required';
}
</script>

<template>
  <div>
    <div class="gp-eyebrow">Passo 2</div>
    <h3 class="mt-1 text-base font-semibold tracking-tight text-gp-text">{{ heading.title }}</h3>
    <p class="mt-1 text-[12px] text-gp-muted">{{ heading.subtitle }}</p>

    <div v-if="status === 'choice_required'" class="mt-4">
      <InstancePicker
        v-model="selectedInstance"
        :instances="instances"
        :error-message="errorMessage"
        :busy="busy"
        @confirm="adopt(selectedInstance)"
        @create="createNew"
      />
    </div>

    <div v-else-if="status === 'plan_limit'" class="mt-4">
      <div class="rounded border border-gp-amber/40 bg-gp-amber/10 p-3">
        <p class="text-[12px] font-medium text-gp-amber">Limite de números do seu plano no Zapmizer</p>
        <p class="mt-1 text-[12px] text-gp-amber/90">
          {{ errorMessage || 'Sua conta no Zapmizer atingiu o limite de números conectados.' }}
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <a
            href="https://app.zapmizer.com"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text"
          >
            <ExternalLinkIcon class="size-3.5" />
            Abrir o Zapmizer
          </a>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text"
            @click="instances.length ? backToPicker() : tryAgain()"
          >
            {{ instances.length ? 'Voltar para a lista' : 'Tentar de novo' }}
          </button>
        </div>
      </div>

      <div v-if="instances.length" class="mt-4">
        <p class="mb-2 text-[12px] text-gp-muted">Você ainda pode usar um número já conectado:</p>
        <InstancePicker
          v-model="selectedInstance"
          :instances="instances"
          :busy="busy"
          @confirm="adopt(selectedInstance)"
          @create="createNew"
        />
      </div>
    </div>

    <div v-else-if="status === 'error'" class="mt-4 rounded border border-gp-coral/40 bg-gp-coral/10 p-3">
      <p class="flex items-start gap-2 text-[12px] text-gp-coral">
        <AlertCircleIcon class="mt-px size-3.5 shrink-0" />
        {{ errorMessage }}
      </p>
      <button
        type="button"
        class="mt-3 inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text"
        @click="tryAgain"
      >
        <RefreshCwIcon class="size-3.5" />
        Tentar de novo
      </button>
    </div>

    <div v-else class="mt-4 flex flex-col gap-5 sm:flex-row sm:items-start">
      <div class="flex shrink-0 flex-col items-center gap-2">
        <!-- Fundo branco também no tema escuro, de propósito: a câmera precisa
             do contraste do QR. -->
        <div class="flex size-52 items-center justify-center rounded border border-gp-border bg-white">
          <QrCanvas v-if="qrValue" :value="qrValue" :size="196" />
          <div v-else class="flex flex-col items-center gap-2 px-4 text-center">
            <Loader2Icon class="size-5 animate-spin text-neutral-500" />
            <span class="text-[11px] text-neutral-500">Gerando novo código...</span>
          </div>
        </div>
        <p v-if="expiresIn" class="flex items-center gap-1.5 text-[11px] text-gp-muted">
          <ClockIcon class="size-3" />
          Expira em <span class="gp-mono text-gp-text">{{ expiresIn }}</span>
        </p>
        <button
          v-if="showRestart"
          type="button"
          class="inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text"
          @click="tryAgain"
        >
          <RefreshCwIcon class="size-3.5" />
          Reiniciar
        </button>
      </div>

      <div class="min-w-0 flex-1">
        <span class="gp-tag gp-tag-amber">{{ stateLabel }}</span>
        <p v-if="status === 'unavailable'" class="mt-2 text-[11px] text-gp-muted">
          O Zapmizer está instável — tentando novamente...
        </p>
        <ol class="mt-4 list-decimal space-y-1.5 pl-5 text-[12px] text-gp-muted">
          <li>Abra o <span class="text-gp-text">WhatsApp</span> no celular</li>
          <li>Toque em <span class="text-gp-text">Dispositivos conectados</span></li>
          <li>Toque em <span class="text-gp-text">Conectar dispositivo</span></li>
          <li>Aponte a câmera para o código</li>
        </ol>
        <button
          v-if="instances.length"
          type="button"
          class="mt-4 rounded text-[11px] text-gp-muted underline underline-offset-2 transition-colors hover:text-gp-text"
          @click="backToPicker"
        >
          Escolher um número já conectado
        </button>
      </div>
    </div>
  </div>
</template>
