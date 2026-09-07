<script setup lang="ts">
import { AlertCircleIcon, Loader2Icon, PlusIcon, SmartphoneIcon } from 'lucide-vue-next';
import type { ZapmizerInstance } from '@/types/zapmizer';

withDefaults(
  defineProps<{
    instances: ZapmizerInstance[];
    errorMessage?: string;
    busy?: boolean;
  }>(),
  { errorMessage: '', busy: false },
);

const emit = defineEmits<{ confirm: []; create: [] }>();

const selected = defineModel<number | null>({ default: null });
</script>

<template>
  <div>
    <p
      v-if="errorMessage"
      class="mb-3 flex items-start gap-2 rounded border border-gp-coral/40 bg-gp-coral/10 px-3 py-2 text-[12px] text-gp-coral"
    >
      <AlertCircleIcon class="mt-px size-3.5 shrink-0" />
      {{ errorMessage }}
    </p>

    <template v-if="instances.length">
      <fieldset :disabled="busy" class="flex flex-col gap-1.5">
        <legend class="sr-only">Números conectados no Zapmizer</legend>

        <label
          v-for="instance in instances"
          :key="instance.id"
          class="flex cursor-pointer items-center gap-2.5 rounded border px-3 py-2 transition-colors"
          :class="[
            selected === instance.id
              ? 'border-gp-teal bg-gp-teal/10'
              : 'border-gp-border bg-gp-panel-2 hover:border-gp-text',
            busy ? 'pointer-events-none opacity-60' : '',
          ]"
        >
          <input
            v-model="selected"
            type="radio"
            name="Zapmizer-instance"
            :value="instance.id"
            class="size-3 shrink-0 accent-gp-teal"
          />
          <SmartphoneIcon class="size-3.5 shrink-0 text-gp-muted" />
          <span class="gp-mono min-w-0 flex-1 text-[12px] text-gp-text">{{ instance.number }}</span>
          <span v-if="instance.is_current" class="gp-tag gp-tag-teal">Remetente atual</span>
        </label>
      </fieldset>

      <div class="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded border border-gp-teal bg-gp-teal/10 px-3 py-1.5 text-[12px] text-gp-teal transition-colors hover:bg-gp-teal/20 disabled:opacity-50"
          :disabled="busy || selected === null"
          @click="emit('confirm')"
        >
          <Loader2Icon v-if="busy" class="size-3.5 animate-spin" />
          Usar este número
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded border border-gp-border bg-gp-panel-2 px-3 py-1.5 text-[12px] text-gp-text transition-colors hover:border-gp-text disabled:opacity-50"
          :disabled="busy"
          @click="emit('create')"
        >
          <PlusIcon class="size-3.5" />
          Conectar outro número
        </button>
      </div>
    </template>

    <div v-else class="rounded border border-dashed border-gp-border px-4 py-10 text-center">
      <SmartphoneIcon class="mx-auto size-5 text-gp-muted" />
      <p class="mt-2 text-[12px] text-gp-muted">Nenhum número conectado no Zapmizer ainda.</p>
      <button
        type="button"
        class="mt-4 inline-flex items-center gap-1.5 rounded border border-gp-teal bg-gp-teal/10 px-3 py-1.5 text-[12px] text-gp-teal transition-colors hover:bg-gp-teal/20 disabled:opacity-50"
        :disabled="busy"
        @click="emit('create')"
      >
        <Loader2Icon v-if="busy" class="size-3.5 animate-spin" />
        <PlusIcon v-else class="size-3.5" />
        Conectar um número
      </button>
    </div>
  </div>
</template>
