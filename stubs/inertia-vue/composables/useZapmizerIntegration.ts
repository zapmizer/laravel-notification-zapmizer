import axios from 'axios';
import { computed, ref } from 'vue';
import type { ZapmizerConnection, ZapmizerRelinkResult } from '@/types/zapmizer';

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
 * Religa o número já conectado, com o token que a conexão guarda. `online` e
 * `starting` não abrem nada; `needs_client` abre o popup do Zapmizer, e o
 * resultado chega por postMessage com `source: 'zapmizer-reconnect'` — ver
 * ConnectionPanel; `refused` traz `code` e `message`. Os erros (409
 * `no_instance`/`reauth_required`, 503, 403, 429) sobem como o erro do axios,
 * com o código em `response.data.code`.
 */
export async function relinkZapmizer(): Promise<ZapmizerRelinkResult> {
  const { data } = await axios.post<ZapmizerRelinkResult>(route('zapmizer.connect.reconnect'), {}, JSON_HEADERS);

  if (data.status === 'needs_client') {
    window.open(data.url, 'zapmizer-reconnect', 'width=520,height=720');
  }

  return data;
}

/**
 * Estado da integração do time — de onde a tela decide se mostra o botão de
 * conectar ou o painel do número conectado.
 *
 * O popup do Zapmizer autoriza E pareia o número: quando o callback responde
 * `ok`, a conexão já vem ativa, com número. O connect grava a conexão ativa
 * mesmo no `webhook_failed`: ela envia, e "não recebe mensagens" se vê por
 * `has_webhook_secret === false`.
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
  const relink = relinkZapmizer;

  async function disconnect() {
    await axios.delete(route('zapmizer.connect.destroy'), JSON_HEADERS);

    request++;
    integration.value = null;
  }

  return { integration, loading, errorMessage, isConnected, reload, start, relink, disconnect };
}
