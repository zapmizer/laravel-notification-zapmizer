import axios, { type AxiosResponse } from 'axios';
import { onUnmounted, ref } from 'vue';
import type { ZapmizerInstanceConnection, ZapmizerInstance } from '@/types/zapmizer';

const BASE_INTERVAL_MS = 2500;
const MAX_BACKOFF_MS = 10000;

const JSON_HEADERS = { headers: { Accept: 'application/json' } };

export type ZapmizerConnectionStatus =
  | 'idle'
  | 'booting'
  | 'polling'
  | 'connected'
  | 'choice_required'
  | 'plan_limit'
  | 'reauth_required'
  | 'unavailable'
  | 'error';

interface InstancePayload {
  instance_id?: number;
  create?: boolean;
}

interface ConnectionResponse {
  code?: string;
  connection?: ZapmizerInstanceConnection | null;
  instances?: ZapmizerInstance[];
  message?: string;
}

interface UseZapmizerConnectionOptions {
  interval?: number;
  /** Continua consultando depois de conectado — o painel usa para o "online agora". */
  keepAlive?: boolean;
}

/**
 * Acompanha o pareamento do número no Zapmizer por polling (não há websocket
 * cross-app). Em 503 o intervalo cresce até 10s sem abortar: o Zapmizer
 * reiniciando não pode derrubar o wizard no meio.
 */
export function useZapmizerConnection({ interval = BASE_INTERVAL_MS, keepAlive = false }: UseZapmizerConnectionOptions = {}) {
  const connection = ref<ZapmizerInstanceConnection | null>(null);
  const status = ref<ZapmizerConnectionStatus>('idle');
  const errorMessage = ref('');
  const instances = ref<ZapmizerInstance[]>([]);
  const busy = ref(false);

  let timer: ReturnType<typeof setTimeout> | null = null;
  let delay = interval;

  // Geração invalida respostas em voo: stopPolling() incrementa, e todo request
  // re-checa depois do await — um poll disparado antes de voltar para a escolha
  // não pode aterrissar por cima do status novo.
  let generation = 0;

  function stopPolling() {
    generation++;

    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
  }

  function schedule(fn: () => void) {
    stopPolling();
    timer = setTimeout(fn, delay);
  }

  function backoff() {
    delay = Math.min(delay * 2, Math.max(MAX_BACKOFF_MS, interval));
  }

  function statusOf(error: unknown): number | undefined {
    return (error as { response?: { status?: number } }).response?.status;
  }

  function bodyOf(error: unknown): ConnectionResponse {
    return (error as { response?: { data?: ConnectionResponse } }).response?.data ?? {};
  }

  /**
   * Mensagem do servidor só serve em 4xx. Em 5xx ela é o texto da exceção —
   * stack do Zapmizer em dev, "Server Error" em produção: nenhum dos dois diz ao
   * usuário o que fazer.
   */
  function safeMessage(error: unknown, fallback: string): string {
    const code = statusOf(error) ?? 0;

    return code >= 400 && code < 500 ? (bodyOf(error).message ?? fallback) : fallback;
  }

  /** Trecho comum a poll() e attemptStart(): aterrissa a resposta boa. */
  function settle(data: ConnectionResponse) {
    connection.value = data.connection ?? null;

    const connected = data.connection?.state === 'connected';
    status.value = connected ? 'connected' : 'polling';

    if (!connected || keepAlive) schedule(poll);
  }

  async function poll() {
    const gen = generation;
    let data: ConnectionResponse;

    try {
      ({ data } = await axios.get<ConnectionResponse>(route('zapmizer.connect.connection'), JSON_HEADERS));
    } catch (error) {
      if (gen !== generation) return;

      handlePollError(error);

      return;
    }

    if (gen !== generation) return;

    delay = interval;

    if (data.code === 'reauth_required') {
      status.value = 'reauth_required';

      return;
    }

    settle(data);
  }

  function handlePollError(error: unknown) {
    const code = statusOf(error);

    if (code === 503) {
      status.value = 'unavailable';
      backoff();
      schedule(poll);

      return;
    }

    status.value = 'error';
    errorMessage.value =
      code === 409
        ? 'Nenhuma conexão em andamento. Reinicie o processo.'
        : safeMessage(error, 'Não foi possível consultar o estado da conexão.');
  }

  async function start() {
    stopPolling();
    errorMessage.value = '';
    delay = interval;
    status.value = 'booting';

    return attemptStart();
  }

  async function attemptStart(payload: InstancePayload = {}) {
    const gen = generation;
    let response: AxiosResponse<ConnectionResponse>;

    try {
      response = await axios.post<ConnectionResponse>(route('zapmizer.connect.instance'), payload, JSON_HEADERS);
    } catch (error) {
      if (gen !== generation) return;

      handleStartError(error, payload);

      return;
    }

    if (gen !== generation) return;

    // Instância ainda subindo: consultar `connection` agora devolveria 409
    // no_instance — quem resolve o boot é o próprio instance(), então é ele que
    // se reagenda, mantendo o MESMO payload (um create precisa continuar sendo
    // create na repetição, senão o retry vira "resolva sozinho" e cria outra).
    if (response.status === 202) {
      schedule(() => attemptStart(payload));
      backoff();

      return;
    }

    delay = interval;

    if (response.data.code === 'reauth_required') {
      status.value = 'reauth_required';

      return;
    }

    if (response.data.code === 'choice_required') {
      instances.value = response.data.instances ?? [];
      status.value = 'choice_required';

      return;
    }

    settle(response.data);
  }

  function handleStartError(error: unknown, payload: InstancePayload = {}) {
    const code = statusOf(error);
    const body = bodyOf(error);

    // plan_limit e instance_unavailable preservam `instances`: a lista já
    // carregada é o caminho de volta do usuário.
    if (code === 422 && body.code === 'instance_unavailable') {
      status.value = 'choice_required';
      instances.value = body.instances ?? instances.value;
      errorMessage.value = body.message || 'Esse número não está mais disponível. Escolha outro.';

      return;
    }

    if (code === 422 && body.code === 'plan_limit') {
      status.value = 'plan_limit';
      errorMessage.value = body.message || '';

      return;
    }

    // Instância criada, mas o Zapmizer recusa a conexão por QR (time sem a
    // feature). Repetir não resolve — é configuração lá.
    if (code === 422 && body.code === 'qr_not_available') {
      status.value = 'error';
      errorMessage.value = body.message || 'O Zapmizer não liberou a conexão por QR Code para esse time.';

      return;
    }

    if (code === 409) {
      status.value = 'error';
      errorMessage.value = 'A autorização com o Zapmizer não foi concluída. Volte ao passo anterior e autorize novamente.';

      return;
    }

    if (code === 503) {
      status.value = 'unavailable';
      backoff();
      schedule(() => attemptStart(payload));

      return;
    }

    status.value = 'error';
    errorMessage.value = safeMessage(error, 'Não foi possível iniciar a conexão. Tente novamente.');
  }

  async function loadChoices() {
    stopPolling();
    errorMessage.value = '';

    const gen = generation;
    let data: { code?: string; instances?: ZapmizerInstance[] };

    try {
      ({ data } = await axios.get(route('zapmizer.connect.instances'), JSON_HEADERS));
    } catch (error) {
      if (gen !== generation) return;

      const code = statusOf(error);

      if (code === 503) {
        status.value = 'unavailable';
        backoff();
        schedule(loadChoices);

        return;
      }

      status.value = 'error';
      errorMessage.value =
        code === 409
          ? 'A autorização com o Zapmizer não foi concluída. Volte ao passo anterior e autorize novamente.'
          : safeMessage(error, 'Não foi possível carregar os números conectados.');

      return;
    }

    if (gen !== generation) return;

    delay = interval;

    if (data.code === 'reauth_required') {
      status.value = 'reauth_required';

      return;
    }

    // Lista vazia também vira choice_required: o estado vazio oferece só o
    // botão de conectar um número novo.
    instances.value = data.instances ?? [];
    status.value = 'choice_required';
  }

  async function adopt(id: number | null) {
    if (busy.value || id == null) return;

    busy.value = true;
    errorMessage.value = '';

    try {
      await attemptStart({ instance_id: id });
    } finally {
      busy.value = false;
    }
  }

  async function createNew() {
    if (busy.value) return;

    busy.value = true;
    errorMessage.value = '';
    status.value = 'booting';

    try {
      await attemptStart({ create: true });
    } finally {
      busy.value = false;
    }
  }

  function startPolling() {
    stopPolling();
    delay = interval;
    status.value = 'polling';
    poll();
  }

  onUnmounted(stopPolling);

  return {
    connection,
    status,
    errorMessage,
    instances,
    busy,
    start,
    restart: start,
    startPolling,
    stopPolling,
    loadChoices,
    adopt,
    createNew,
  };
}
