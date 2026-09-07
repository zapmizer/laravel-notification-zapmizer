<script setup lang="ts">
import { CheckIcon } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import StepAuthorize from '@/components/zapmizer/StepAuthorize.vue';
import StepDone from '@/components/zapmizer/StepDone.vue';
import StepQr from '@/components/zapmizer/StepQr.vue';
import type { ZapmizerInstanceConnection, ZapmizerConnection } from '@/types/zapmizer';

const props = withDefaults(
  defineProps<{
    integration?: ZapmizerConnection | null;
    /** Passo em que a tela abre, derivado do estado que veio do servidor. */
    initialStep?: number;
    /** Troca de número numa integração já pronta: começa pela lista, não pelo QR. */
    swapMode?: boolean;
  }>(),
  { integration: null, initialStep: 1, swapMode: false },
);

const emit = defineEmits<{ recheck: []; connected: []; done: [] }>();

const currentStep = ref(props.initialStep);
const lastConnection = ref<ZapmizerInstanceConnection | null>(null);
const reauthRequired = ref(false);

// O estado do servidor pode avançar sozinho (o popup autoriza, o reload traz a
// integração). Só puxa para frente: recuar apagaria o passo 3 recém-chegado.
watch(
  () => props.initialStep,
  (step) => {
    if (step > currentStep.value) currentStep.value = step;
  },
);

const steps = [
  { number: 1, label: 'Autorizar', doneLabel: 'Autorizado' },
  { number: 2, label: 'Conectar número', doneLabel: 'Número conectado' },
  { number: 3, label: 'Pronto', doneLabel: 'Pronto' },
];

function onAuthorized() {
  reauthRequired.value = false;
  currentStep.value = 2;
  emit('recheck');
}

function onConnected(connection: ZapmizerInstanceConnection | null) {
  lastConnection.value = connection;
  currentStep.value = 3;
  emit('connected');
}

function onReauth() {
  reauthRequired.value = true;
  currentStep.value = 1;
}
</script>

<template>
  <section class="rounded border border-gp-border bg-gp-panel">
    <ol class="flex items-center gap-2 border-b border-gp-border px-4 py-3">
      <template v-for="(step, index) in steps" :key="step.number">
        <li v-if="index > 0" aria-hidden="true" class="h-px flex-1 bg-gp-border"></li>
        <li
          class="flex items-center gap-1.5 text-[11px]"
          :class="step.number < currentStep ? 'text-gp-mint' : step.number === currentStep ? 'text-gp-text' : 'text-gp-dim'"
          :aria-current="step.number === currentStep ? 'step' : undefined"
        >
          <span
            class="flex size-5 items-center justify-center rounded-full text-[10px]"
            :class="
              step.number < currentStep
                ? 'bg-gp-mint/15 text-gp-mint'
                : step.number === currentStep
                  ? 'bg-gp-teal/15 text-gp-teal'
                  : 'bg-gp-panel-2 text-gp-dim'
            "
          >
            <CheckIcon v-if="step.number < currentStep" class="size-3" />
            <template v-else>{{ step.number }}</template>
          </span>
          {{ step.number < currentStep ? step.doneLabel : step.label }}
        </li>
      </template>
    </ol>

    <div class="p-4">
      <StepAuthorize
        v-if="currentStep === 1"
        :reauth-required="reauthRequired"
        @authorized="onAuthorized"
        @recheck="emit('recheck')"
      />
      <StepQr v-else-if="currentStep === 2" :swap-mode="swapMode" @connected="onConnected" @reauth="onReauth" />
      <StepDone v-else :connection="lastConnection" :integration="integration" @done="emit('done')" />
    </div>
  </section>
</template>
