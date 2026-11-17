<script setup lang="ts">
import {
    AlertCircleIcon,
    Loader2Icon,
    RefreshCwIcon,
    RotateCcwIcon,
    SmartphoneIcon,
    Trash2Icon,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { relinkZapmizer } from './useZapmizerIntegration';
import { brand } from './zapmizerBrand';
import type { ZapmizerConnection, ZapmizerReconnectMessage } from './types';

/**
 * O número conectado e o estado dele. `integration` é o que `show?live=1`
 * devolve — `state`/`is_online` só existem quando a tela pediu `live`
 * (`useZapmizerIntegration().reload({ live: true })`); sem eles o painel só
 * sabe o que está gravado.
 */
const props = withDefaults(
    defineProps<{ integration: ZapmizerConnection; refreshing?: boolean; removing?: boolean }>(),
    { refreshing: false, removing: false }
);

/**
 * `reconnect` ("Trocar número") é um connect novo, com outro número ou outra
 * conta. "Religar número" é outra coisa: traz de volta o MESMO número, com o
 * token que a conexão já tem, e é resolvido aqui dentro. Quando ele dá certo
 * (`online`, `starting` ou o popup com `reconnected`), o painel emite
 * `relinked`: ligue em `beginTurnOn()` do `useZapmizerIntegration`, que
 * acelera o polling e segura o `off` enquanto o número sobe.
 */
const emit = defineEmits<{ refresh: []; relinked: []; reconnect: []; disconnect: [] }>();

const number = computed(() => props.integration.phone_number || '');
const teamName = computed(() => props.integration.zapmizer_team_name ?? null);

/** O religar respondeu 409: o token não serve mais, ou a instância sumiu. */
const relinkNeedsConnect = ref(false);

const reauthRequired = computed(() => props.integration.state === 'reauth_required' || relinkNeedsConnect.value);

/** Conectado, mas sem secret do webhook: envia e não recebe. `undefined` não acende. */
const cannotReceive = computed(
    () => props.integration.webhook_id !== null && props.integration.has_webhook_secret === false
);

const canRelink = computed(
    () => props.integration.is_active && ['disconnected', 'off', 'qr_pending'].includes(props.integration.state ?? '')
);

const health = computed(() => {
    const state = props.integration.state;

    if (!props.integration.is_active) return { tone: 'zapmizer-panel__status--error', label: 'Inativa' };
    if (state === 'reauth_required') return { tone: 'zapmizer-panel__status--error', label: 'Sem autorização' };
    if (state === 'instance_gone')
        return { tone: 'zapmizer-panel__status--error', label: `Número removido ${brand.no}` };
    if (state === 'zapmizer_unavailable')
        return { tone: 'zapmizer-panel__status--warning', label: `${brand.name} instável` };
    if (props.integration.is_online) return { tone: 'zapmizer-panel__status--connected', label: 'Online' };
    if (state === 'connected') return { tone: 'zapmizer-panel__status--connected', label: 'Conectado' };
    if (state === 'connecting') return { tone: 'zapmizer-panel__status--pending', label: 'Conectando...' };
    if (state === 'disconnected' || state === 'off' || state === 'qr_pending')
        return { tone: 'zapmizer-panel__status--warning', label: 'Desconectado no celular' };
    if (state === undefined) return { tone: 'zapmizer-panel__status--connected', label: 'Conectado' };

    return { tone: 'zapmizer-panel__status--pending', label: 'Verificando...' };
});

const confirmingRemoval = ref(false);

const relinking = ref(false);
const relinkMessage = ref('');

const UNAVAILABLE = `${brand.O} está indisponível no momento. Tente novamente em instantes.`;
const RELINK_FAILED = 'Não foi possível religar o número. Tente de novo.';

const RELINK_MESSAGES: Record<string, string> = {
    denied: 'Religação cancelada.',
    plan_limit:
        `O plano ${brand.do} não tem vaga para mais um número. ` +
        'Libere um número lá (ou mude de plano) e tente de novo.',
    subscription_required: `A conta ${brand.do} não tem assinatura ativa. Assine ${brand.no} e tente de novo.`,
};

async function relink() {
    relinkMessage.value = '';
    relinkNeedsConnect.value = false;
    relinking.value = true;

    try {
        const result = await relinkZapmizer();

        // `needs_client`: o popup abriu; o resultado chega por postMessage.
        if (result.status === 'online' || result.status === 'starting') emit('relinked');
        if (result.status === 'refused') relinkMessage.value = result.message;
    } catch (e) {
        const status = (e as { response?: { status?: number } }).response?.status ?? 0;

        if (status === 409) relinkNeedsConnect.value = true;
        else relinkMessage.value = status === 503 || status === 429 ? UNAVAILABLE : RELINK_FAILED;
    } finally {
        relinking.value = false;
    }
}

/** Mesma conferência de origem do ConnectButton, para o popup do religar. */
function onMessage(event: MessageEvent<Partial<ZapmizerReconnectMessage>>) {
    if (event.origin !== window.location.origin) return;
    if (event.data?.source !== 'zapmizer-reconnect') return;

    if (event.data.status === 'reconnected') {
        relinkMessage.value = '';
        emit('relinked');

        return;
    }

    relinkMessage.value = RELINK_MESSAGES[event.data.status ?? ''] ?? RELINK_FAILED;
}

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));
</script>

<template>
    <section class="zapmizer-panel">
        <header class="zapmizer-panel__header">
            <div>
                <div class="zapmizer-panel__eyebrow">Conexão</div>
                <h3 class="zapmizer-panel__title">WhatsApp conectado</h3>
                <p class="zapmizer-panel__description">O número por onde o sistema fala com o time.</p>
            </div>
            <span class="zapmizer-panel__status" :class="health.tone">{{ health.label }}</span>
        </header>

        <div class="zapmizer-panel__body">
            <div class="zapmizer-panel__number">
                <div class="zapmizer-panel__badge">
                    <SmartphoneIcon :size="16" />
                </div>
                <div class="zapmizer-panel__details">
                    <p class="zapmizer-panel__phone">{{ number || 'Número não identificado' }}</p>
                    <p class="zapmizer-panel__account">
                        Conta {{ brand.no }}:
                        <span class="zapmizer-panel__team">{{ teamName || 'não identificada' }}</span>
                        <template v-if="integration.api_token_masked">
                            · credencial {{ integration.api_token_masked }}
                        </template>
                    </p>
                </div>
            </div>

            <p v-if="reauthRequired" class="zapmizer-panel__error">
                <AlertCircleIcon class="zapmizer-panel__icon" :size="14" />
                A autorização {{ brand.com }} expirou. Conecte de novo para renovar o acesso.
            </p>

            <p v-else-if="!integration.is_active" class="zapmizer-panel__error">
                <AlertCircleIcon class="zapmizer-panel__icon" :size="14" />
                A conexão está inativa. Conecte de novo.
            </p>

            <p v-if="cannotReceive" class="zapmizer-panel__warning">
                <AlertCircleIcon class="zapmizer-panel__icon" :size="14" />
                Este número envia, mas não recebe mensagens: desconecte e conecte de novo.
            </p>

            <p v-if="relinkMessage" class="zapmizer-panel__error">
                <AlertCircleIcon class="zapmizer-panel__icon" :size="14" />
                {{ relinkMessage }}
            </p>

            <div class="zapmizer-panel__actions">
                <button
                    type="button"
                    class="zapmizer-panel__button zapmizer-panel__button--refresh"
                    :disabled="refreshing"
                    @click="emit('refresh')"
                >
                    <RefreshCwIcon
                        class="zapmizer-panel__icon"
                        :class="{ 'zapmizer-panel__icon--spinning': refreshing }"
                        :size="14"
                    />
                    Atualizar estado
                </button>
                <button
                    v-if="canRelink"
                    type="button"
                    class="zapmizer-panel__button zapmizer-panel__button--relink"
                    :disabled="relinking"
                    @click="relink"
                >
                    <Loader2Icon
                        v-if="relinking"
                        class="zapmizer-panel__icon zapmizer-panel__icon--spinning"
                        :size="14"
                    />
                    <RotateCcwIcon v-else class="zapmizer-panel__icon" :size="14" />
                    Religar número
                </button>
                <!-- Connect novo (outro número ou outra conta), não o religar. -->
                <button
                    type="button"
                    class="zapmizer-panel__button zapmizer-panel__button--reconnect"
                    @click="emit('reconnect')"
                >
                    Trocar número
                </button>
                <button
                    v-if="!confirmingRemoval"
                    type="button"
                    class="zapmizer-panel__button zapmizer-panel__button--disconnect"
                    @click="confirmingRemoval = true"
                >
                    <Trash2Icon class="zapmizer-panel__icon" :size="14" />
                    Desconectar
                </button>
                <div v-else class="zapmizer-panel__confirm">
                    <span class="zapmizer-panel__confirm-text">Desconectar? O time para de receber e responder.</span>
                    <button
                        type="button"
                        class="zapmizer-panel__button zapmizer-panel__button--confirm"
                        :disabled="removing"
                        @click="emit('disconnect')"
                    >
                        <Loader2Icon
                            v-if="removing"
                            class="zapmizer-panel__icon zapmizer-panel__icon--spinning"
                            :size="14"
                        />
                        Confirmar
                    </button>
                    <button
                        type="button"
                        class="zapmizer-panel__button zapmizer-panel__button--cancel"
                        @click="confirmingRemoval = false"
                    >
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
