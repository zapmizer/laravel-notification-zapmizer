<script setup lang="ts">
import { ArrowRightIcon, CheckIcon, SmartphoneIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import type { ZapmizerInstanceConnection, ZapmizerConnection } from '@/types/zapmizer';

const props = withDefaults(
  defineProps<{ connection?: ZapmizerInstanceConnection | null; integration?: ZapmizerConnection | null }>(),
  { connection: null, integration: null },
);

const emit = defineEmits<{ done: [] }>();

const number = computed(() => props.connection?.number || props.integration?.phone_number || '');
</script>

<template>
  <div>
    <div class="gp-eyebrow">Passo 3</div>
    <h3 class="mt-1 flex items-center gap-2 text-base font-semibold tracking-tight text-gp-text">
      <CheckIcon class="size-4 text-gp-mint" />
      WhatsApp conectado
    </h3>
    <p class="mt-1 text-[12px] text-gp-muted">
      Este é o número por onde o sistema recebe e responde as mensagens do time.
    </p>

    <div class="mt-4 flex items-center gap-3 rounded border border-gp-border bg-gp-panel-2 p-3">
      <div class="flex size-9 shrink-0 items-center justify-center rounded border border-gp-mint/40 bg-gp-mint/10 text-gp-mint">
        <SmartphoneIcon class="size-4" />
      </div>
      <div class="min-w-0 flex-1">
        <p class="gp-mono text-[13px] text-gp-text">{{ number || 'Número em identificação...' }}</p>
        <p class="mt-0.5 text-[11px] text-gp-muted">Número do sistema no WhatsApp</p>
      </div>
      <span class="gp-tag gp-tag-mint">Conectado</span>
    </div>

    <button
      type="button"
      class="mt-4 inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text"
      @click="emit('done')"
    >
      Ver o painel da conexão
      <ArrowRightIcon class="size-3.5" />
    </button>
  </div>
</template>
