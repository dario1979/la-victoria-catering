<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api, errorMessages, HttpError, idempotencyKey } from '../api';
import { formatCalendarDate, formatDateTime } from '../dates';
import type { DataTableColumn, DataTableFilter } from '../types';
import ServerDataTable from './data-table/ServerDataTable.vue';
import BaseModal from './ui/BaseModal.vue';
import RemoteSelect from './ui/RemoteSelect.vue';
import StatusBadge from './ui/StatusBadge.vue';

type FinanceTab = 'cash' | 'accounts' | 'payables' | 'reconciliation';
type ModalKind = 'register' | 'open' | 'movement' | 'close' | 'ledger' | 'payable' | 'reconciliation';
type Row = Record<string, any>;

const props = defineProps<{ online: boolean; branchName: string; role: string }>();
const emit = defineEmits<{ notice: [message: string]; error: [title: string, messages: string[]] }>();

const tab = ref<FinanceTab>('cash');
const table = ref<InstanceType<typeof ServerDataTable> | null>(null);
const busy = ref(false);
const dirty = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const modal = reactive<{ open: boolean; kind: ModalKind; row: Row | null }>({
    open: false, kind: 'open', row: null,
});
let mutationKey = idempotencyKey('finance');

const registerForm = reactive({ name: '' });
const openForm = reactive({ cash_register_id: null as number | null, opening_balance: '0.00', observations: '' });
const movementForm = reactive({ kind: 'expense', amount: '0.00', reason: '' });
const closeForm = reactive({ counted_balance: '0.00', observations: '' });
const ledgerForm = reactive({
    customer_id: null as number | null, type: 'charge', amount: '0.00',
    description: '', due_on: '',
});
const payableForm = reactive({ amount: '0.00', method: 'transfer', external_reference: '' });
const reconciliationForm = reactive({
    provider: 'manual', internal_type: 'payment', internal_id: null as number | null,
    external_reference: '', external_amount: '0.00',
    external_date: new Date().toISOString().slice(0, 10), observations: '',
});

const canManage = computed(() => ['owner', 'admin', 'finance'].includes(props.role));
const canOperateCash = computed(() => ['owner', 'admin', 'sales', 'finance'].includes(props.role));
const tabs: Array<{ id: FinanceTab; label: string }> = [
    { id: 'cash', label: 'Caja' },
    { id: 'accounts', label: 'Cuenta corriente' },
    { id: 'payables', label: 'Cuentas por pagar' },
    { id: 'reconciliation', label: 'Conciliación' },
];
const content = computed(() => ({
    cash: {
        kicker: 'Turno y arqueo', title: 'Caja de la sucursal',
        description: 'Aperturas, movimientos inmutables, cierre y aprobación de diferencias.',
        action: 'Abrir caja', empty: 'sesión de caja',
    },
    accounts: {
        kicker: 'Clientes', title: 'Cuentas corrientes',
        description: 'Saldo derivado del ledger, vencimientos, anticipos, créditos y reversos.',
        action: 'Registrar movimiento', empty: 'cuenta corriente',
    },
    payables: {
        kicker: 'Proveedores', title: 'Cuentas por pagar',
        description: 'Obligaciones creadas desde recepciones y pagos manuales trazables.',
        action: '', empty: 'obligación',
    },
    reconciliation: {
        kicker: 'Evidencia externa', title: 'Conciliación',
        description: 'Comparación manual preparada para bancos y proveedores de cobro.',
        action: 'Nueva conciliación', empty: 'conciliación',
    },
})[tab.value]);
const endpoint = computed(() => ({
    cash: '/cash-sessions',
    accounts: '/customer-accounts',
    payables: '/accounts-payable',
    reconciliation: '/reconciliations',
})[tab.value]);
const columns = computed<DataTableColumn[]>(() => ({
    cash: [
        { key: 'register.name', label: 'Caja' },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'opened_at', label: 'Apertura', sortable: true, render: (row: Row) => formatDateTime(row.opened_at) },
        { key: 'expected_balance_cents', label: 'Esperado', align: 'end', render: (row: Row) => moneyCents(row.expected_balance_cents) },
        { key: 'difference_cents', label: 'Diferencia', sortable: true, align: 'end', render: (row: Row) => moneyCents(row.difference_cents) },
    ],
    accounts: [
        { key: 'name', label: 'Cliente', sortable: true },
        { key: 'tax_id', label: 'CUIT', priority: 'secondary' },
        { key: 'balance_cents', label: 'Saldo', sortable: true, align: 'end', render: (row: Row) => moneyCents(row.balance_cents) },
        { key: 'overdue_debt_cents', label: 'Vencido', align: 'end', render: (row: Row) => moneyCents(row.overdue_debt_cents) },
        { key: 'credit_limit', label: 'Límite', align: 'end', priority: 'secondary', render: (row: Row) => money(row.credit_limit) },
    ],
    payables: [
        { key: 'supplier.trade_name', label: 'Proveedor' },
        { key: 'document', label: 'Documento', sortable: true },
        { key: 'due_on', label: 'Vencimiento', sortable: true, render: (row: Row) => formatCalendarDate(row.due_on) },
        { key: 'total_cents', label: 'Total', sortable: true, align: 'end', render: (row: Row) => moneyCents(row.total_cents) },
        { key: 'paid_cents', label: 'Pagado', sortable: true, align: 'end', render: (row: Row) => moneyCents(row.paid_cents) },
        { key: 'operational_status', label: 'Estado', sortable: true },
    ],
    reconciliation: [
        { key: 'provider', label: 'Proveedor', sortable: true },
        { key: 'external_reference', label: 'Referencia' },
        { key: 'external_date', label: 'Fecha', sortable: true, render: (row: Row) => formatCalendarDate(row.external_date) },
        { key: 'difference_cents', label: 'Diferencia', sortable: true, align: 'end', render: (row: Row) => moneyCents(row.difference_cents) },
        { key: 'status', label: 'Estado', sortable: true },
    ],
})[tab.value] as DataTableColumn[]);
const filters = computed<DataTableFilter[]>(() => ({
    cash: [{
        key: 'status', label: 'Estado',
        options: ['open', 'closing', 'closed', 'closed_with_difference'].map(value => ({ label: status(value), value })),
    }],
    accounts: [{ key: 'active', label: 'Estado', options: [{ label: 'Activa', value: '1' }, { label: 'Inactiva', value: '0' }] }],
    payables: [{
        key: 'status', label: 'Estado',
        options: ['open', 'partial', 'paid', 'overdue'].map(value => ({ label: status(value), value })),
    }],
    reconciliation: [{
        key: 'status', label: 'Estado',
        options: ['pending', 'matched', 'mismatched', 'ignored', 'resolved'].map(value => ({ label: status(value), value })),
    }],
})[tab.value] as DataTableFilter[]);

const modalTitle = computed(() => ({
    register: 'Nueva caja',
    open: 'Abrir caja',
    movement: movementForm.kind === 'income' ? 'Registrar ingreso' : 'Registrar egreso',
    close: 'Cerrar y arquear caja',
    ledger: 'Movimiento de cuenta corriente',
    payable: 'Registrar pago al proveedor',
    reconciliation: 'Nueva conciliación',
})[modal.kind]);

function money(value: unknown) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(Number(value ?? 0));
}

function moneyCents(value: unknown) {
    return money(Number(value ?? 0) / 100);
}

function status(value: string) {
    return ({
        open: 'Abierta', closing: 'En cierre', closed: 'Cerrada',
        closed_with_difference: 'Cerrada con diferencia', partial: 'Parcial',
        paid: 'Pagada', overdue: 'Vencida', pending: 'Pendiente',
        matched: 'Coincidente', mismatched: 'Con diferencia',
        ignored: 'Ignorada', resolved: 'Resuelta',
    } as Record<string, string>)[value] ?? value;
}

function switchTab(next: FinanceTab) {
    tab.value = next;
}

function start(kind: ModalKind, row: Row | null = null) {
    modal.kind = kind;
    modal.row = row;
    modal.open = true;
    dirty.value = false;
    fieldErrors.value = {};
    mutationKey = idempotencyKey(`finance-${kind}`);
    if (kind === 'register') Object.assign(registerForm, { name: '' });
    if (kind === 'open') Object.assign(openForm, { cash_register_id: null, opening_balance: '0.00', observations: '' });
    if (kind === 'movement') Object.assign(movementForm, { kind: 'expense', amount: '0.00', reason: '' });
    if (kind === 'close') Object.assign(closeForm, {
        counted_balance: String(Number(row?.expected_balance_cents ?? row?.opening_balance_cents ?? 0) / 100),
        observations: '',
    });
    if (kind === 'ledger') Object.assign(ledgerForm, {
        customer_id: row?.id ?? null, type: 'charge', amount: '0.00', description: '', due_on: '',
    });
    if (kind === 'payable') Object.assign(payableForm, {
        amount: String(Math.max(0, Number(row?.total_cents ?? 0) - Number(row?.paid_cents ?? 0)) / 100),
        method: 'transfer', external_reference: '',
    });
    if (kind === 'reconciliation') Object.assign(reconciliationForm, {
        provider: 'manual', internal_type: 'payment', internal_id: null,
        external_reference: '', external_amount: '0.00',
        external_date: new Date().toISOString().slice(0, 10), observations: '',
    });
}

function closeModal() {
    modal.open = false;
}

function field(name: string) {
    return fieldErrors.value[name]?.join(' ');
}

async function submit() {
    if (!props.online || busy.value) return;
    busy.value = true;
    fieldErrors.value = {};
    try {
        if (modal.kind === 'register') {
            await api.post('/cash-registers', registerForm, mutationKey);
            emit('notice', 'Caja creada y autorizada para tu usuario.');
        } else if (modal.kind === 'open') {
            await api.post(`/cash-registers/${openForm.cash_register_id}/open`, {
                opening_balance: openForm.opening_balance, observations: openForm.observations || null,
            }, mutationKey);
            emit('notice', 'Caja abierta. El saldo inicial quedó registrado.');
        } else if (modal.kind === 'movement') {
            await api.post(`/cash-sessions/${modal.row?.id}/movements`, movementForm, mutationKey);
            emit('notice', 'Movimiento registrado de forma inmutable.');
        } else if (modal.kind === 'close') {
            await api.post(`/cash-sessions/${modal.row?.id}/close`, closeForm, mutationKey);
            emit('notice', 'Arqueo guardado. Las diferencias requieren aprobación.');
        } else if (modal.kind === 'ledger') {
            await api.post(`/customer-accounts/${ledgerForm.customer_id}/entries`, {
                ...ledgerForm, due_on: ledgerForm.due_on || null,
            }, mutationKey);
            emit('notice', 'Movimiento agregado al ledger del cliente.');
        } else if (modal.kind === 'payable') {
            await api.post(`/accounts-payable/${modal.row?.id}/payments`, payableForm, mutationKey);
            emit('notice', 'Pago al proveedor registrado.');
        } else {
            await api.post('/reconciliations', reconciliationForm, mutationKey);
            emit('notice', 'Conciliación creada con evidencia externa.');
        }
        modal.open = false;
        await table.value?.refresh();
    } catch (error) {
        if (error instanceof HttpError) fieldErrors.value = error.errors;
        emit('error', 'No pudimos registrar la operación', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

async function approve(row: Row) {
    if (!props.online || busy.value) return;
    busy.value = true;
    try {
        await api.post(`/cash-sessions/${row.id}/approve-difference`, {}, idempotencyKey('cash-difference'));
        emit('notice', 'Diferencia aprobada y alerta resuelta.');
        await table.value?.refresh();
    } catch (error) {
        emit('error', 'No pudimos aprobar la diferencia', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

async function resolveReconciliation(row: Row) {
    if (!props.online || busy.value) return;
    busy.value = true;
    try {
        const next = Number(row.difference_cents) === 0 ? 'matched' : 'resolved';
        await api.post(`/reconciliations/${row.id}/status`, {
            status: next, observations: row.observations ?? null,
        }, idempotencyKey('reconciliation-status'));
        emit('notice', next === 'matched' ? 'Conciliación marcada como coincidente.' : 'Diferencia resuelta.');
        await table.value?.refresh();
    } catch (error) {
        emit('error', 'No pudimos cerrar la conciliación', errorMessages(error));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="list-page finance-page">
        <nav class="module-tabs" aria-label="Secciones financieras">
            <button
                v-for="item in tabs"
                :key="item.id"
                :class="{ active: tab === item.id }"
                :aria-current="tab === item.id ? 'page' : undefined"
                @click="switchTab(item.id)"
            >{{ item.label }}</button>
        </nav>

        <header class="list-page-header">
            <div>
                <p class="section-kicker">{{ content.kicker }}</p>
                <h2>{{ content.title }}</h2>
                <p>{{ content.description }} <strong>{{ branchName }}</strong>.</p>
            </div>
            <div class="header-actions">
                <button v-if="tab === 'cash' && canManage" class="secondary" type="button" :disabled="!online" @click="start('register')">Nueva caja</button>
                <button v-if="content.action && (tab === 'cash' ? canOperateCash : canManage)" class="primary" type="button" :disabled="!online" @click="start(tab === 'cash' ? 'open' : tab === 'accounts' ? 'ledger' : 'reconciliation')">
                    {{ content.action }}
                </button>
            </div>
        </header>

        <p v-if="!online" class="offline-banner" role="status">Modo consulta: reconectate para registrar operaciones financieras.</p>

        <ServerDataTable
            :key="`${tab}-${branchName}`"
            ref="table"
            :endpoint="endpoint"
            :columns="columns"
            :filters="filters"
            :label="content.title"
            :empty-label="content.empty"
            persist-in-url
        >
            <template #cell-status="{ row }">
                <StatusBadge :status="String(row.status)" :label="status(String(row.status))" />
            </template>
            <template #cell-operational_status="{ row }">
                <StatusBadge :status="String(row.operational_status)" :label="status(String(row.operational_status))" />
            </template>
            <template #actions="{ row }">
                <div class="row-action-buttons">
                    <template v-if="tab === 'cash'">
                        <button v-if="row.status === 'open' && canOperateCash" type="button" :disabled="!online" @click="start('movement', row)">Movimiento</button>
                        <button v-if="['open', 'closing'].includes(String(row.status)) && canOperateCash" type="button" :disabled="!online" @click="start('close', row)">Cerrar</button>
                        <button v-if="row.status === 'closed_with_difference' && !row.difference_approved_at && canManage" type="button" :disabled="!online" @click="approve(row)">Aprobar diferencia</button>
                    </template>
                    <button v-else-if="tab === 'accounts' && canManage" type="button" :disabled="!online" @click="start('ledger', row)">Movimiento</button>
                    <button v-else-if="tab === 'payables' && canManage && row.status !== 'paid'" type="button" :disabled="!online" @click="start('payable', row)">Registrar pago</button>
                    <button v-else-if="tab === 'reconciliation' && canManage && ['pending', 'mismatched'].includes(String(row.status))" type="button" :disabled="!online" @click="resolveReconciliation(row)">Resolver</button>
                </div>
            </template>
        </ServerDataTable>

        <BaseModal
            :open="modal.open"
            :title="modalTitle"
            description="La operación quedará auditada y no se edita directamente."
            :busy="busy"
            :dirty="dirty"
            @close="closeModal"
            @discard="dirty = false"
        >
            <form id="finance-form" class="modal-form" @input="dirty = true" @submit.prevent="submit">
                <template v-if="modal.kind === 'register'">
                    <label>Nombre de la caja<input v-model="registerForm.name" required maxlength="120" autofocus><small v-if="field('name')" class="field-error">{{ field('name') }}</small></label>
                </template>
                <template v-else-if="modal.kind === 'open'">
                    <label class="wide">Caja
                        <RemoteSelect v-model="openForm.cash_register_id" endpoint="/cash-registers" label-key="name" :filters="{ active: '1' }" required autofocus />
                        <small v-if="field('cash_register_id')" class="field-error">{{ field('cash_register_id') }}</small>
                    </label>
                    <label>Saldo inicial<input v-model="openForm.opening_balance" required inputmode="decimal"></label>
                    <label class="wide">Observaciones<textarea v-model="openForm.observations" rows="3" maxlength="1000"></textarea></label>
                </template>
                <template v-else-if="modal.kind === 'movement'">
                    <label>Tipo<select v-model="movementForm.kind"><option value="income">Ingreso</option><option value="expense">Egreso</option></select></label>
                    <label>Importe<input v-model="movementForm.amount" required inputmode="decimal" autofocus></label>
                    <label class="wide">Motivo<input v-model="movementForm.reason" required maxlength="255"></label>
                </template>
                <template v-else-if="modal.kind === 'close'">
                    <label>Saldo contado<input v-model="closeForm.counted_balance" required inputmode="decimal" autofocus></label>
                    <label class="wide">Observaciones del arqueo<textarea v-model="closeForm.observations" rows="4" maxlength="1000"></textarea></label>
                </template>
                <template v-else-if="modal.kind === 'ledger'">
                    <label v-if="!modal.row" class="wide">Cliente
                        <RemoteSelect v-model="ledgerForm.customer_id" endpoint="/customers" label-key="name" secondary-key="tax_id" required autofocus />
                    </label>
                    <label>Tipo<select v-model="ledgerForm.type"><option value="charge">Cargo</option><option value="payment">Pago</option><option value="advance">Anticipo</option><option value="credit_note">Nota de crédito interna</option></select></label>
                    <label>Importe<input v-model="ledgerForm.amount" required inputmode="decimal"></label>
                    <label>Vencimiento<input v-model="ledgerForm.due_on" type="date"></label>
                    <label class="wide">Descripción<input v-model="ledgerForm.description" required maxlength="255"></label>
                </template>
                <template v-else-if="modal.kind === 'payable'">
                    <p class="wide form-context">Saldo disponible: <strong>{{ moneyCents(Number(modal.row?.total_cents) - Number(modal.row?.paid_cents)) }}</strong></p>
                    <label>Importe<input v-model="payableForm.amount" required inputmode="decimal" autofocus></label>
                    <label>Medio<select v-model="payableForm.method"><option value="transfer">Transferencia</option><option value="cash">Efectivo</option><option value="mercadopago">Mercado Pago</option><option value="card">Tarjeta</option><option value="other">Otro</option></select></label>
                    <label class="wide">Referencia externa<input v-model="payableForm.external_reference" maxlength="255"></label>
                </template>
                <template v-else>
                    <label>Proveedor<select v-model="reconciliationForm.provider"><option value="manual">Manual</option><option value="bank">Banco</option><option value="mercadopago">Mercado Pago</option><option value="cash">Caja</option></select></label>
                    <label>Movimiento interno<select v-model="reconciliationForm.internal_type"><option value="payment">Cobro</option><option value="cash_movement">Movimiento de caja</option><option value="payable_payment">Pago a proveedor</option></select></label>
                    <label>ID interno<input v-model.number="reconciliationForm.internal_id" required min="1" type="number"></label>
                    <label>Importe externo<input v-model="reconciliationForm.external_amount" required inputmode="decimal"></label>
                    <label>Fecha externa<input v-model="reconciliationForm.external_date" required type="date"></label>
                    <label class="wide">Referencia externa<input v-model="reconciliationForm.external_reference" required maxlength="255"></label>
                    <label class="wide">Observaciones<textarea v-model="reconciliationForm.observations" rows="3" maxlength="1000"></textarea></label>
                </template>
            </form>
            <template #footer>
                <button class="secondary" type="button" :disabled="busy" @click="closeModal">Cancelar</button>
                <button class="primary" form="finance-form" type="submit" :disabled="busy || !online">{{ busy ? 'Guardando…' : 'Confirmar' }}</button>
            </template>
        </BaseModal>
    </section>
</template>

<style scoped>
.header-actions,
.row-action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
}

.row-action-buttons button {
    min-height: 2.25rem;
    padding: .45rem .7rem;
}

.form-context {
    margin: 0;
    padding: .85rem 1rem;
    border-radius: .75rem;
    background: var(--surface-muted);
}

@media (max-width: 720px) {
    .header-actions,
    .header-actions button {
        width: 100%;
    }
}
</style>
