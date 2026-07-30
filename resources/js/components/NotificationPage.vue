<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { api, errorMessages } from '../api';
import { formatDateTime } from '../dates';
import type { DataTableColumn, DataTableFilter } from '../types';
import ServerDataTable from './data-table/ServerDataTable.vue';
import StatusBadge from './ui/StatusBadge.vue';

type Channel = 'internal' | 'email' | 'pwa_push';
type Preference = {
    channel: Channel;
    enabled: boolean;
    quiet_hours_start: string | null;
    quiet_hours_end: string | null;
    timezone: string;
};

const props = defineProps<{ online: boolean; branchName: string }>();
const emit = defineEmits<{ notice: [message: string]; error: [title: string, messages: string[]] }>();

const saving = ref(false);
const preferences = reactive<Preference[]>([
    { channel: 'internal', enabled: true, quiet_hours_start: null, quiet_hours_end: null, timezone: 'America/Argentina/Buenos_Aires' },
    { channel: 'email', enabled: true, quiet_hours_start: null, quiet_hours_end: null, timezone: 'America/Argentina/Buenos_Aires' },
    { channel: 'pwa_push', enabled: false, quiet_hours_start: null, quiet_hours_end: null, timezone: 'America/Argentina/Buenos_Aires' },
]);

const columns: DataTableColumn[] = [
    { key: 'subject', label: 'Notificación', sortable: true },
    { key: 'message', label: 'Detalle' },
    { key: 'channel', label: 'Canal', sortable: true, render: (row) => channelLabel(String(row.channel)) },
    { key: 'status', label: 'Estado', sortable: true },
    { key: 'action', label: 'Acción esperada', priority: 'secondary' },
    { key: 'created_at', label: 'Fecha', sortable: true, priority: 'secondary', render: (row) => formatDateTime(row.created_at) },
];
const filters: DataTableFilter[] = [
    {
        key: 'channel',
        label: 'Canal',
        options: ['internal', 'email', 'pwa_push'].map((channel) => ({ label: channelLabel(channel), value: channel })),
    },
    {
        key: 'status',
        label: 'Estado',
        options: ['pending', 'sent', 'delivered', 'failed', 'cancelled'].map((status) => ({
            label: statusLabel(status),
            value: status,
        })),
    },
];

function channelLabel(channel: string) {
    return ({ internal: 'Centro interno', email: 'Correo', pwa_push: 'Push PWA' } as Record<string, string>)[channel] ?? channel;
}

function statusLabel(status: string) {
    return ({
        pending: 'Pendiente',
        sent: 'Enviada',
        delivered: 'Entregada',
        failed: 'Fallida',
        cancelled: 'Cancelada',
    } as Record<string, string>)[status] ?? status;
}

async function loadPreferences() {
    try {
        const stored = await api.get<Preference[]>('/notification-preferences');
        stored.forEach((item) => {
            const target = preferences.find((preference) => preference.channel === item.channel);
            if (target) Object.assign(target, item);
        });
    } catch (error) {
        emit('error', 'No pudimos cargar tus preferencias', errorMessages(error));
    }
}

async function savePreferences() {
    if (!props.online) {
        emit('error', 'Sin conexión', ['Recuperá conexión para guardar tus preferencias.']);
        return;
    }
    saving.value = true;
    try {
        await api.put('/notification-preferences', { preferences });
        emit('notice', 'Preferencias de notificación actualizadas.');
    } catch (error) {
        emit('error', 'No pudimos guardar tus preferencias', errorMessages(error));
    } finally {
        saving.value = false;
    }
}

onMounted(loadPreferences);
</script>

<template>
    <section class="page notification-page">
        <header class="list-page-header">
            <div>
                <p class="section-kicker">Control · {{ branchName }}</p>
                <h2>Centro de notificaciones</h2>
                <p>Mensajes dirigidos a tu rol, con trazabilidad de entrega y sin duplicados por ocurrencia.</p>
            </div>
        </header>

        <section class="panel preferences-panel" aria-labelledby="notification-preferences-title">
            <div class="panel-head">
                <div>
                    <p class="section-kicker">Preferencias</p>
                    <h3 id="notification-preferences-title">Canales y horas silenciosas</h3>
                </div>
                <button class="primary" type="button" :disabled="saving || !online" @click="savePreferences">
                    {{ saving ? 'Guardando…' : 'Guardar preferencias' }}
                </button>
            </div>
            <p class="push-note">
                Push PWA permanece desactivado hasta configurar un transporte y claves VAPID. El centro interno y el correo no dependen de ese canal.
            </p>
            <div class="preference-grid">
                <fieldset v-for="preference in preferences" :key="preference.channel">
                    <legend>{{ channelLabel(preference.channel) }}</legend>
                    <label class="check-line">
                        <input v-model="preference.enabled" type="checkbox">
                        Canal habilitado
                    </label>
                    <div class="quiet-hours">
                        <label>Desde<input v-model="preference.quiet_hours_start" type="time"></label>
                        <label>Hasta<input v-model="preference.quiet_hours_end" type="time"></label>
                    </div>
                    <label>Zona horaria<input v-model="preference.timezone" required></label>
                </fieldset>
            </div>
        </section>

        <ServerDataTable
            endpoint="/notifications"
            :columns="columns"
            :filters="filters"
            initial-sort="created_at"
            label="Notificaciones personales"
            empty-label="notificación"
            :persist-in-url="true"
        >
            <template #cell-status="{ row }">
                <StatusBadge :status="String(row.status)" />
            </template>
        </ServerDataTable>
    </section>
</template>

<style scoped>
.notification-page { display: grid; gap: var(--space-5); }
.preferences-panel { padding: var(--space-5); }
.push-note {
    margin: calc(var(--space-2) * -1) 0 var(--space-4);
    color: var(--ink-muted);
    line-height: 1.5;
}
.preference-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); }
fieldset {
    min-width: 0;
    margin: 0;
    padding: var(--space-4);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    display: grid;
    gap: var(--space-3);
}
legend { padding: 0 var(--space-2); font-weight: 700; }
.check-line { display: flex; align-items: center; gap: var(--space-2); }
.check-line input { width: auto; }
.quiet-hours { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }
label { display: grid; gap: var(--space-1); color: var(--ink-muted); font-size: .9rem; }
@media (max-width: 900px) {
    .preference-grid { grid-template-columns: 1fr; }
}
@media (max-width: 560px) {
    .panel-head { align-items: stretch; flex-direction: column; }
    .panel-head .primary { width: 100%; }
}
</style>
