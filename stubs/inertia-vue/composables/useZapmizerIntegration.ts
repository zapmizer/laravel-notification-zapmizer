import axios from 'axios';
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import type { ZapmizerConnection, ZapmizerRelinkResult } from '@/types/zapmizer';

const JSON_HEADERS = { headers: { Accept: 'application/json' } };

/** Intervalo normal do polling do `show?live=1`. */
const POLL_INTERVAL_MS = 15000;
/** Intervalo acelerado, enquanto o número não volta a `connected`. */
const FAST_POLL_INTERVAL_MS = 5000;
/** Por quanto tempo o polling fica acelerado depois de religar. */
const FAST_POLL_WINDOW_MS = 120000;
/**
 * Depois de religar, a Zapmizer ainda responde `off` por alguns segundos
 * enquanto o número sobe: nessa janela o `off` é ignorado e o painel segue
 * mostrando "Conectando".
 */
const TURN_ON_GRACE_MS = 30000;

/** Estados em que perguntar de novo não muda nada sem o usuário agir. */
const FINAL_STATES = ['reauth_required', 'instance_gone'];

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

  // Uma resposta atrasada (do reload ou do polling) não pode sobrescrever um
  // estado mais novo: cada pedido leva o número da vez e só aplica a resposta
  // se ainda for o último. `stopPolling()` também avança o número.
  let request = 0;

  let timer: ReturnType<typeof setTimeout> | null = null;
  let polling = false;
  let fastUntil = 0;
  let maskOffUntil = 0;

  const isConnected = computed(() => Boolean(integration.value?.is_active && integration.value?.phone_number));

  /**
   * Aplica o que o `show` respondeu. Na janela depois de religar, um `off`
   * fica de fora: o número ainda está subindo.
   */
  function apply(connection: ZapmizerConnection | null) {
    if (connection?.state === 'off' && Date.now() < maskOffUntil) return;

    integration.value = connection;
  }

  async function fetchConnection(live: boolean) {
    const { data } = await axios.get<{ connection: ZapmizerConnection | null }>(
      route('zapmizer.connect.show', live ? { live: 1 } : {}),
      JSON_HEADERS,
    );

    return data.connection ?? null;
  }

  /** `live` consulta o Zapmizer e traz `state` / `is_online` na conexão. */
  async function reload(options: { live?: boolean } = {}) {
    const current = ++request;
    errorMessage.value = '';

    try {
      const connection = await fetchConnection(Boolean(options.live));

      if (current !== request) return;

      apply(connection);
    } catch {
      if (current !== request) return;

      errorMessage.value = 'Não foi possível carregar o estado da integração.';
    } finally {
      if (current === request) loading.value = false;
    }
  }

  function clearTimer() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
  }

  function schedule() {
    clearTimer();

    if (!polling) return;

    const fast = Date.now() < fastUntil && integration.value?.state !== 'connected';

    timer = setTimeout(poll, fast ? FAST_POLL_INTERVAL_MS : POLL_INTERVAL_MS);
  }

  async function poll() {
    timer = null;

    const current = ++request;
    let connection: ZapmizerConnection | null;

    try {
      connection = await fetchConnection(true);
    } catch (e) {
      if (current !== request) return;

      loading.value = false;

      // 403 `no_connectable`: nada a consultar sem o usuário agir. Rede, 429
      // e 503 são passageiros: segue consultando.
      if ((e as { response?: { status?: number } }).response?.status === 403) {
        stopPolling();

        return;
      }

      schedule();

      return;
    }

    if (current !== request) return;

    loading.value = false;
    errorMessage.value = '';
    apply(connection);

    // Desconectado, token revogado ou instância apagada: só uma ação do
    // usuário (conectar de novo) muda isso.
    if (connection === null || FINAL_STATES.includes(connection.state ?? '')) {
      stopPolling();

      return;
    }

    schedule();
  }

  /** Consulta `show?live=1` agora e depois a cada 15 s (5 s depois de religar). */
  function startPolling() {
    stopPolling();
    polling = true;
    poll();
  }

  /** Para o polling e descarta a resposta que estiver a caminho. */
  function stopPolling() {
    polling = false;
    request++;
    clearTimer();
  }

  /**
   * Depois de religar (`online`/`starting` ou o popup com `reconnected`):
   * mostra "Conectando", ignora o `off` por 30 s e acelera o polling por 2
   * minutos, até o número voltar a `connected`.
   */
  function beginTurnOn() {
    if (integration.value) {
      integration.value = { ...integration.value, state: 'connecting', is_online: false };
    }

    maskOffUntil = Date.now() + TURN_ON_GRACE_MS;
    fastUntil = Date.now() + FAST_POLL_WINDOW_MS;
    startPolling();
  }

  // Desmontou o componente (ou o escopo): o polling para junto.
  if (getCurrentScope()) onScopeDispose(stopPolling);

  const start = openZapmizerConnect;
  const relink = relinkZapmizer;

  async function disconnect() {
    await axios.delete(route('zapmizer.connect.destroy'), JSON_HEADERS);

    stopPolling();
    integration.value = null;
  }

  return {
    integration,
    loading,
    errorMessage,
    isConnected,
    reload,
    start,
    relink,
    disconnect,
    startPolling,
    stopPolling,
    beginTurnOn,
  };
}
