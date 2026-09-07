import axios from 'axios';
import { computed, ref } from 'vue';
import type { ZapmizerConnection } from '@/types/zapmizer';

const JSON_HEADERS = { headers: { Accept: 'application/json' } };

/**
 * Estado da integração do time — de onde a tela decide se abre o wizard, em
 * que passo, ou o painel de conexão já pronta.
 *
 * Autorizado mas sem número pareado é um estado real (o callback grava a
 * credencial e deixa `is_active` falso de propósito): é ele que manda o wizard
 * abrir direto no passo 2.
 */
export function useZapmizerIntegration() {
  const integration = ref<ZapmizerConnection | null>(null);
  const loading = ref(true);
  const errorMessage = ref('');

  // Uma resposta atrasada do reload não pode sobrescrever um estado mais novo
  // (o "já autorizei" recarrega enquanto o popup também pode ter respondido).
  let request = 0;

  const isAuthorized = computed(() => integration.value !== null);
  const isConnected = computed(() => Boolean(integration.value?.is_active && integration.value?.phone_number));

  /** 1 = autorizar, 2 = parear número, 3 = pronto. */
  const initialStep = computed(() => (isConnected.value ? 3 : isAuthorized.value ? 2 : 1));

  async function reload() {
    const current = ++request;
    errorMessage.value = '';

    try {
      const { data } = await axios.get<{ connection: ZapmizerConnection | null }>(route('zapmizer.connect.show'), JSON_HEADERS);

      if (current !== request) return;

      integration.value = data.connection ?? null;
    } catch {
      if (current !== request) return;

      errorMessage.value = 'Não foi possível carregar o estado da integração.';
    } finally {
      if (current === request) loading.value = false;
    }
  }

  async function disconnect() {
    await axios.delete(route('zapmizer.connect.destroy'), JSON_HEADERS);

    request++;
    integration.value = null;
  }

  return { integration, loading, errorMessage, isAuthorized, isConnected, initialStep, reload, disconnect };
}
