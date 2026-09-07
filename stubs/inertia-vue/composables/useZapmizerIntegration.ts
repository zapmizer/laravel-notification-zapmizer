import axios from 'axios';
import { computed, ref } from 'vue';
import type { ZapmizerConnection } from '@/types/zapmizer';

const JSON_HEADERS = { headers: { Accept: 'application/json' } };

/**
 * Abre o popup do Zapmizer (autorização + pareamento). O resultado chega por
 * postMessage — ver ConnectButton. Fora do composable de propósito: o botão
 * chama isto sem carregar um estado que não é dele.
 */
export async function openZapmizerConnect(): Promise<Window | null> {
  const { data } = await axios.post<{ url: string; expires_at: string }>(route('zapmizer.connect.start'), {}, JSON_HEADERS);

  return window.open(data.url, 'zapmizer-connect', 'width=520,height=720');
}

/**
 * Estado da integração do time — de onde a tela decide se mostra o botão de
 * conectar ou o painel do número conectado.
 *
 * O popup do Zapmizer autoriza E pareia o número: quando o callback responde
 * `ok`, a conexão já vem ativa, com número. `is_active` falso com conexão
 * existente é um estado de erro (o secret do webhook não pôde ser obtido) —
 * o caminho é conectar de novo.
 */
export function useZapmizerIntegration() {
  const integration = ref<ZapmizerConnection | null>(null);
  const loading = ref(true);
  const errorMessage = ref('');

  // Uma resposta atrasada do reload não pode sobrescrever um estado mais novo
  // (o "já autorizei" recarrega enquanto o popup também pode ter respondido).
  let request = 0;

  const isConnected = computed(() => Boolean(integration.value?.is_active && integration.value?.phone_number));

  /** `live` consulta o Zapmizer e traz `state` / `is_online` na conexão. */
  async function reload(options: { live?: boolean } = {}) {
    const current = ++request;
    errorMessage.value = '';

    try {
      const { data } = await axios.get<{ connection: ZapmizerConnection | null }>(
        route('zapmizer.connect.show', options.live ? { live: 1 } : {}),
        JSON_HEADERS,
      );

      if (current !== request) return;

      integration.value = data.connection ?? null;
    } catch {
      if (current !== request) return;

      errorMessage.value = 'Não foi possível carregar o estado da integração.';
    } finally {
      if (current === request) loading.value = false;
    }
  }

  const start = openZapmizerConnect;

  async function disconnect() {
    await axios.delete(route('zapmizer.connect.destroy'), JSON_HEADERS);

    request++;
    integration.value = null;
  }

  return { integration, loading, errorMessage, isConnected, reload, start, disconnect };
}
