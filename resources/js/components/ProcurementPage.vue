<script setup lang="ts">
import { computed, reactive, ref, useId } from 'vue';
import { api, errorMessages, HttpError, idempotencyKey } from '../api';
import { formatCalendarDate, formatDateTime } from '../dates';
import { activeOptions, statusLabel, unitLabel } from '../operational';
import type { DataTableColumn, DataTableFilter, DataTableRowAction } from '../types';
import DataTableRowActions from './data-table/DataTableRowActions.vue';
import ServerDataTable from './data-table/ServerDataTable.vue';
import BaseModal from './ui/BaseModal.vue';
import ConfirmActionModal from './ui/ConfirmActionModal.vue';
import RemoteSelect from './ui/RemoteSelect.vue';
import StatusBadge from './ui/StatusBadge.vue';

type ProcurementTab = 'orders' | 'suppliers' | 'catalog' | 'receipts';
type ModalKind = 'supplier' | 'catalog' | 'order' | 'receipt' | 'detail';
type Row = Record<string, any>;

const props = defineProps<{
    online: boolean;
    branchName: string;
    role: string;
}>();
const emit = defineEmits<{ notice: [message: string]; error: [title: string, messages: string[]] }>();

const canManage = computed(() => ['owner', 'admin', 'purchasing'].includes(props.role));
const canReceive = computed(() => ['owner', 'admin', 'purchasing', 'inventory'].includes(props.role));
const canSeeSuppliers = computed(() => ['owner', 'admin', 'purchasing', 'inventory', 'production'].includes(props.role));
const tab = ref<ProcurementTab>('orders');
const table = ref<InstanceType<typeof ServerDataTable> | null>(null);
const busy = ref(false);
const formDirty = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const detail = ref<Row | null>(null);
const formId = `procurement-form-${useId()}`;
const selectedSupplierLabel = ref('');
const selectedProductLabel = ref('');
let orderKey = idempotencyKey('purchase-order');
let receiptKey = idempotencyKey('purchase-receipt');

const modal = reactive<{ open: boolean; kind: ModalKind; editing: boolean; row: Row | null }>({
    open: false, kind: 'detail', editing: false, row: null,
});
const confirmation = reactive<{
    open: boolean;
    title: string;
    description: string;
    entity: string;
    label: string;
    tone: 'primary' | 'danger';
    execute: null | (() => Promise<void>);
}>({
    open: false, title: '', description: '', entity: '', label: 'Confirmar',
    tone: 'primary', execute: null,
});

const supplierForm = reactive({
    id: 0, trade_name: '', legal_name: '', tax_id: '', email: '', phone: '',
    contact_name: '', address: '', payment_terms: '', lead_time_days: 0,
    notes: '', active: true,
});
const catalogForm = reactive({
    id: 0, supplier_id: null as number | null, product_id: null as number | null,
    supplier_code: '', purchase_unit: 'kg', conversion_factor: '1000.000000',
    minimum_quantity: '1.000', lead_time_days: 0, preferred: false, active: true,
    price: '0.00', currency: 'ARS', price_valid_from: new Date().toISOString().slice(0, 10),
});
const orderForm = reactive({
    id: 0, supplier_id: null as number | null, ordered_at: new Date().toISOString().slice(0, 10),
    expected_at: '', currency: 'ARS', payment_terms: '', notes: '',
    items: [newOrderItem()],
});
const receiptForm = reactive({
    order_id: 0, received_at: localDateTime(), notes: '',
    items: [] as Array<{
        purchase_order_item_id: number;
        product_name: string;
        purchase_unit: string;
        pending: string;
        location_id: number | null;
        received_quantity: string;
        accepted_quantity: string;
        rejected_quantity: string;
        discrepancy_type: string;
        discrepancy_reason: string;
        lot_code: string;
        manufactured_at: string;
        expires_at: string;
        actual_unit_cost: string;
    }>,
});

const tabs = computed(() => [
    { id: 'orders' as const, label: 'Órdenes' },
    ...(canSeeSuppliers.value ? [
        { id: 'suppliers' as const, label: 'Proveedores' },
        { id: 'catalog' as const, label: 'Catálogo y precios' },
    ] : []),
    { id: 'receipts' as const, label: 'Recepciones' },
]);
const content = computed(() => ({
    orders: {
        kicker: 'Abastecimiento',
        title: 'Órdenes de compra',
        description: 'Del borrador al ingreso de stock, con precios congelados y cantidades pendientes visibles.',
        action: 'Nueva orden',
        empty: 'orden de compra',
    },
    suppliers: {
        kicker: 'Red de suministro',
        title: 'Proveedores',
        description: 'Datos comerciales, condiciones, plazos y estado operativo.',
        action: 'Nuevo proveedor',
        empty: 'proveedor',
    },
    catalog: {
        kicker: 'Condiciones vigentes',
        title: 'Catálogo y precios',
        description: 'Relación proveedor-producto, unidad de compra, conversión e historial de precios.',
        action: 'Vincular producto',
        empty: 'producto de proveedor',
    },
    receipts: {
        kicker: 'Trazabilidad',
        title: 'Recepciones',
        description: 'Ingresos aceptados, rechazos, lotes y movimientos de stock asociados.',
        action: '',
        empty: 'recepción',
    },
})[tab.value]);
const endpoint = computed(() => ({
    orders: '/purchase-orders',
    suppliers: '/suppliers',
    catalog: '/supplier-products',
    receipts: '/purchase-receipts',
})[tab.value]);

const columns = computed<DataTableColumn[]>(() => ({
    orders: [
        { key: 'number', label: 'Orden', sortable: true },
        { key: 'supplier.trade_name', label: 'Proveedor' },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'expected_at', label: 'Entrega esperada', sortable: true, render: (row: Row) => formatCalendarDate(row.expected_at) },
        { key: 'total', label: 'Total', sortable: true, align: 'end' as const, render: (row: Row) => money(row.total, row.currency) },
    ],
    suppliers: [
        { key: 'trade_name', label: 'Proveedor', sortable: true },
        { key: 'tax_id', label: 'CUIT', priority: 'secondary' as const },
        { key: 'contact_name', label: 'Contacto' },
        { key: 'lead_time_days', label: 'Plazo', sortable: true, align: 'end' as const, render: (row: Row) => `${row.lead_time_days} días` },
        { key: 'active', label: 'Estado' },
    ],
    catalog: [
        { key: 'product.name', label: 'Producto' },
        { key: 'supplier.trade_name', label: 'Proveedor' },
        { key: 'purchase_unit', label: 'Compra', sortable: true, render: (row: Row) => unitLabel(String(row.purchase_unit)) },
        { key: 'conversion_factor', label: 'Factor', align: 'end' as const, priority: 'secondary' as const },
        { key: 'current_price', label: 'Precio', align: 'end' as const, render: (row: Row) => currentPrice(row) },
        { key: 'preferred', label: 'Preferido', render: (row: Row) => row.preferred ? 'Sí' : 'No' },
        { key: 'active', label: 'Estado' },
    ],
    receipts: [
        { key: 'number', label: 'Recepción', sortable: true },
        { key: 'order.number', label: 'Orden' },
        { key: 'order.supplier.trade_name', label: 'Proveedor' },
        { key: 'received_at', label: 'Fecha', sortable: true, render: (row: Row) => formatDateTime(row.received_at) },
        { key: 'items', label: 'Renglones', align: 'end' as const, render: (row: Row) => String(row.items?.length ?? 0) },
    ],
})[tab.value]);
const filters = computed<DataTableFilter[]>(() => ({
    orders: [{
        key: 'status', label: 'Estado',
        options: ['draft', 'approved', 'sent', 'partially_received', 'received', 'cancelled']
            .map(value => ({ label: statusLabel(value), value })),
    }],
    suppliers: [{ key: 'active', label: 'Estado', options: activeOptions() }],
    catalog: [
        { key: 'active', label: 'Estado', options: activeOptions() },
        { key: 'preferred', label: 'Preferido', options: [{ label: 'Sí', value: '1' }, { label: 'No', value: '0' }] },
    ],
    receipts: [],
})[tab.value]);

const modalTitle = computed(() => {
    if (modal.kind === 'detail') return detailTitle();
    if (modal.kind === 'receipt') return 'Registrar recepción';
    if (modal.kind === 'supplier') return modal.editing ? 'Editar proveedor' : 'Nuevo proveedor';
    if (modal.kind === 'catalog') return modal.editing ? 'Actualizar catálogo y precio' : 'Vincular producto';
    return modal.editing ? 'Editar orden de compra' : 'Nueva orden de compra';
});
const modalDescription = computed(() => ({
    supplier: 'Concentrá aquí la información necesaria para comprar y coordinar entregas.',
    catalog: 'La unidad y el factor deben convertir exactamente a la unidad del producto.',
    order: 'Los precios, unidades y factores quedan congelados cuando la orden se aprueba.',
    receipt: 'Sólo lo aceptado genera lote y movimiento de stock; lo rechazado abre una discrepancia.',
    detail: 'Detalle operativo y trazabilidad del registro.',
})[modal.kind]);

function newOrderItem() {
    return {
        supplier_product_id: null as number | null,
        label: '',
        quantity: '1.000',
        unit_price: '',
        purchase_unit: '',
    };
}

function localDateTime() {
    const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000);
    return now.toISOString().slice(0, 16);
}

function money(value: unknown, currency = 'ARS') {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency: String(currency || 'ARS') })
        .format(Number(value ?? 0));
}

function currentPrice(row: Row) {
    if (row.current_price) return money(row.current_price.price, row.current_price.currency);
    const price = Array.isArray(row.prices)
        ? row.prices[0]
        : null;
    return price ? money(price.price, price.currency) : 'Sin precio';
}

function switchTab(next: ProcurementTab) {
    tab.value = next;
    fieldErrors.value = {};
}

function resetModal(kind: ModalKind, editing = false, row: Row | null = null) {
    fieldErrors.value = {};
    formDirty.value = false;
    detail.value = null;
    Object.assign(modal, { open: true, kind, editing, row });
}

function openCreate() {
    if (!canManage.value) return;
    if (tab.value === 'suppliers') {
        Object.assign(supplierForm, {
            id: 0, trade_name: '', legal_name: '', tax_id: '', email: '', phone: '',
            contact_name: '', address: '', payment_terms: '', lead_time_days: 0,
            notes: '', active: true,
        });
        resetModal('supplier');
    } else if (tab.value === 'catalog') {
        Object.assign(catalogForm, {
            id: 0, supplier_id: null, product_id: null, supplier_code: '',
            purchase_unit: 'kg', conversion_factor: '1000.000000',
            minimum_quantity: '1.000', lead_time_days: 0, preferred: false,
            active: true, price: '0.00', currency: 'ARS',
            price_valid_from: new Date().toISOString().slice(0, 10),
        });
        selectedSupplierLabel.value = '';
        selectedProductLabel.value = '';
        resetModal('catalog');
    } else {
        Object.assign(orderForm, {
            id: 0, supplier_id: null, ordered_at: new Date().toISOString().slice(0, 10),
            expected_at: '', currency: 'ARS', payment_terms: '', notes: '',
            items: [newOrderItem()],
        });
        selectedSupplierLabel.value = '';
        orderKey = idempotencyKey('purchase-order');
        resetModal('order');
    }
}

async function openDetail(row: Row) {
    busy.value = true;
    resetModal('detail', false, row);
    try {
        detail.value = await api.get<Row>(`${endpoint.value}/${row.id}`);
    } catch (error) {
        modal.open = false;
        emit('error', 'No pudimos abrir el detalle', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

async function openEdit(row: Row) {
    busy.value = true;
    try {
        const record = await api.get<Row>(`${endpoint.value}/${row.id}`);
        if (tab.value === 'suppliers') {
            Object.assign(supplierForm, record);
            resetModal('supplier', true, record);
        } else if (tab.value === 'catalog') {
            const price = record.prices?.find((item: Row) => !item.valid_until) ?? record.prices?.[0];
            Object.assign(catalogForm, {
                ...record,
                price: price?.price ?? '0.00',
                currency: price?.currency ?? 'ARS',
                price_valid_from: price?.valid_from ?? new Date().toISOString().slice(0, 10),
            });
            selectedSupplierLabel.value = record.supplier?.trade_name ?? '';
            selectedProductLabel.value = record.product?.name ?? '';
            resetModal('catalog', true, record);
        } else {
            Object.assign(orderForm, {
                ...record,
                expected_at: record.expected_at?.slice(0, 10) ?? '',
                ordered_at: record.ordered_at?.slice(0, 10) ?? '',
                items: record.items.map((item: Row) => ({
                    supplier_product_id: item.supplier_product_id,
                    label: item.product_name,
                    quantity: item.quantity,
                    unit_price: item.unit_price,
                    purchase_unit: item.purchase_unit,
                })),
            });
            selectedSupplierLabel.value = record.supplier?.trade_name ?? '';
            resetModal('order', true, record);
        }
    } catch (error) {
        emit('error', 'No pudimos preparar la edición', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

async function openReceipt(row: Row) {
    busy.value = true;
    try {
        const order = await api.get<Row>(`/purchase-orders/${row.id}`);
        receiptForm.order_id = Number(order.id);
        receiptKey = idempotencyKey('purchase-receipt');
        receiptForm.received_at = localDateTime();
        receiptForm.notes = '';
        receiptForm.items = order.items
            .filter((item: Row) => Number(item.received_quantity) < Number(item.quantity))
            .map((item: Row) => {
                const pending = (Number(item.quantity) - Number(item.received_quantity)).toFixed(3);
                return {
                    purchase_order_item_id: Number(item.id), product_name: String(item.product_name),
                    purchase_unit: String(item.purchase_unit), pending,
                    location_id: null, received_quantity: pending, accepted_quantity: pending,
                    rejected_quantity: '0.000', discrepancy_type: '', discrepancy_reason: '',
                    lot_code: '', manufactured_at: '', expires_at: '', actual_unit_cost: String(item.unit_price),
                };
            });
        resetModal('receipt', false, order);
    } catch (error) {
        emit('error', 'No pudimos preparar la recepción', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

function removeOrderItem(index: number) {
    if (orderForm.items.length > 1) orderForm.items.splice(index, 1);
    formDirty.value = true;
}

function removeReceiptItem(index: number) {
    if (receiptForm.items.length > 1) receiptForm.items.splice(index, 1);
    formDirty.value = true;
}

function selectCatalogItem(index: number, row: Row | null) {
    const item = orderForm.items[index];
    item.label = row?.product?.name ?? '';
    item.purchase_unit = row?.purchase_unit ?? '';
    const price = row?.prices?.find((candidate: Row) => !candidate.valid_until) ?? row?.prices?.[0];
    item.unit_price = price?.price ?? '';
}

async function submit() {
    if (!props.online || busy.value) return;
    busy.value = true;
    fieldErrors.value = {};
    try {
        if (modal.kind === 'supplier') {
            const body = { ...supplierForm };
            if (modal.editing) await api.patch(`/suppliers/${supplierForm.id}`, body);
            else await api.post('/suppliers', body);
            emit('notice', modal.editing ? 'Proveedor actualizado.' : 'Proveedor creado.');
        } else if (modal.kind === 'catalog') {
            const body = { ...catalogForm };
            if (modal.editing) await api.patch(`/supplier-products/${catalogForm.id}`, body);
            else await api.post('/supplier-products', body);
            emit('notice', modal.editing ? 'Catálogo e historial de precio actualizados.' : 'Producto vinculado al proveedor.');
        } else if (modal.kind === 'order') {
            const body = {
                supplier_id: orderForm.supplier_id, ordered_at: orderForm.ordered_at,
                expected_at: orderForm.expected_at || null, currency: orderForm.currency,
                payment_terms: orderForm.payment_terms || null, notes: orderForm.notes || null,
                items: orderForm.items.map(item => ({
                    supplier_product_id: item.supplier_product_id, quantity: item.quantity,
                    ...(item.unit_price ? { unit_price: item.unit_price } : {}),
                })),
            };
            if (modal.editing) await api.put(`/purchase-orders/${orderForm.id}`, body);
            else await api.post('/purchase-orders', body, orderKey);
            emit('notice', modal.editing ? 'Borrador actualizado.' : 'Orden de compra creada como borrador.');
        } else if (modal.kind === 'receipt') {
            const body = {
                received_at: new Date(receiptForm.received_at).toISOString(),
                notes: receiptForm.notes || null,
                items: receiptForm.items.map(item => ({
                    purchase_order_item_id: item.purchase_order_item_id,
                    location_id: item.location_id,
                    received_quantity: item.received_quantity,
                    accepted_quantity: item.accepted_quantity,
                    rejected_quantity: item.rejected_quantity,
                    discrepancy_type: item.discrepancy_type || null,
                    discrepancy_reason: item.discrepancy_reason || null,
                    lot_code: item.lot_code,
                    manufactured_at: item.manufactured_at ? new Date(item.manufactured_at).toISOString() : null,
                    expires_at: item.expires_at || null,
                    actual_unit_cost: item.actual_unit_cost || null,
                })),
            };
            await api.post(
                `/purchase-orders/${receiptForm.order_id}/receipts`,
                body,
                receiptKey,
            );
            emit('notice', 'Recepción registrada; stock, lote y estado de la orden fueron actualizados.');
        }
        modal.open = false;
        formDirty.value = false;
        await table.value?.refresh();
    } catch (error) {
        if (error instanceof HttpError) fieldErrors.value = error.errors;
        emit('error', 'No pudimos completar la operación', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

function requestTransition(row: Row, status: 'approved' | 'sent' | 'cancelled') {
    const transitionKey = idempotencyKey(`purchase-order-${status}`);
    const copy = {
        approved: {
            title: 'Aprobar orden', description: 'La aprobación congela precios, unidades y factores.',
            label: 'Aprobar orden', tone: 'primary' as const,
        },
        sent: {
            title: 'Marcar como enviada', description: 'Confirmá que la orden aprobada fue enviada al proveedor.',
            label: 'Marcar enviada', tone: 'primary' as const,
        },
        cancelled: {
            title: 'Cancelar orden', description: 'La orden quedará cerrada y no admitirá nuevas recepciones.',
            label: 'Cancelar orden', tone: 'danger' as const,
        },
    }[status];
    Object.assign(confirmation, {
        open: true, ...copy, entity: `${row.number} · ${row.supplier?.trade_name ?? 'Proveedor'}`,
        execute: async () => {
            await api.post(
                `/purchase-orders/${row.id}/transitions`,
                { status },
                transitionKey,
            );
            emit('notice', `Orden ${statusLabel(status).toLowerCase()}.`);
        },
    });
}

function requestSupplierToggle(row: Row) {
    const active = !row.active;
    Object.assign(confirmation, {
        open: true,
        title: active ? 'Reactivar proveedor' : 'Desactivar proveedor',
        description: active
            ? 'El proveedor volverá a estar disponible para nuevas órdenes.'
            : 'No se podrá usar en nuevas órdenes; las órdenes abiertas impiden desactivarlo.',
        entity: row.trade_name,
        label: active ? 'Reactivar' : 'Desactivar',
        tone: active ? 'primary' : 'danger',
        execute: async () => {
            await api.post(`/suppliers/${row.id}/active`, { active });
            emit('notice', active ? 'Proveedor reactivado.' : 'Proveedor desactivado.');
        },
    });
}

async function executeConfirmation() {
    if (!confirmation.execute || busy.value) return;
    busy.value = true;
    try {
        await confirmation.execute();
        confirmation.open = false;
        await table.value?.refresh();
    } catch (error) {
        emit('error', 'No pudimos completar la acción', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

function rowActions(row: Row): DataTableRowAction[] {
    const actions: DataTableRowAction[] = [{ key: 'detail', label: 'Ver detalle' }];
    if (tab.value === 'suppliers' && canManage.value) {
        actions.push({ key: 'edit', label: 'Editar' });
        actions.push({ key: 'toggle', label: row.active ? 'Desactivar' : 'Reactivar', tone: row.active ? 'danger' : 'default' });
    } else if (tab.value === 'catalog' && canManage.value) {
        actions.push({ key: 'edit', label: 'Editar y actualizar precio' });
    } else if (tab.value === 'orders') {
        if (canManage.value && row.status === 'draft') actions.push({ key: 'edit', label: 'Editar borrador' }, { key: 'approved', label: 'Aprobar' });
        if (canManage.value && row.status === 'approved') actions.push({ key: 'sent', label: 'Marcar enviada' });
        if (canReceive.value && ['sent', 'partially_received'].includes(row.status)) actions.push({ key: 'receipt', label: 'Registrar recepción' });
        if (canManage.value && !['received', 'cancelled'].includes(row.status)) actions.push({ key: 'cancelled', label: 'Cancelar', tone: 'danger' });
    }
    return actions;
}

async function handleAction(row: Row, action: string) {
    if (action === 'detail') await openDetail(row);
    else if (action === 'edit') await openEdit(row);
    else if (action === 'receipt') await openReceipt(row);
    else if (action === 'toggle') requestSupplierToggle(row);
    else requestTransition(row, action as 'approved' | 'sent' | 'cancelled');
}

function detailTitle() {
    if (tab.value === 'orders') return detail.value?.number ?? 'Orden de compra';
    if (tab.value === 'suppliers') return detail.value?.trade_name ?? 'Proveedor';
    if (tab.value === 'catalog') return detail.value?.product?.name ?? 'Producto de proveedor';
    return detail.value?.number ?? 'Recepción';
}
</script>

<template>
    <section class="page procurement-page">
        <nav class="procurement-tabs" aria-label="Secciones de compras">
            <button
                v-for="item in tabs"
                :key="item.id"
                type="button"
                :class="{ active: tab === item.id }"
                :aria-current="tab === item.id ? 'page' : undefined"
                @click="switchTab(item.id)"
            >{{ item.label }}</button>
        </nav>

        <header class="list-page-header">
            <div>
                <p class="section-kicker">{{ content.kicker }}</p>
                <h2>{{ content.title }}</h2>
                <p>{{ content.description }}</p>
            </div>
            <button v-if="content.action && canManage" class="primary" type="button" :disabled="!online" @click="openCreate">
                {{ content.action }}
            </button>
        </header>

        <ServerDataTable
            :key="tab"
            ref="table"
            :endpoint="endpoint"
            :columns="columns"
            :filters="filters"
            :initial-sort="tab === 'orders' ? 'created_at' : 'id'"
            :persist-in-url="false"
            :label="content.title"
            :empty-label="content.empty"
            :empty-action="content.action && canManage ? content.action : ''"
            @empty-action="openCreate"
            @error="$emit('error', 'No pudimos cargar compras', $event)"
        >
            <template #cell-status="{ row }"><StatusBadge :status="String(row.status)" /></template>
            <template #cell-active="{ row }"><StatusBadge :status="row.active ? 'active' : 'inactive'" /></template>
            <template #actions="{ row }">
                <DataTableRowActions :actions="rowActions(row)" @action="handleAction(row, $event)" />
            </template>
        </ServerDataTable>
    </section>

    <BaseModal
        :open="modal.open"
        :title="modalTitle"
        :description="modalDescription"
        :size="modal.kind === 'receipt' || modal.kind === 'order' || modal.kind === 'detail' ? 'large' : 'wide'"
        :busy="busy"
        :dirty="formDirty"
        @close="modal.open = false"
        @discard="formDirty = false"
    >
        <form v-if="modal.kind !== 'detail'" :id="formId" class="form-grid modal-form" @input="formDirty = true" @submit.prevent="submit">
            <template v-if="modal.kind === 'supplier'">
                <label>Nombre comercial<input v-model="supplierForm.trade_name" required maxlength="255" autofocus></label>
                <label>Razón social<input v-model="supplierForm.legal_name" maxlength="255"></label>
                <label>CUIT<input v-model="supplierForm.tax_id" maxlength="32"></label>
                <label>Contacto<input v-model="supplierForm.contact_name" maxlength="255"></label>
                <label>Email<input v-model="supplierForm.email" type="email" maxlength="255"></label>
                <label>Teléfono<input v-model="supplierForm.phone" maxlength="64"></label>
                <label>Plazo habitual (días)<input v-model.number="supplierForm.lead_time_days" type="number" min="0" max="365" required></label>
                <label>Condiciones de pago<input v-model="supplierForm.payment_terms" maxlength="255"></label>
                <label class="wide">Domicilio<textarea v-model="supplierForm.address" rows="2" maxlength="2000"></textarea></label>
                <label class="wide">Notas<textarea v-model="supplierForm.notes" rows="3" maxlength="5000"></textarea></label>
                <label class="checkbox-field wide"><input v-model="supplierForm.active" type="checkbox"> Proveedor activo</label>
            </template>

            <template v-else-if="modal.kind === 'catalog'">
                <label>Proveedor
                    <RemoteSelect
                        v-model="catalogForm.supplier_id"
                        endpoint="/suppliers"
                        sort="trade_name"
                        label-key="trade_name"
                        :filters="{ active: '1' }"
                        :selected-label="selectedSupplierLabel"
                        required
                        placeholder="Buscar proveedor activo"
                    />
                </label>
                <label>Producto
                    <RemoteSelect
                        v-model="catalogForm.product_id"
                        endpoint="/products"
                        sort="name"
                        label-key="name"
                        secondary-key="unit"
                        :filters="{ active: '1' }"
                        :selected-label="selectedProductLabel"
                        required
                        placeholder="Buscar producto activo"
                    />
                </label>
                <label>Código del proveedor<input v-model="catalogForm.supplier_code" maxlength="255"></label>
                <label>Unidad de compra<select v-model="catalogForm.purchase_unit"><option v-for="unit in ['unit', 'kg', 'g', 'l', 'ml']" :key="unit" :value="unit">{{ unitLabel(unit) }}</option></select></label>
                <label>Factor a unidad del producto<input v-model="catalogForm.conversion_factor" required type="number" min="0.000001" step="0.000001"></label>
                <label>Compra mínima<input v-model="catalogForm.minimum_quantity" required type="number" min="0" step="0.001"></label>
                <label>Plazo (días)<input v-model.number="catalogForm.lead_time_days" required type="number" min="0" max="365"></label>
                <label>Precio<input v-model="catalogForm.price" required type="number" min="0" step="0.01"></label>
                <label>Moneda<select v-model="catalogForm.currency"><option value="ARS">ARS</option><option value="USD">USD</option></select></label>
                <label>Vigente desde<input v-model="catalogForm.price_valid_from" required type="date"></label>
                <label class="checkbox-field"><input v-model="catalogForm.preferred" type="checkbox"> Proveedor preferido</label>
                <label class="checkbox-field"><input v-model="catalogForm.active" type="checkbox"> Relación activa</label>
            </template>

            <template v-else-if="modal.kind === 'order'">
                <label>Proveedor
                    <RemoteSelect
                        v-model="orderForm.supplier_id"
                        endpoint="/suppliers"
                        sort="trade_name"
                        label-key="trade_name"
                        secondary-key="payment_terms"
                        :filters="{ active: '1' }"
                        :selected-label="selectedSupplierLabel"
                        required
                        placeholder="Buscar proveedor activo"
                        @selected="selectedSupplierLabel = $event ? String($event.trade_name) : ''"
                    />
                </label>
                <label>Fecha de emisión<input v-model="orderForm.ordered_at" required type="date"></label>
                <label>Entrega esperada<input v-model="orderForm.expected_at" type="date" :min="orderForm.ordered_at"></label>
                <label>Moneda<select v-model="orderForm.currency"><option value="ARS">ARS</option><option value="USD">USD</option></select></label>
                <label>Condiciones de pago<input v-model="orderForm.payment_terms" maxlength="255"></label>
                <label class="wide">Notas<textarea v-model="orderForm.notes" rows="2" maxlength="5000"></textarea></label>
                <fieldset class="line-items wide">
                    <legend>Productos solicitados</legend>
                    <article v-for="(item, index) in orderForm.items" :key="index" class="line-item-card">
                        <label>Producto del proveedor
                            <RemoteSelect
                                v-model="item.supplier_product_id"
                                endpoint="/supplier-products"
                                sort="id"
                                label-key="product.name"
                                secondary-key="purchase_unit"
                                :filters="{ supplier_id: String(orderForm.supplier_id ?? ''), active: '1' }"
                                :disabled="!orderForm.supplier_id"
                                :selected-label="item.label"
                                required
                                placeholder="Elegí primero un proveedor"
                                @selected="selectCatalogItem(index, $event)"
                            />
                        </label>
                        <label>Cantidad <span v-if="item.purchase_unit">({{ unitLabel(item.purchase_unit) }})</span><input v-model="item.quantity" required type="number" min="0.001" step="0.001"></label>
                        <label>Precio unitario<input v-model="item.unit_price" type="number" min="0" step="0.01" placeholder="Usar vigente"></label>
                        <button class="text-button danger-text" type="button" :disabled="orderForm.items.length === 1" @click="removeOrderItem(index)">Quitar</button>
                    </article>
                    <button class="secondary" type="button" @click="orderForm.items.push(newOrderItem())">Agregar producto</button>
                </fieldset>
            </template>

            <template v-else-if="modal.kind === 'receipt'">
                <div class="form-context wide">
                    <strong>{{ modal.row?.number }} · {{ modal.row?.supplier?.trade_name }}</strong>
                    <span>Registrá sólo los renglones entregados en esta recepción.</span>
                </div>
                <label>Fecha y hora<input v-model="receiptForm.received_at" required type="datetime-local"></label>
                <label>Notas<input v-model="receiptForm.notes" maxlength="5000"></label>
                <fieldset class="line-items wide">
                    <legend>Control de mercadería</legend>
                    <article v-for="(item, index) in receiptForm.items" :key="item.purchase_order_item_id" class="receipt-card">
                        <header><strong>{{ item.product_name }}</strong><span>Pendiente: {{ item.pending }} {{ unitLabel(item.purchase_unit) }}</span></header>
                        <label>Ubicación
                            <RemoteSelect v-model="item.location_id" endpoint="/locations" sort="name" label-key="name" :filters="{ active: '1' }" required placeholder="Buscar ubicación activa" />
                        </label>
                        <label>Recibido<input v-model="item.received_quantity" required type="number" min="0.001" step="0.001"></label>
                        <label>Aceptado<input v-model="item.accepted_quantity" required type="number" min="0" :max="item.pending" step="0.001"></label>
                        <label>Rechazado<input v-model="item.rejected_quantity" required type="number" min="0" step="0.001"></label>
                        <label>Motivo de diferencia<select v-model="item.discrepancy_type"><option value="">Sin diferencia</option><option value="shortage">Faltante</option><option value="excess">Exceso</option><option value="damaged">Dañado</option><option value="quality">Calidad</option><option value="wrong_product">Producto incorrecto</option><option value="other">Otro</option></select></label>
                        <label>Detalle<input v-model="item.discrepancy_reason" maxlength="255"></label>
                        <label>Código de lote<input v-model="item.lot_code" required maxlength="255"></label>
                        <label>Elaboración<input v-model="item.manufactured_at" type="datetime-local"></label>
                        <label>Vencimiento<input v-model="item.expires_at" type="date"></label>
                        <label>Costo real unitario<input v-model="item.actual_unit_cost" type="number" min="0" step="0.01"></label>
                        <button class="text-button danger-text" type="button" :disabled="receiptForm.items.length === 1" @click="removeReceiptItem(index)">No incluir en esta recepción</button>
                    </article>
                </fieldset>
            </template>

            <div v-if="Object.keys(fieldErrors).length" class="form-error-summary wide" role="alert">
                <strong>Revisá los datos ingresados</strong>
                <ul><li v-for="message in Object.values(fieldErrors).flat()" :key="message">{{ message }}</li></ul>
            </div>
        </form>

        <div v-else class="procurement-detail">
            <p v-if="busy">Cargando detalle…</p>
            <template v-else-if="detail">
                <dl class="detail-grid">
                    <template v-if="tab === 'orders'">
                        <div><dt>Estado</dt><dd><StatusBadge :status="detail.status" /></dd></div>
                        <div><dt>Proveedor</dt><dd>{{ detail.supplier?.trade_name }}</dd></div>
                        <div><dt>Emisión</dt><dd>{{ formatCalendarDate(detail.ordered_at) }}</dd></div>
                        <div><dt>Entrega esperada</dt><dd>{{ formatCalendarDate(detail.expected_at) }}</dd></div>
                        <div><dt>Total</dt><dd>{{ money(detail.total, detail.currency) }}</dd></div>
                        <div><dt>Condiciones</dt><dd>{{ detail.payment_terms || 'Sin definir' }}</dd></div>
                    </template>
                    <template v-else-if="tab === 'suppliers'">
                        <div><dt>Estado</dt><dd>{{ detail.active ? 'Activo' : 'Inactivo' }}</dd></div>
                        <div><dt>CUIT</dt><dd>{{ detail.tax_id || 'Sin informar' }}</dd></div>
                        <div><dt>Contacto</dt><dd>{{ detail.contact_name || 'Sin informar' }}</dd></div>
                        <div><dt>Plazo</dt><dd>{{ detail.lead_time_days }} días</dd></div>
                        <div><dt>Email</dt><dd>{{ detail.email || 'Sin informar' }}</dd></div>
                        <div><dt>Teléfono</dt><dd>{{ detail.phone || 'Sin informar' }}</dd></div>
                    </template>
                    <template v-else-if="tab === 'catalog'">
                        <div><dt>Proveedor</dt><dd>{{ detail.supplier?.trade_name }}</dd></div>
                        <div><dt>Producto</dt><dd>{{ detail.product?.name }}</dd></div>
                        <div><dt>Conversión</dt><dd>1 {{ unitLabel(detail.purchase_unit) }} = {{ detail.conversion_factor }} {{ unitLabel(detail.product?.unit) }}</dd></div>
                        <div><dt>Precio actual</dt><dd>{{ currentPrice(detail) }}</dd></div>
                    </template>
                    <template v-else>
                        <div><dt>Orden</dt><dd>{{ detail.order?.number }}</dd></div>
                        <div><dt>Proveedor</dt><dd>{{ detail.order?.supplier?.trade_name }}</dd></div>
                        <div><dt>Fecha</dt><dd>{{ formatDateTime(detail.received_at) }}</dd></div>
                        <div><dt>Renglones</dt><dd>{{ detail.items?.length }}</dd></div>
                    </template>
                </dl>

                <section v-if="detail.items?.length" class="detail-lines">
                    <h3>{{ tab === 'receipts' ? 'Mercadería recibida' : 'Productos' }}</h3>
                    <article v-for="item in detail.items" :key="item.id">
                        <strong>{{ item.product_name || item.product?.name || `Producto #${item.product_id}` }}</strong>
                        <span v-if="tab === 'orders'">{{ item.received_quantity }} / {{ item.quantity }} {{ unitLabel(item.purchase_unit) }} · {{ money(item.total, detail.currency) }}</span>
                        <span v-else>{{ item.accepted_quantity }} aceptado · {{ item.rejected_quantity }} rechazado · lote {{ item.lot_code }}</span>
                    </article>
                </section>
                <section v-if="tab === 'catalog' && detail.prices?.length" class="detail-lines">
                    <h3>Historial de precios</h3>
                    <article v-for="price in detail.prices" :key="price.id">
                        <strong>{{ money(price.price, price.currency) }}</strong>
                        <span>Desde {{ formatCalendarDate(price.valid_from) }} hasta {{ formatCalendarDate(price.valid_until) }}</span>
                    </article>
                </section>
            </template>
        </div>

        <template v-if="modal.kind !== 'detail'" #footer>
            <button class="secondary" type="button" :disabled="busy" @click="modal.open = false">Cancelar</button>
            <button class="primary" type="submit" :form="formId" :disabled="busy || !online">
                {{ busy ? 'Guardando…' : modal.kind === 'receipt' ? 'Registrar recepción' : 'Guardar' }}
            </button>
        </template>
        <template v-else #footer>
            <button class="primary" type="button" @click="modal.open = false">Cerrar</button>
        </template>
    </BaseModal>

    <ConfirmActionModal
        :open="confirmation.open"
        :title="confirmation.title"
        :description="confirmation.description"
        :entity="confirmation.entity"
        :confirm-label="confirmation.label"
        :tone="confirmation.tone"
        :busy="busy"
        @close="confirmation.open = false"
        @confirm="executeConfirmation"
    />
</template>

<style scoped>
.procurement-tabs {
    display: flex;
    gap: .25rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--line);
    overflow-x: auto;
}
.procurement-tabs button {
    min-height: 44px;
    border: 0;
    border-bottom: 3px solid transparent;
    color: var(--ink-muted);
    background: transparent;
    padding: .6rem .9rem;
    white-space: nowrap;
}
.procurement-tabs button.active {
    border-bottom-color: var(--terracotta);
    color: var(--ink);
    font-weight: 800;
}
.line-items {
    display: grid;
    gap: 1rem;
    min-width: 0;
    margin: .5rem 0 0;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    padding: 1rem;
}
.line-items legend { color: var(--ink); font-family: var(--font-display); font-size: 1.15rem; }
.line-item-card, .receipt-card {
    display: grid;
    grid-template-columns: minmax(220px, 1.7fr) repeat(2, minmax(120px, .7fr)) auto;
    gap: .75rem;
    align-items: end;
    border-bottom: 1px solid var(--line);
    padding-bottom: 1rem;
}
.receipt-card { grid-template-columns: repeat(3, minmax(150px, 1fr)); }
.receipt-card header {
    display: flex;
    grid-column: 1 / -1;
    justify-content: space-between;
    gap: 1rem;
}
.receipt-card header span { color: var(--ink-muted); font-size: .8rem; }
.danger-text { color: var(--danger); }
.checkbox-field { display: flex; align-items: center; gap: .6rem; }
.checkbox-field input { width: 18px; min-height: 18px; margin: 0; }
.procurement-detail { display: grid; gap: 1.5rem; }
.detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin: 0; }
.detail-grid div { border-bottom: 1px solid var(--line); padding-bottom: .75rem; }
.detail-grid dt { color: var(--ink-muted); font-size: .72rem; font-weight: 800; text-transform: uppercase; }
.detail-grid dd { margin: .3rem 0 0; }
.detail-lines { display: grid; gap: .5rem; }
.detail-lines h3 { margin: 0 0 .25rem; font-family: var(--font-display); }
.detail-lines article {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    padding: .75rem;
}
.detail-lines span { color: var(--ink-muted); text-align: right; }
@media (max-width: 840px) {
    .line-item-card, .receipt-card { grid-template-columns: 1fr 1fr; }
    .line-item-card > :first-child, .receipt-card header { grid-column: 1 / -1; }
}
@media (max-width: 600px) {
    .line-item-card, .receipt-card, .detail-grid { grid-template-columns: 1fr; }
    .receipt-card header, .line-item-card > :first-child { grid-column: auto; }
    .receipt-card header, .detail-lines article { flex-direction: column; }
    .detail-lines span { text-align: left; }
}
</style>
