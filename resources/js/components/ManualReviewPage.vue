<!--
THESIS: Una bandeja de excepciones accionables; evita un dashboard de métricas sin resolución.
OWN-WORLD: Obrador editorial operativo, tabla densa, papel cálido, tinta y estados semánticos.
STORY: El administrador filtra evidencia, confirma vigencia y registra una decisión trazable.
FIRST VIEWPORT: Pestañas, propósito y sucursal arriba; toolbar y tabla ocupan el área principal.
FORM: Extensión list-first establecida; la única interrupción es el modal de acción protegida.
-->
<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue';
import { api, errorMessages, HttpError, idempotencyKey } from '../api';
import { formatDateTime } from '../dates';
import type { DataTableColumn, DataTableFilter } from '../types';
import ServerDataTable from './data-table/ServerDataTable.vue';
import BaseModal from './ui/BaseModal.vue';
import StatusBadge from './ui/StatusBadge.vue';

type ReviewTab = 'failures' | 'notifications' | 'webhooks';
type ReviewAction = 'resolve-failure' | 'retry-notification' | 'resolve-webhook';
type Row = Record<string, any>;

const props = defineProps<{ online: boolean; branchName: string }>();
const emit = defineEmits<{ notice: [message: string]; error: [title: string, messages: string[]] }>();

const tab = ref<ReviewTab>('failures');
const table = ref<InstanceType<typeof ServerDataTable> | null>(null);
const busy = ref(false);
const dirty = ref(false);
const resolutionInput = ref<HTMLTextAreaElement | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});
const modal = reactive<{ open: boolean; action: ReviewAction; row: Row | null }>({
    open: false,
    action: 'resolve-failure',
    row: null,
});
const form = reactive({ status: 'resolved', resolution: '' });
let mutationKey = idempotencyKey('manual-review');

const tabs: Array<{ id: ReviewTab; label: string }> = [
    { id: 'failures', label: 'Jobs fallidos' },
    { id: 'notifications', label: 'Entregas' },
    { id: 'webhooks', label: 'Webhooks' },
];
const content = computed(() => ({
    failures: {
        kicker: 'Colas',
        title: 'Jobs que requieren decisión',
        description: 'Metadata sanitizada: sin payload, excepción ni datos de otro tenant.',
        empty: 'fallo operativo',
    },
    notifications: {
        kicker: 'Canales',
        title: 'Entregas del tenant',
        description: 'Reintentos sólo cuando el destinatario y el evento siguen vigentes.',
        empty: 'entrega de notificación',
    },
    webhooks: {
        kicker: 'Evidencia externa',
        title: 'Webhooks recibidos',
        description: 'Firma, headers y payload original permanecen ocultos e inmutables.',
        empty: 'webhook',
    },
})[tab.value]);
const endpoint = computed(() => ({
    failures: '/operations/failures',
    notifications: '/operations/notification-deliveries',
    webhooks: '/operations/webhooks',
})[tab.value]);
const columns = computed<DataTableColumn[]>(() => ({
    failures: [
        { key: 'job_name', label: 'Job', sortable: true },
        { key: 'correlation_id', label: 'Correlación', priority: 'secondary' },
        { key: 'queue', label: 'Cola', priority: 'secondary' },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'failed_at', label: 'Falló', sortable: true, render: (row: Row) => formatDateTime(row.failed_at) },
        { key: 'resolved_at', label: 'Resuelto', sortable: true, priority: 'secondary', render: (row: Row) => formatDateTime(row.resolved_at) },
    ],
    notifications: [
        { key: 'subject', label: 'Entrega' },
        { key: 'channel', label: 'Canal', sortable: true, render: (row: Row) => channelLabel(row.channel) },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'attempts', label: 'Intentos', sortable: true, align: 'end' },
        { key: 'available_at', label: 'Disponible', sortable: true, priority: 'secondary', render: (row: Row) => formatDateTime(row.available_at) },
        { key: 'failed_at', label: 'Falló', sortable: true, render: (row: Row) => formatDateTime(row.failed_at) },
    ],
    webhooks: [
        { key: 'provider', label: 'Proveedor', sortable: true },
        { key: 'external_id', label: 'Referencia' },
        { key: 'signature_valid', label: 'Firma', sortable: true, render: (row: Row) => row.signature_valid ? 'Válida' : 'Inválida' },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'created_at', label: 'Recibido', sortable: true, render: (row: Row) => formatDateTime(row.created_at) },
        { key: 'reviewed_at', label: 'Revisado', sortable: true, priority: 'secondary', render: (row: Row) => formatDateTime(row.reviewed_at) },
    ],
})[tab.value] as DataTableColumn[]);
const filters = computed<DataTableFilter[]>(() => ({
    failures: [
        {
            key: 'status',
            label: 'Estado',
            options: ['failed', 'resolved'].map(value => ({ label: statusLabel(value), value })),
        },
    ],
    notifications: [
        {
            key: 'status',
            label: 'Estado',
            options: ['pending', 'sent', 'delivered', 'failed', 'cancelled']
                .map(value => ({ label: statusLabel(value), value })),
        },
        {
            key: 'channel',
            label: 'Canal',
            options: ['internal', 'email', 'pwa_push'].map(value => ({ label: channelLabel(value), value })),
        },
    ],
    webhooks: [
        {
            key: 'status',
            label: 'Estado',
            options: ['received', 'processed', 'rejected', 'failed', 'manual_review', 'resolved']
                .map(value => ({ label: statusLabel(value), value })),
        },
        {
            key: 'signature_valid',
            label: 'Firma',
            options: [{ label: 'Válida', value: '1' }, { label: 'Inválida', value: '0' }],
        },
    ],
})[tab.value] as DataTableFilter[]);
const modalTitle = computed(() => ({
    'resolve-failure': 'Registrar resolución del job',
    'retry-notification': 'Confirmar reintento',
    'resolve-webhook': 'Registrar revisión del webhook',
})[modal.action]);
const modalDescription = computed(() => ({
    'resolve-failure': 'La resolución queda auditada. El payload original no se reejecuta.',
    'retry-notification': 'Se comprobarán nuevamente el destinatario, la sucursal, la alerta y el canal.',
    'resolve-webhook': 'La evidencia recibida no se edita ni se reenvía al proveedor.',
})[modal.action]);
const decisionIdentity = computed(() => {
    if (!modal.row) return null;
    if (modal.action === 'resolve-failure') {
        return {
            title: String(modal.row.job_name),
            detail: `Correlación: ${modal.row.correlation_id || 'No disponible'}`,
        };
    }
    if (modal.action === 'retry-notification') {
        return {
            title: String(modal.row.subject),
            detail: `Canal: ${channelLabel(String(modal.row.channel))} · Intentos previos: ${modal.row.attempts}`,
        };
    }

    return {
        title: `${modal.row.provider || 'Proveedor no identificado'} · ${modal.row.external_id || 'Sin referencia'}`,
        detail: `Firma: ${modal.row.signature_valid ? 'Válida' : 'Inválida'}`,
    };
});

function statusLabel(value: string) {
    return ({
        failed: 'Fallido', resolved: 'Resuelto', pending: 'Pendiente', sent: 'Enviado',
        delivered: 'Entregado', cancelled: 'Cancelado', received: 'Recibido',
        processed: 'Procesado', rejected: 'Rechazado', manual_review: 'Revisión manual',
    } as Record<string, string>)[value] ?? value;
}

function channelLabel(value: string) {
    return ({ internal: 'Centro interno', email: 'Correo', pwa_push: 'Push PWA' } as Record<string, string>)[value] ?? value;
}

function openAction(action: ReviewAction, row: Row) {
    modal.action = action;
    modal.row = row;
    modal.open = true;
    fieldErrors.value = {};
    form.status = action === 'resolve-webhook' ? 'manual_review' : 'resolved';
    form.resolution = '';
    dirty.value = false;
    mutationKey = idempotencyKey(action);
}

function closeModal() {
    modal.open = false;
    dirty.value = false;
}

function field(name: string) {
    return fieldErrors.value[name]?.join(' ');
}

async function submit() {
    if (!props.online || busy.value || !modal.row) return;
    busy.value = true;
    fieldErrors.value = {};
    try {
        if (modal.action === 'retry-notification') {
            await api.post(
                `/operations/notification-deliveries/${modal.row.id}/retry`,
                {},
                mutationKey,
            );
            emit('notice', 'Entrega validada y devuelta a la cola.');
        } else if (modal.action === 'resolve-failure') {
            await api.post(
                `/operations/failures/${modal.row.id}/resolution`,
                { resolution: form.resolution },
                mutationKey,
            );
            emit('notice', 'Resolución del job registrada sin reintento genérico.');
        } else {
            await api.post(
                `/operations/webhooks/${modal.row.id}/resolution`,
                { status: form.status, resolution: form.resolution },
                mutationKey,
            );
            emit('notice', form.status === 'resolved' ? 'Webhook marcado como resuelto.' : 'Webhook enviado a revisión manual.');
        }
        closeModal();
        await table.value?.refresh();
    } catch (error) {
        if (error instanceof HttpError) {
            fieldErrors.value = error.errors;
            if (fieldErrors.value.resolution?.length) {
                await nextTick();
                resolutionInput.value?.focus();
            }
        }
        emit('error', 'No pudimos registrar la decisión', errorMessages(error));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="page manual-review-page">
        <nav class="module-tabs" aria-label="Tipos de revisión operacional">
            <button
                v-for="item in tabs"
                :key="item.id"
                type="button"
                :class="{ active: tab === item.id }"
                :aria-current="tab === item.id ? 'page' : undefined"
                @click="tab = item.id"
            >{{ item.label }}</button>
        </nav>

        <header class="list-page-header">
            <div>
                <p class="section-kicker">{{ content.kicker }} · {{ branchName }}</p>
                <h2>{{ content.title }}</h2>
                <p>{{ content.description }}</p>
            </div>
        </header>

        <p v-if="!online" class="offline-banner" role="status">
            Modo consulta: recuperá conexión para registrar resoluciones o reintentos.
        </p>

        <ServerDataTable
            :key="`${tab}-${branchName}`"
            ref="table"
            :endpoint="endpoint"
            :columns="columns"
            :filters="filters"
            :initial-sort="tab === 'failures' ? 'failed_at' : tab === 'notifications' ? 'created_at' : 'created_at'"
            :label="content.title"
            :empty-label="content.empty"
            persist-in-url
            @error="emit('error', 'No pudimos cargar la revisión', $event)"
        >
            <template #cell-status="{ row }">
                <StatusBadge :status="String(row.status)" :label="statusLabel(String(row.status))" />
            </template>
            <template #actions="{ row }">
                <button
                    v-if="tab === 'failures' && row.status === 'failed'"
                    class="table-row-action"
                    type="button"
                    :disabled="!online"
                    @click="openAction('resolve-failure', row)"
                >Registrar resolución</button>
                <span
                    v-else-if="tab === 'notifications' && row.status === 'failed' && row.channel === 'pwa_push'"
                    class="muted-action"
                    title="El canal push permanece deshabilitado durante el pre-piloto"
                >Push apagado</span>
                <button
                    v-else-if="tab === 'notifications' && row.status === 'failed'"
                    class="table-row-action"
                    type="button"
                    :disabled="!online"
                    @click="openAction('retry-notification', row)"
                >Reintentar</button>
                <button
                    v-else-if="tab === 'webhooks' && ['rejected', 'failed', 'manual_review'].includes(String(row.status))"
                    class="table-row-action"
                    type="button"
                    :disabled="!online"
                    @click="openAction('resolve-webhook', row)"
                >Revisar</button>
                <span v-else class="muted-action">Sin acciones</span>
            </template>
        </ServerDataTable>

        <BaseModal
            :open="modal.open"
            :title="modalTitle"
            :description="modalDescription"
            :busy="busy"
            :dirty="dirty"
            @close="closeModal"
            @discard="dirty = false"
        >
            <form id="manual-review-form" class="review-form" @submit.prevent="submit">
                <div v-if="decisionIdentity" class="decision-summary">
                    <strong>{{ decisionIdentity.title }}</strong>
                    <p>{{ decisionIdentity.detail }}</p>
                </div>
                <p v-if="modal.action === 'retry-notification'" class="decision-note">
                    Si el evento dejó de ser accionable, el servidor rechazará el reintento.
                </p>
                <template v-else>
                    <label v-if="modal.action === 'resolve-webhook'">
                        Decisión
                        <select v-model="form.status" autofocus @change="dirty = true">
                            <option value="manual_review">Mantener en revisión manual</option>
                            <option value="resolved">Marcar como resuelto</option>
                        </select>
                    </label>
                    <label>
                        Resolución
                        <textarea
                            ref="resolutionInput"
                            v-model="form.resolution"
                            :autofocus="modal.action !== 'resolve-webhook'"
                            :aria-invalid="Boolean(field('resolution'))"
                            :aria-describedby="field('resolution') ? 'resolution-help resolution-error' : 'resolution-help'"
                            required
                            minlength="3"
                            maxlength="1000"
                            rows="5"
                            @input="dirty = true"
                        />
                        <small id="resolution-help">Describí la evidencia revisada y por qué no corresponde editar ni reejecutar el payload.</small>
                        <span v-if="field('resolution')" id="resolution-error" class="field-error" role="alert">{{ field('resolution') }}</span>
                    </label>
                </template>
            </form>
            <template #footer="{ close }">
                <button class="secondary" type="button" :disabled="busy" @click="close">Cancelar</button>
                <button class="primary" type="submit" form="manual-review-form" :disabled="busy || !online">
                    {{ busy ? 'Registrando…' : modal.action === 'retry-notification' ? 'Validar y reintentar' : 'Registrar decisión' }}
                </button>
            </template>
        </BaseModal>
    </section>
</template>

<style scoped>
.manual-review-page { display: grid; gap: var(--space-5); }
.module-tabs {
    display: flex;
    gap: var(--space-1);
    border-bottom: 1px solid var(--line);
}
.module-tabs button {
    min-height: 44px;
    border: 0;
    border-bottom: 2px solid transparent;
    color: var(--ink-muted);
    background: transparent;
    padding: .65rem .85rem;
    font-weight: 750;
    white-space: nowrap;
}
.module-tabs button:hover,
.module-tabs button:focus-visible { color: var(--ink); background: var(--surface-muted); }
.module-tabs button.active { border-bottom-color: var(--terracotta); color: var(--terracotta-dark); }
.table-row-action {
    min-height: 36px;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    color: var(--terracotta-dark);
    background: var(--surface);
    padding: .4rem .65rem;
    font-weight: 750;
}
.table-row-action:hover:not(:disabled) { border-color: var(--terracotta); background: var(--surface-muted); }
.review-form { display: grid; gap: var(--space-4); }
.review-form label { display: grid; gap: var(--space-2); color: var(--ink-muted); }
.review-form small { max-width: 68ch; line-height: 1.45; color: var(--ink-muted); }
.decision-summary { display: grid; gap: var(--space-2); line-height: 1.5; }
.decision-summary p { margin: 0; color: var(--ink-muted); }
.decision-note { margin: 0; color: var(--ink-muted); line-height: 1.5; }
.muted-action { color: var(--ink-muted); font-size: .875rem; }
@media (max-width: 560px) {
    .module-tabs { overflow-x: auto; justify-content: flex-start; }
}
</style>
