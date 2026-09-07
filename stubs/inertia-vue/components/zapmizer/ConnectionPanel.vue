<script setup lang="ts">
import { AlertCircleIcon, Loader2Icon, RefreshCwIcon, SmartphoneIcon, Trash2Icon } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { ZapmizerConnection } from '@/types/zapmizer';

/**
 * O número conectado e o estado dele. `integration` é o que `show?live=1`
 * devolve — `state`/`is_online` só existem quando a tela pediu `live`
 * (`useZapmizerIntegration().reload({ live: true })`); sem eles o painel só
 * sabe o que está gravado.
 */
const props = withDefaults(
  defineProps<{ integration: ZapmizerConnection; refreshing?: boolean; removing?: boolean }>(),
  { refreshing: false, removing: false },
);

const emit = defineEmits<{ refresh: []; reconnect: []; disconnect: [] }>();

const number = computed(() => props.integration.phone_number || '');
const teamName = computed(() => props.integration.zapmizer_team_name ?? null);

const reauthRequired = computed(() => props.integration.state === 'reauth_required');

const health = computed(() => {
  const state = props.integration.state;

  if (!props.integration.is_active) return { tone: 'gp-tag-coral', label: 'Inativa' };
  if (state === 'reauth_required') return { tone: 'gp-tag-coral', label: 'Sem autorização' };
  if (state === 'instance_gone') return { tone: 'gp-tag-coral', label: 'Número removido no Zapmizer' };
  if (state === 'zapmizer_unavailable') return { tone: 'gp-tag-amber', label: 'Zapmizer instável' };
  if (props.integration.is_online) return { tone: 'gp-tag-mint', label: 'Online' };
  if (state === 'connected') return { tone: 'gp-tag-mint', label: 'Conectado' };
  if (state === 'disconnected' || state === 'off' || state === 'qrcode') return { tone: 'gp-tag-amber', label: 'Desconectado no celular' };
  if (state === undefined) return { tone: 'gp-tag-mint', label: 'Conectado' };

  return { tone: 'gp-tag-muted', label: 'Verificando...' };
});

const confirmingRemoval = ref(false);
</script>

<template>
  <section class="rounded border border-gp-border bg-gp-panel">
    <header class="flex items-start justify-between gap-4 border-b border-gp-border p-4">
      <div>
        <div class="gp-eyebrow">Conexão</div>
        <h3 class="mt-1 text-base font-semibold tracking-tight text-gp-text">WhatsApp conectado</h3>
        <p class="mt-1 text-[12px] text-gp-muted">O número por onde o sistema fala com o time.</p>
      </div>
      <span class="gp-tag shrink-0 whitespace-nowrap" :class="health.tone">{{ health.label }}</span>
    </header>

    <div class="space-y-3 p-4">
      <div class="flex items-center gap-3 rounded border border-gp-border bg-gp-panel-2 p-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded border border-gp-teal/40 bg-gp-teal/10 text-gp-teal">
          <SmartphoneIcon class="size-4" />
        </div>
        <div class="min-w-0 flex-1">
          <p class="gp-mono text-[13px] text-gp-text">{{ number || 'Número não identificado' }}</p>
          <p class="mt-0.5 text-[11px] text-gp-muted">
            Conta no Zapmizer: <span class="text-gp-text">{{ teamName || 'não identificada' }}</span>
            <template v-if="integration.api_token_masked"> · credencial {{ integration.api_token_masked }}</template>
          </p>
        </div>
      </div>

      <p
        v-if="reauthRequired"
        class="flex items-start gap-2 rounded border border-gp-coral/40 bg-gp-coral/10 px-3 py-2 text-[12px] text-gp-coral"
      >
        <AlertCircleIcon class="mt-px size-3.5 shrink-0" />
        A autorização com o Zapmizer expirou. Conecte de novo para renovar o acesso.
      </p>

      <p
        v-else-if="!integration.is_active"
        class="flex items-start gap-2 rounded border border-gp-coral/40 bg-gp-coral/10 px-3 py-2 text-[12px] text-gp-coral"
      >
        <AlertCircleIcon class="mt-px size-3.5 shrink-0" />
        A conexão está inativa: o recebimento de mensagens não pôde ser configurado. Conecte de novo.
      </p>

      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text disabled:opacity-50"
          :disabled="refreshing"
          @click="emit('refresh')"
        >
          <RefreshCwIcon class="size-3.5" :class="{ 'animate-spin': refreshing }" />
          Atualizar estado
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text"
          @click="emit('reconnect')"
        >
          Trocar número
        </button>
        <button
          v-if="!confirmingRemoval"
          type="button"
          class="ml-auto inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-muted transition-colors hover:border-gp-coral hover:text-gp-coral"
          @click="confirmingRemoval = true"
        >
          <Trash2Icon class="size-3.5" />
          Desconectar
        </button>
        <div v-else class="ml-auto flex items-center gap-2">
          <span class="text-[11px] text-gp-muted">Desconectar? O time para de receber e responder.</span>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded border border-gp-coral bg-gp-coral/10 px-3 py-1.5 text-[12px] text-gp-coral transition-colors hover:bg-gp-coral/20 disabled:opacity-50"
            :disabled="removing"
            @click="emit('disconnect')"
          >
            <Loader2Icon v-if="removing" class="size-3.5 animate-spin" />
            Confirmar
          </button>
          <button
            type="button"
            class="rounded px-2 py-1 text-[11px] text-gp-muted transition-colors hover:text-gp-text"
            @click="confirmingRemoval = false"
          >
            Cancelar
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
