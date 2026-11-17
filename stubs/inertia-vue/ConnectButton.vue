<script setup lang="ts">
import { AlertCircleIcon, ArrowRightIcon, MessageCircleIcon } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { openZapmizerConnect } from './useZapmizerIntegration';
import { brand } from './zapmizerBrand';
import type { ZapmizerConnectMessage } from './types';

withDefaults(defineProps<{ reauthRequired?: boolean }>(), { reauthRequired: false });

/**
 * `connected`: o popup respondeu `ok` ou `webhook_failed` — a conexão já está
 * ativa, com número. No `webhook_failed` o aviso de "não recebe" fica em
 * `error`, mas é passageiro: a tela troca para o painel, e o aviso que fica é
 * o do ConnectionPanel (`has_webhook_secret === false`).
 * `recheck`: o usuário clicou "já conectei" — quem decide é o estado que
 * voltar do servidor, não o clique.
 */
const emit = defineEmits<{ connected: []; recheck: [] }>();

const starting = ref(false);
const error = ref('');

const MESSAGES: Record<string, string> = {
    denied: 'Autorização cancelada. Tente novamente quando quiser conectar.',
    plan_limit:
        `O plano ${brand.do} não tem vaga para mais um número. ` +
        'Libere um número lá (ou mude de plano) e tente de novo.',
    subscription_required:
        `A conta ${brand.do} foi vinculada, mas não tem assinatura ativa. ` + 'Conecte de novo para assinar.',
    invalid_state: 'O link de autorização expirou. Tente novamente.',
    exchange_failed: 'Não foi possível concluir a conexão. Tente novamente.',
    rate_limited: `Muitas tentativas ${brand.no} agora. Feche a janela e conecte de novo em um minuto.`,
    webhook_failed:
        'Conectado e enviando, mas as mensagens recebidas não podem ser verificadas. ' +
        'Desconecte e conecte de novo para receber.',
    team_already_connected:
        `Essa conta ${brand.do} já está conectada a outra conta aqui. ` +
        `Desconecte lá primeiro ou autorize outro time ${brand.do}.`,
    no_connectable: 'Não há o que conectar nesta conta. Entre de novo e tente outra vez.',
};

/**
 * O popup do callback fala com esta página por postMessage. Confiar no payload
 * antes de conferir a origem deixaria qualquer aba aberta em outro site
 * declarar "conectado" e pular o passo.
 */
function onMessage(event: MessageEvent<Partial<ZapmizerConnectMessage>>) {
    if (event.origin !== window.location.origin) return;
    if (event.data?.source !== 'zapmizer-connect') return;

    if (event.data.status === 'ok' || event.data.status === 'webhook_failed') {
        error.value = event.data.status === 'webhook_failed' ? MESSAGES.webhook_failed : '';
        emit('connected');

        return;
    }

    error.value = MESSAGES[event.data.status ?? ''] || event.data.message || MESSAGES.exchange_failed;
}

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));

async function connect() {
    error.value = '';
    starting.value = true;

    try {
        const popup = await openZapmizerConnect();

        if (!popup) error.value = 'O navegador bloqueou a janela — libere popups para este site e tente de novo.';
    } catch (e) {
        const response = (e as { response?: { status?: number; data?: { message?: string } } }).response;
        const status = response?.status ?? 0;

        // Mensagem do servidor só vale em 4xx (validação, throttle). Em 5xx ela é o
        // texto da exceção — em dev vaza stack do Zapmizer, em produção vira
        // "Server Error"; nenhum dos dois diz ao usuário o que fazer.
        error.value =
            status === 503
                ? `${brand.O} está indisponível no momento. Tente novamente em instantes.`
                : status >= 500 || status === 0
                ? `Não foi possível falar com ${brand.o} agora. Tente novamente em instantes.`
                : response?.data?.message ?? 'Não foi possível iniciar a conexão. Tente novamente.';
    } finally {
        starting.value = false;
    }
}
</script>

<template>
    <section class="zapmizer-connect">
        <div class="zapmizer-connect__eyebrow">Conexão</div>
        <h3 class="zapmizer-connect__title">Conecte um número de WhatsApp</h3>
        <p class="zapmizer-connect__description">
            É a conta {{ brand.do }} que fala com o WhatsApp. Na janela que abre você entra, autoriza e lê o QR code com
            o celular — tudo lá.
        </p>

        <p v-if="reauthRequired" class="zapmizer-connect__warning">
            <AlertCircleIcon class="zapmizer-connect__icon" :size="14" />
            A autorização {{ brand.com }} expirou. Conecte novamente para continuar.
        </p>

        <p v-if="error" class="zapmizer-connect__error">
            <AlertCircleIcon class="zapmizer-connect__icon" :size="14" />
            {{ error }}
        </p>

        <div class="zapmizer-connect__box">
            <div class="zapmizer-connect__badge">
                <MessageCircleIcon :size="20" />
            </div>
            <p class="zapmizer-connect__box-title">Uma janela {{ brand.do }} vai abrir</p>
            <p class="zapmizer-connect__hint">
                Entre (ou crie sua conta), autorize o acesso e leia o QR code. Você volta para cá automaticamente.
            </p>
            <button type="button" class="zapmizer-connect__button" :disabled="starting" @click="connect">
                {{ starting ? `Abrindo ${brand.o}...` : `Conectar ${brand.com}` }}
                <ArrowRightIcon :size="14" />
            </button>
            <!-- Popup fechado na mão, ou bloqueado e reaberto por fora: sem esta saída
                o usuário fica olhando o botão com a conexão já concluída. -->
            <button type="button" class="zapmizer-connect__recheck" @click="emit('recheck')">Já conectei</button>
        </div>
    </section>
</template>
