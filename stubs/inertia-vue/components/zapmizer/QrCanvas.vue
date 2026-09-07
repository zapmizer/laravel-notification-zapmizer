<script setup lang="ts">
import QRCode from 'qrcode';
import { ref, watch } from 'vue';

const props = withDefaults(defineProps<{ value: string; size?: number }>(), { size: 196 });

const dataUrl = ref<string | null>(null);

watch(
  () => props.value,
  async (value) => {
    dataUrl.value = null;
    if (!value) return;

    try {
      dataUrl.value = await QRCode.toDataURL(value, { margin: 1, width: props.size });
    } catch {
      dataUrl.value = null;
    }
  },
  { immediate: true },
);
</script>

<template>
  <img
    v-if="dataUrl"
    :src="dataUrl"
    :width="size"
    :height="size"
    alt="QR code para conectar o WhatsApp"
    class="rounded-[2px]"
  />
</template>
