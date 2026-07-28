<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api, errorMessages, HttpError, idempotencyKey } from '../api';
import type { DataTableColumn, DataTableFilter, DataTableRowAction, OrderStatus, Payment, View } from '../types';
import DataTableRowActions from './data-table/DataTableRowActions.vue';
import ServerDataTable from './data-table/ServerDataTable.vue';
import BaseModal from './ui/BaseModal.vue';
import ConfirmActionModal from './ui/ConfirmActionModal.vue';
import RemoteSelect from './ui/RemoteSelect.vue';
import StatusBadge from './ui/StatusBadge.vue';

type ModuleView = Exclude<View, 'dashboard'>;
type ModalMode =
    | 'create'
    | 'edit'
    | 'detail'
    | 'lot-adjust'
    | 'order-transition'
    | 'order-delivery'
    | 'payment-create'
    | 'production-complete'
    | 'production-requirements'
    | 'production-traceability';

const props = defineProps<{
    view: ModuleView;
    online: boolean;
    branchName: string;
    role: string;
}>();

const emit = defineEmits<{ notice: [message: string]; error: [title: string, messages: string[]] }>();

const operationalTable = ref<InstanceType<typeof ServerDataTable> | null>(null);
const busy = ref(false);
const formDirty = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const detail = ref<Record<string, any> | null>(null);
const detailLoading = ref(false);
const allowedTransitions = ref<OrderStatus[]>([]);
const highlightedId = ref<string | number | null>(null);
const selectedOrderLabel = ref('');
const selectedProductLabel = ref('');
const selectedLocationLabel = ref('');
const selectedRecipeLabel = ref('');

const modal = reactive<{
    open: boolean;
    mode: ModalMode;
    row: Record<string, any> | null;
}>({ open: false, mode: 'detail', row: null });

const confirmation = reactive<{
    open: boolean;
    title: string;
    description: string;
    entity: string;
    label: string;
    tone: 'primary' | 'danger';
    details: string[];
    execute: null | (() => Promise<void>);
}>({
    open: false,
    title: '',
    description: '',
    entity: '',
    label: 'Confirmar',
    tone: 'primary',
    details: [],
    execute: null,
});

const customerForm = reactive({
    id: 0, name: '', tax_id: '', tax_condition: '', email: '', phone: '',
    credit_limit: '0.00', active: true,
});
const productForm = reactive({
    id: 0, name: '', type: 'finished_product', unit: 'unit',
    minimum_stock: '0.000', price: '0.00', active: true,
});
const locationForm = reactive({ name: '', active: true });
const lotForm = reactive({
    lot_id: null as number | null, product_id: null as number | null,
    location_id: null as number | null, code: '', unit: 'unit',
    quantity: '0.000', expires_at: '', reason: 'Ingreso de mercadería',
    type: 'receipt',
});
const orderForm = reactive({
    customer_id: null as number | null, customer_name: '', required_at: '',
    product_id: null as number | null, quantity: '1.000', unit_price: '0.00',
});
const transitionForm = reactive({ status: 'confirmed' as OrderStatus });
const deliveryForm = reactive({ method: 'pickup', notes: '' });
const paymentForm = reactive({
    order_id: null as number | null, amount: '0.00',
    method: 'transfer' as Payment['method'], external_reference: '',
});
const recipeForm = reactive({
    product_id: null as number | null, expected_yield: '1.000',
    yield_unit: 'unit', theoretical_waste_percent: '0.00', status: 'draft',
    items: [{ ingredient_product_id: null as number | null, quantity: '1.000', unit: 'unit' }],
});
const productionForm = reactive({
    order_id: null as number | null, recipe_id: null as number | null,
    planned_quantity: '1.000', unit: 'unit', actual_yield: '1.000',
    waste_quantity: '0.000', destination_location_id: null as number | null,
    manufactured_at: '', expires_at: '', observations: '',
});

let orderKey = idempotencyKey('order');
let paymentKey = idempotencyKey('payment');
let lotKey = idempotencyKey('lot');
let productionKey = idempotencyKey('production');
let productionActionKey = idempotencyKey('production-action');
let transitionKey = idempotencyKey('transition');
let deliveryKey = idempotencyKey('delivery');

const pageContent: Record<ModuleView, { title: string; description: string; action?: string; empty: string }> = {
    customers: { title: 'Clientes', description: 'Contacto, condición comercial y estado de cada cliente.', action: 'Nuevo cliente', empty: 'cliente' },
    products: { title: 'Productos', description: 'Catálogo, unidades, precios y niveles mínimos.', action: 'Nuevo producto', empty: 'producto' },
    locations: { title: 'Ubicaciones', description: 'Depósitos y sectores habilitados en la sucursal.', action: 'Nueva ubicación', empty: 'ubicación' },
    lots: { title: 'Inventario por lote', description: 'Disponibilidad, reservas y vencimientos con criterio FEFO.', action: 'Registrar recepción', empty: 'lote' },
    orders: { title: 'Pedidos', description: 'Seguimiento comercial desde el alta hasta la entrega.', action: 'Nuevo pedido', empty: 'pedido' },
    recipes: { title: 'Recetas', description: 'Versiones, rendimiento e ingredientes de producción.', action: 'Nueva receta', empty: 'receta' },
    production: { title: 'Producción', description: 'Órdenes, requerimientos, rendimiento, merma y trazabilidad.', action: 'Nueva orden', empty: 'orden' },
    payments: { title: 'Cobranzas', description: 'Pagos registrados y saldo de los pedidos.', action: 'Registrar pago', empty: 'pago' },
    alerts: { title: 'Alertas', description: 'Situaciones operativas que requieren revisión o acción.', empty: 'alerta' },
};

const endpoints: Record<ModuleView, string> = {
    customers: '/customers',
    products: '/products',
    locations: '/locations',
    lots: '/lots',
    orders: '/orders',
    recipes: '/recipes',
    production: '/production-orders',
    payments: '/payments',
    alerts: '/alerts',
};

const columns: Record<ModuleView, DataTableColumn[]> = {
    customers: [
        { key: 'name', label: 'Cliente', sortable: true },
        { key: 'tax_id', label: 'Documento', priority: 'secondary' },
        { key: 'email', label: 'Contacto', sortable: true },
        { key: 'phone', label: 'Teléfono', priority: 'secondary' },
        { key: 'active', label: 'Estado' },
        { key: 'updated_at', label: 'Actualización', priority: 'secondary', render: (row) => formatDate(row.updated_at) },
    ],
    products: [
        { key: 'name', label: 'Producto', sortable: true },
        { key: 'type', label: 'Tipo', sortable: true, render: (row) => productType(String(row.type)) },
        { key: 'unit', label: 'Unidad', render: (row) => unitLabel(String(row.unit)) },
        { key: 'price', label: 'Precio', sortable: true, align: 'end', render: (row) => money(String(row.price ?? 0)) },
        { key: 'minimum_stock', label: 'Stock mínimo', align: 'end', priority: 'secondary' },
        { key: 'active', label: 'Estado' },
    ],
    locations: [
        { key: 'name', label: 'Ubicación', sortable: true },
        { key: 'branch_id', label: 'Sucursal', render: () => props.branchName },
        { key: 'active', label: 'Estado' },
        { key: 'created_at', label: 'Creada', sortable: true, priority: 'secondary', render: (row) => formatDate(row.created_at) },
    ],
    lots: [
        { key: 'code', label: 'Lote', sortable: true },
        { key: 'product.name', label: 'Producto' },
        { key: 'location.name', label: 'Ubicación', priority: 'secondary' },
        { key: 'quantity', label: 'Disponible', sortable: true, align: 'end' },
        { key: 'reserved_quantity', label: 'Reservado', align: 'end', priority: 'secondary' },
        { key: 'unit', label: 'Unidad', render: (row) => unitLabel(String(row.unit)) },
        { key: 'expires_at', label: 'Vencimiento', sortable: true, render: (row) => formatDate(row.expires_at) },
        { key: 'status', label: 'Estado', sortable: true },
    ],
    orders: [
        { key: 'id', label: 'Pedido', sortable: true, render: (row) => `#${row.id}` },
        { key: 'customer_name', label: 'Cliente', sortable: true },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'total', label: 'Total', sortable: true, align: 'end', render: (row) => money(String(row.total ?? 0)) },
        { key: 'paid_total', label: 'Saldo', align: 'end', render: (row) => money(decimalSubtract(row.total, row.paid_total)) },
        { key: 'delivery_method', label: 'Entrega', priority: 'secondary', render: (row) => deliveryLabel(String(row.delivery_method ?? '')) },
        { key: 'required_at', label: 'Fecha requerida', sortable: true, render: (row) => formatDate(row.required_at) },
    ],
    recipes: [
        { key: 'product.name', label: 'Producto' },
        { key: 'version', label: 'Versión', sortable: true, align: 'end' },
        { key: 'yield_quantity', label: 'Rendimiento', sortable: true, align: 'end' },
        { key: 'yield_unit', label: 'Unidad', render: (row) => unitLabel(String(row.yield_unit)) },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'created_at', label: 'Fecha', sortable: true, priority: 'secondary', render: (row) => formatDate(row.created_at) },
    ],
    production: [
        { key: 'id', label: 'Orden', sortable: true, render: (row) => `#${row.id}` },
        { key: 'order_id', label: 'Pedido', render: (row) => `#${row.order_id}` },
        { key: 'recipe.product.name', label: 'Producto' },
        { key: 'planned_quantity', label: 'Cantidad', sortable: true, align: 'end' },
        { key: 'actual_yield', label: 'Rendimiento', align: 'end', priority: 'secondary' },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'started_at', label: 'Inicio', sortable: true, priority: 'secondary', render: (row) => formatDate(row.started_at) },
    ],
    payments: [
        { key: 'id', label: 'Pago', sortable: true, render: (row) => `#${row.id}` },
        { key: 'order_id', label: 'Pedido', sortable: true, render: (row) => `#${row.order_id}` },
        { key: 'order.customer_name', label: 'Cliente' },
        { key: 'method', label: 'Medio', sortable: true, render: (row) => paymentMethod(String(row.method)) },
        { key: 'amount', label: 'Importe', sortable: true, align: 'end', render: (row) => money(String(row.amount ?? 0)) },
        { key: 'created_at', label: 'Fecha', sortable: true, priority: 'secondary', render: (row) => formatDate(row.created_at) },
    ],
    alerts: [
        { key: 'severity', label: 'Severidad', sortable: true },
        { key: 'event', label: 'Tipo', sortable: true },
        { key: 'action', label: 'Acción esperada' },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'last_seen_at', label: 'Abierta', sortable: true, render: (row) => formatDate(row.last_seen_at) },
    ],
};

const filters: Record<ModuleView, DataTableFilter[]> = {
    customers: [{ key: 'active', label: 'Estado', options: activeOptions() }],
    products: [
        { key: 'active', label: 'Estado', options: activeOptions() },
        { key: 'type', label: 'Tipo', options: ['raw_material', 'semi_finished', 'finished_product', 'packaging'].map((value) => ({ label: productType(value), value })) },
    ],
    locations: [{ key: 'active', label: 'Estado', options: activeOptions() }],
    lots: [{ key: 'status', label: 'Estado', options: ['available', 'depleted', 'blocked', 'expired'].map((value) => ({ label: statusLabel(value), value })) }],
    orders: [{ key: 'status', label: 'Estado', options: ['draft', 'confirmed', 'in_production', 'ready', 'delivered', 'cancelled'].map((value) => ({ label: statusLabel(value), value })) }],
    recipes: [{ key: 'status', label: 'Estado', options: ['draft', 'approved', 'inactive'].map((value) => ({ label: statusLabel(value), value })) }],
    production: [{ key: 'status', label: 'Estado', options: ['planned', 'in_progress', 'completed'].map((value) => ({ label: statusLabel(value), value })) }],
    payments: [{ key: 'method', label: 'Medio', options: ['cash', 'transfer', 'mercadopago', 'card'].map((value) => ({ label: paymentMethod(value), value })) }],
    alerts: [
        { key: 'severity', label: 'Severidad', options: ['low', 'medium', 'high', 'critical'].map((value) => ({ label: statusLabel(value), value })) },
        { key: 'status', label: 'Estado', options: ['open', 'acknowledged', 'resolved'].map((value) => ({ label: statusLabel(value), value })) },
    ],
};

const content = computed(() => pageContent[props.view]);
const endpoint = computed(() => endpoints[props.view]);
const tableColumns = computed(() => columns[props.view]);
const tableFilters = computed(() => filters[props.view]);
const modalTitle = computed(() => {
    const entity = content.value.empty;
    if (modal.mode === 'create') return content.value.action ?? `Nuevo ${entity}`;
    if (modal.mode === 'edit') return `Editar ${entity}`;
    if (modal.mode === 'lot-adjust') return 'Ajustar existencia';
    if (modal.mode === 'order-transition') return 'Cambiar estado del pedido';
    if (modal.mode === 'order-delivery') return 'Preparar entrega';
    if (modal.mode === 'payment-create') return 'Registrar pago';
    if (modal.mode === 'production-complete') return 'Completar producción';
    if (modal.mode === 'production-requirements') return 'Requerimientos de producción';
    if (modal.mode === 'production-traceability') return 'Trazabilidad de producción';
    return `Detalle de ${entity}`;
});
const modalSize = computed(() => ['orders', 'recipes', 'production'].includes(props.view) || ['production-requirements', 'production-traceability'].includes(modal.mode) ? 'large' : 'wide');
const isFormModal = computed(() => !['detail', 'production-requirements', 'production-traceability'].includes(modal.mode));
const canCreate = computed(() => ({
    customers: can('owner', 'admin', 'sales'),
    products: can('owner', 'admin', 'inventory'),
    locations: can('owner', 'admin', 'inventory'),
    lots: can('owner', 'admin', 'inventory'),
    orders: can('owner', 'admin', 'sales'),
    recipes: can('owner', 'admin', 'production'),
    production: can('owner', 'admin', 'production'),
    payments: can('owner', 'admin', 'sales', 'finance'),
    alerts: false,
})[props.view]);

function rowActions(row: Record<string, any>): DataTableRowAction[] {
    const actions: DataTableRowAction[] = [{ key: 'view', label: 'Ver detalle' }];
    if (
        (props.view === 'customers' && can('owner', 'admin', 'sales'))
        || (props.view === 'products' && can('owner', 'admin', 'inventory'))
    ) {
        actions.push({ key: 'edit', label: 'Editar' });
        actions.push({ key: 'toggle', label: row.active ? 'Desactivar' : 'Activar', tone: row.active ? 'danger' : 'default' });
    }
    if (props.view === 'lots' && can('owner', 'admin', 'inventory')) actions.push({ key: 'adjust', label: 'Registrar ajuste' });
    if (props.view === 'orders') {
        if (can('owner', 'admin', 'sales', 'production')) actions.push({ key: 'transition', label: 'Cambiar estado', disabled: ['delivered', 'cancelled'].includes(row.status) });
        if (can('owner', 'admin', 'sales', 'finance')) actions.push({ key: 'payment', label: 'Registrar pago', disabled: decimalSubtract(row.total, row.paid_total) === '0.00' });
        if (can('owner', 'admin', 'sales')) actions.push({ key: 'delivery', label: 'Registrar entrega', disabled: row.status !== 'ready' });
    }
    if (props.view === 'recipes' && can('owner', 'admin', 'production')) {
        actions.push({ key: row.status === 'approved' ? 'deactivate' : 'approve', label: row.status === 'approved' ? 'Desactivar' : 'Aprobar', tone: row.status === 'approved' ? 'danger' : 'default' });
    }
    if (props.view === 'production' && can('owner', 'admin', 'production')) {
        actions.push({ key: 'requirements', label: 'Ver requerimientos' });
        actions.push({ key: 'traceability', label: 'Ver trazabilidad' });
        actions.push({ key: 'start', label: 'Iniciar producción', disabled: row.status !== 'planned' });
        actions.push({ key: 'complete', label: 'Completar producción', disabled: row.status !== 'in_progress' });
    }
    if (props.view === 'alerts') {
        if (can('owner', 'admin', 'inventory', 'production', 'finance', 'purchasing')) actions.push({ key: 'acknowledge', label: 'Reconocer', disabled: row.status !== 'open' });
        if (can('owner', 'admin', 'inventory', 'production', 'finance')) actions.push({ key: 'resolve', label: 'Resolver', disabled: row.status === 'resolved' });
    }
    return actions;
}

function resetModalState() {
    fieldErrors.value = {};
    detail.value = null;
    formDirty.value = false;
    selectedOrderLabel.value = '';
    selectedProductLabel.value = '';
    selectedLocationLabel.value = '';
    selectedRecipeLabel.value = '';
}

function openCreate(mode: ModalMode = 'create', row: Record<string, any> | null = null) {
    if (mode === 'create' && !canCreate.value) return;
    resetModalState();
    modal.row = row;
    modal.mode = mode;
    if (props.view === 'customers') Object.assign(customerForm, { id: 0, name: '', tax_id: '', tax_condition: '', email: '', phone: '', credit_limit: '0.00', active: true });
    if (props.view === 'products') Object.assign(productForm, { id: 0, name: '', type: 'finished_product', unit: 'unit', minimum_stock: '0.000', price: '0.00', active: true });
    if (props.view === 'locations') Object.assign(locationForm, { name: '', active: true });
    if (props.view === 'lots') Object.assign(lotForm, { lot_id: null, product_id: null, location_id: null, code: '', unit: 'unit', quantity: '0.000', expires_at: '', reason: 'Ingreso de mercadería', type: 'receipt' });
    if (props.view === 'orders') Object.assign(orderForm, { customer_id: null, customer_name: '', required_at: '', product_id: null, quantity: '1.000', unit_price: '0.00' });
    if (props.view === 'recipes') Object.assign(recipeForm, { product_id: null, expected_yield: '1.000', yield_unit: 'unit', theoretical_waste_percent: '0.00', status: 'draft', items: [{ ingredient_product_id: null, quantity: '1.000', unit: 'unit' }] });
    if (props.view === 'production') Object.assign(productionForm, { order_id: null, recipe_id: null, planned_quantity: '1.000', unit: 'unit', actual_yield: '1.000', waste_quantity: '0.000', destination_location_id: null, manufactured_at: '', expires_at: '', observations: '' });
    if (props.view === 'payments' || mode === 'payment-create') Object.assign(paymentForm, { order_id: row?.id ?? null, amount: row ? decimalSubtract(row.total, row.paid_total) : '0.00', method: 'transfer', external_reference: '' });
    if (row) selectedOrderLabel.value = `Pedido #${row.id} · ${row.customer_name ?? ''}`;
    modal.open = true;
}

function openEdit(row: Record<string, any>) {
    resetModalState();
    modal.row = row;
    modal.mode = 'edit';
    if (props.view === 'customers') Object.assign(customerForm, {
        id: row.id, name: row.name ?? '', tax_id: row.tax_id ?? '', tax_condition: row.tax_condition ?? '',
        email: row.email ?? '', phone: row.phone ?? '', credit_limit: row.credit_limit ?? '0.00', active: Boolean(row.active),
    });
    if (props.view === 'products') Object.assign(productForm, {
        id: row.id, name: row.name ?? '', type: row.type ?? 'finished_product', unit: row.unit ?? 'unit',
        minimum_stock: row.minimum_stock ?? '0.000', price: row.price ?? '0.00', active: Boolean(row.active),
    });
    modal.open = true;
}

async function openDetail(row: Record<string, any>, mode: ModalMode = 'detail') {
    resetModalState();
    modal.row = row;
    modal.mode = mode;
    modal.open = true;
    detailLoading.value = true;
    try {
        if (props.view === 'locations') detail.value = row;
        else if (mode === 'production-requirements') detail.value = await api.get(`/production-orders/${row.id}/requirements`);
        else if (mode === 'production-traceability') detail.value = await api.get(`/production-orders/${row.id}/traceability`);
        else {
            const path = props.view === 'production'
                ? `/production-orders/${row.id}`
                : `${endpoint.value}/${row.id}`;
            detail.value = props.view === 'lots'
                ? await api.getRaw<Record<string, any>>(path)
                : await api.get<Record<string, any>>(path);
        }
    } catch (error) {
        emit('error', 'No pudimos cargar el detalle', errorMessages(error));
    } finally {
        detailLoading.value = false;
    }
}

async function handleRowAction(row: Record<string, any>, action: string) {
    if (action === 'view') return openDetail(row);
    if (action === 'edit') return openEdit(row);
    if (action === 'adjust') {
        resetModalState();
        Object.assign(lotForm, { lot_id: row.id, product_id: null, location_id: null, code: row.code, unit: row.unit, quantity: '0.000', expires_at: row.expires_at ?? '', reason: '', type: 'adjustment' });
        modal.row = row;
        modal.mode = 'lot-adjust';
        modal.open = true;
        return;
    }
    if (action === 'payment') return openCreate('payment-create', row);
    if (action === 'transition') {
        resetModalState();
        modal.row = row;
        modal.mode = 'order-transition';
        modal.open = true;
        allowedTransitions.value = await api.get<OrderStatus[]>(`/orders/${row.id}/allowed-transitions`);
        transitionForm.status = allowedTransitions.value[0] ?? 'confirmed';
        return;
    }
    if (action === 'delivery') {
        resetModalState();
        modal.row = row;
        modal.mode = 'order-delivery';
        modal.open = true;
        Object.assign(deliveryForm, { method: 'pickup', notes: '' });
        return;
    }
    if (action === 'requirements') return openDetail(row, 'production-requirements');
    if (action === 'traceability') return openDetail(row, 'production-traceability');
    if (action === 'complete') {
        resetModalState();
        modal.row = row;
        modal.mode = 'production-complete';
        modal.open = true;
        Object.assign(productionForm, {
            actual_yield: row.planned_quantity ?? '1.000', waste_quantity: '0.000',
            destination_location_id: null, manufactured_at: localDateTime(), expires_at: '', observations: '',
            unit: row.unit ?? 'unit',
        });
        return;
    }
    if (action === 'start') return confirmStart(row);
    if (action === 'toggle') return confirmToggle(row);
    if (action === 'approve' || action === 'deactivate') return confirmRecipeStatus(row, action === 'approve' ? 'approved' : 'inactive');
    if (action === 'acknowledge' || action === 'resolve') return confirmAlert(row, action);
}

function closeModal() {
    modal.open = false;
    modal.row = null;
    detail.value = null;
    formDirty.value = false;
}

function refresh(id?: string | number) {
    operationalTable.value?.refresh();
    if (id !== undefined) {
        highlightedId.value = id;
        window.setTimeout(() => {
            if (String(highlightedId.value) === String(id)) highlightedId.value = null;
        }, 2600);
    }
}

async function mutate(action: () => Promise<Record<string, any> | void>, success: string, close = true) {
    if (!props.online) {
        emit('error', 'Sin conexión', ['La operación no se envió. Volvé a intentarlo cuando recuperes conexión.']);
        return;
    }
    busy.value = true;
    fieldErrors.value = {};
    try {
        const result = await action();
        const id = result && 'id' in result ? result.id : modal.row?.id;
        if (close) closeModal();
        confirmation.open = false;
        refresh(id);
        emit('notice', success);
    } catch (error) {
        if (error instanceof HttpError) fieldErrors.value = error.errors;
        const title = error instanceof HttpError && error.status === 0
            ? 'Resultado desconocido'
            : error instanceof HttpError && error.status === 422
                ? 'Revisá los datos ingresados'
                : 'No pudimos completar la operación';
        emit('error', title, errorMessages(error));
    } finally {
        busy.value = false;
    }
}

function submitForm() {
    if (modal.mode === 'order-transition') return prepareTransition();
    if (modal.mode === 'order-delivery') return prepareDelivery();
    if (modal.mode === 'payment-create' || props.view === 'payments') return preparePayment();
    if (modal.mode === 'production-complete') return prepareProductionComplete();

    if (props.view === 'customers') {
        const payload = customerPayload();
        return mutate(
            () => modal.mode === 'edit' ? api.patch(`/customers/${customerForm.id}`, payload) : api.post('/customers', payload),
            modal.mode === 'edit' ? 'Cliente actualizado.' : 'Cliente creado.',
        );
    }
    if (props.view === 'products') {
        const payload = productPayload();
        return mutate(
            () => modal.mode === 'edit' ? api.patch(`/products/${productForm.id}`, payload) : api.post('/products', payload),
            modal.mode === 'edit' ? 'Producto actualizado.' : 'Producto creado.',
        );
    }
    if (props.view === 'locations') {
        return mutate(() => api.post('/locations', locationForm), 'Ubicación creada.');
    }
    if (props.view === 'lots') {
        const payload = lotForm.lot_id
            ? { lot_id: lotForm.lot_id, quantity: lotForm.quantity, reason: lotForm.reason, type: lotForm.type }
            : {
                product_id: lotForm.product_id, location_id: lotForm.location_id, code: lotForm.code,
                unit: lotForm.unit, quantity: lotForm.quantity, expires_at: lotForm.expires_at || undefined,
                reason: lotForm.reason, type: lotForm.type,
            };
        return mutate(async () => {
            const result = await api.post<Record<string, any>>('/lots/adjustments', payload, lotKey);
            lotKey = idempotencyKey('lot');
            return result;
        }, lotForm.lot_id ? 'Ajuste de stock registrado.' : 'Recepción registrada.');
    }
    if (props.view === 'orders') {
        const payload = {
            customer_id: orderForm.customer_id || undefined,
            customer_name: orderForm.customer_id ? undefined : orderForm.customer_name,
            required_at: orderForm.required_at || undefined,
            items: [{ product_id: orderForm.product_id, quantity: orderForm.quantity, unit_price: orderForm.unit_price }],
        };
        return mutate(async () => {
            const result = await api.post<Record<string, any>>('/orders', payload, orderKey);
            orderKey = idempotencyKey('order');
            return result;
        }, 'Pedido creado.');
    }
    if (props.view === 'recipes') {
        const payload = {
            product_id: recipeForm.product_id,
            expected_yield: recipeForm.expected_yield,
            yield_unit: recipeForm.yield_unit,
            theoretical_waste_percent: recipeForm.theoretical_waste_percent,
            status: recipeForm.status,
            items: recipeForm.items,
        };
        return mutate(() => api.post('/recipes', payload), 'Receta creada.');
    }
    if (props.view === 'production') {
        const payload = {
            order_id: productionForm.order_id, recipe_id: productionForm.recipe_id,
            planned_quantity: productionForm.planned_quantity, unit: productionForm.unit,
        };
        return mutate(async () => {
            const result = await api.post<Record<string, any>>('/production-orders', payload, productionKey);
            productionKey = idempotencyKey('production');
            return result;
        }, 'Orden de producción creada.');
    }
}

function openConfirmation(config: Omit<typeof confirmation, 'open'>) {
    Object.assign(confirmation, config, { open: true });
}

function confirmToggle(row: Record<string, any>) {
    const active = !row.active;
    const isCustomer = props.view === 'customers';
    const payload = isCustomer
        ? { ...customerPayload(row), active }
        : { ...productPayload(row), active };
    openConfirmation({
        title: active ? 'Activar registro' : 'Desactivar registro',
        description: active ? 'El registro volverá a estar disponible para la operación.' : 'El registro dejará de ofrecerse en las operaciones nuevas.',
        entity: row.name,
        label: active ? 'Activar' : 'Desactivar',
        tone: active ? 'primary' : 'danger',
        details: [`Estado actual: ${row.active ? 'Activo' : 'Inactivo'}`, `Nuevo estado: ${active ? 'Activo' : 'Inactivo'}`],
        execute: () => mutate(
            () => api.patch(`${endpoint.value}/${row.id}`, payload),
            `${row.name} quedó ${active ? 'activo' : 'inactivo'}.`,
            false,
        ),
    });
}

function confirmRecipeStatus(row: Record<string, any>, status: 'approved' | 'inactive') {
    openConfirmation({
        title: status === 'approved' ? 'Aprobar receta' : 'Desactivar receta',
        description: status === 'approved' ? 'La receta podrá usarse para crear órdenes de producción.' : 'La receta dejará de estar disponible para nuevas órdenes.',
        entity: `${row.product?.name ?? 'Receta'} · versión ${row.version}`,
        label: status === 'approved' ? 'Aprobar receta' : 'Desactivar receta',
        tone: status === 'approved' ? 'primary' : 'danger',
        details: [`Estado actual: ${statusLabel(row.status)}`, `Nuevo estado: ${statusLabel(status)}`],
        execute: () => mutate(() => api.patch(`/recipes/${row.id}`, { status }), 'Estado de receta actualizado.', false),
    });
}

function confirmStart(row: Record<string, any>) {
    openConfirmation({
        title: 'Iniciar producción',
        description: 'La orden quedará en curso y registrará el operador y el momento de inicio.',
        entity: `Orden #${row.id} · ${row.recipe?.product?.name ?? 'Producto'}`,
        label: 'Iniciar producción',
        tone: 'primary',
        details: [`Pedido: #${row.order_id}`, `Cantidad planificada: ${row.planned_quantity} ${unitLabel(row.unit)}`],
        execute: () => mutate(async () => {
            const result = await api.post<Record<string, any>>(`/production-orders/${row.id}/start`, {}, productionActionKey);
            productionActionKey = idempotencyKey('production-action');
            return result;
        }, `Producción #${row.id} iniciada.`, false),
    });
}

function confirmAlert(row: Record<string, any>, action: string) {
    const resolving = action === 'resolve';
    openConfirmation({
        title: resolving ? 'Resolver alerta' : 'Reconocer alerta',
        description: resolving ? 'Confirmá que la situación fue atendida antes de cerrarla.' : 'La alerta quedará asignada como revisada, pero seguirá abierta.',
        entity: row.event,
        label: resolving ? 'Marcar como resuelta' : 'Reconocer alerta',
        tone: resolving ? 'primary' : 'primary',
        details: [`Severidad: ${statusLabel(row.severity)}`, row.action ? `Acción esperada: ${row.action}` : ''],
        execute: () => mutate(() => api.post(`/alerts/${row.id}/${action}`, {}), resolving ? 'Alerta resuelta.' : 'Alerta reconocida.', false),
    });
}

function prepareTransition() {
    const row = modal.row!;
    openConfirmation({
        title: transitionForm.status === 'cancelled' ? 'Cancelar pedido' : 'Confirmar cambio de estado',
        description: transitionForm.status === 'confirmed'
            ? 'Confirmar el pedido puede reservar existencias mediante FEFO.'
            : 'El nuevo estado quedará registrado en la auditoría del pedido.',
        entity: `Pedido #${row.id} · ${row.customer_name}`,
        label: transitionForm.status === 'cancelled' ? 'Cancelar pedido' : `Cambiar a ${statusLabel(transitionForm.status)}`,
        tone: transitionForm.status === 'cancelled' ? 'danger' : 'primary',
        details: [`Estado actual: ${statusLabel(row.status)}`, `Nuevo estado: ${statusLabel(transitionForm.status)}`],
        execute: () => mutate(async () => {
            const result = await api.post<Record<string, any>>(`/orders/${row.id}/transitions`, { status: transitionForm.status }, transitionKey);
            transitionKey = idempotencyKey('transition');
            return result;
        }, `Pedido #${row.id} actualizado.`),
    });
}

function prepareDelivery() {
    const row = modal.row!;
    openConfirmation({
        title: 'Confirmar entrega',
        description: 'La entrega actualiza el estado del pedido y sus movimientos de inventario.',
        entity: `Pedido #${row.id} · ${row.customer_name}`,
        label: 'Registrar entrega',
        tone: 'primary',
        details: [`Método: ${deliveryLabel(deliveryForm.method)}`, `Total: ${money(row.total)}`],
        execute: () => mutate(async () => {
            const result = await api.post<Record<string, any>>(`/orders/${row.id}/delivery`, { method: deliveryForm.method, notes: deliveryForm.notes || undefined }, deliveryKey);
            deliveryKey = idempotencyKey('delivery');
            return result;
        }, `Pedido #${row.id} entregado.`),
    });
}

function preparePayment() {
    const row = modal.row;
    const entity = row ? `Pedido #${row.id} · ${row.customer_name}` : selectedOrderLabel.value || `Pedido #${paymentForm.order_id}`;
    openConfirmation({
        title: 'Confirmar pago',
        description: 'El pago se aplicará una sola vez y generará el movimiento de caja correspondiente.',
        entity,
        label: `Registrar ${money(paymentForm.amount)}`,
        tone: 'primary',
        details: [`Medio: ${paymentMethod(paymentForm.method)}`, paymentForm.external_reference ? `Referencia: ${paymentForm.external_reference}` : 'Sin referencia externa'],
        execute: () => mutate(async () => {
            const result = await api.post<Record<string, any>>('/payments', paymentForm, paymentKey);
            paymentKey = idempotencyKey('payment');
            return result;
        }, 'Pago registrado.'),
    });
}

function prepareProductionComplete() {
    const row = modal.row!;
    openConfirmation({
        title: 'Completar producción',
        description: 'Se consumirán lotes por FEFO y se creará el lote terminado. Esta operación no debe duplicarse.',
        entity: `Orden #${row.id} · ${row.recipe?.product?.name ?? 'Producto'}`,
        label: 'Completar producción',
        tone: 'primary',
        details: [
            `Rendimiento: ${productionForm.actual_yield} ${unitLabel(productionForm.unit)}`,
            `Merma: ${productionForm.waste_quantity} ${unitLabel(productionForm.unit)}`,
            `Ubicación destino: ${selectedLocationLabel.value || `#${productionForm.destination_location_id}`}`,
        ],
        execute: () => mutate(async () => {
            const payload = {
                actual_yield: productionForm.actual_yield, unit: productionForm.unit,
                waste_quantity: productionForm.waste_quantity,
                destination_location_id: productionForm.destination_location_id,
                manufactured_at: productionForm.manufactured_at,
                expires_at: productionForm.expires_at || undefined,
                observations: productionForm.observations || undefined,
            };
            const result = await api.post<Record<string, any>>(`/production-orders/${row.id}/complete`, payload, productionActionKey);
            productionActionKey = idempotencyKey('production-action');
            return result;
        }, `Producción #${row.id} completada.`),
    });
}

async function executeConfirmation() {
    await confirmation.execute?.();
}

function customerPayload(source: Record<string, any> = customerForm) {
    return {
        name: source.name, tax_id: source.tax_id || undefined, tax_condition: source.tax_condition || undefined,
        email: source.email || undefined, phone: source.phone || undefined,
        credit_limit: source.credit_limit || '0.00', active: Boolean(source.active),
    };
}

function productPayload(source: Record<string, any> = productForm) {
    return {
        name: source.name, type: source.type, unit: source.unit,
        minimum_stock: source.minimum_stock ?? '0.000', price: source.price ?? '0.00',
        active: Boolean(source.active),
    };
}

function addIngredient() {
    recipeForm.items.push({ ingredient_product_id: null, quantity: '1.000', unit: 'unit' });
    formDirty.value = true;
}

function removeIngredient(index: number) {
    if (recipeForm.items.length <= 1) return;
    recipeForm.items.splice(index, 1);
    formDirty.value = true;
}

function activeOptions() {
    return [{ label: 'Activos', value: '1' }, { label: 'Inactivos', value: '0' }];
}

function can(...roles: string[]) {
    return roles.includes(props.role);
}

function money(value: string | number) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(Number(value || 0));
}

function decimalSubtract(left: unknown, right: unknown) {
    const cents = Math.max(0, Math.round(Number(left ?? 0) * 100) - Math.round(Number(right ?? 0) * 100));
    return (cents / 100).toFixed(2);
}

function formatDate(value: unknown) {
    if (!value) return '—';
    const date = new Date(String(value));
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('es-AR', { dateStyle: 'short', timeStyle: String(value).includes('T') ? 'short' : undefined }).format(date);
}

function statusLabel(value: string) {
    return ({
        draft: 'Borrador', confirmed: 'Confirmado', in_production: 'En producción', ready: 'Listo',
        delivered: 'Entregado', cancelled: 'Cancelado', planned: 'Planificada', in_progress: 'En curso',
        completed: 'Completada', approved: 'Aprobada', inactive: 'Inactiva', available: 'Disponible',
        depleted: 'Agotado', blocked: 'Bloqueado', expired: 'Vencido', open: 'Abierta',
        acknowledged: 'Reconocida', resolved: 'Resuelta', low: 'Baja', medium: 'Media',
        high: 'Alta', critical: 'Crítica', active: 'Activo',
    } as Record<string, string>)[value] ?? value;
}

function productType(value: string) {
    return ({ raw_material: 'Materia prima', semi_finished: 'Semielaborado', finished_product: 'Producto terminado', packaging: 'Empaque' } as Record<string, string>)[value] ?? value;
}

function unitLabel(value: string) {
    return ({ unit: 'unidad', kg: 'kg', g: 'g', l: 'l', ml: 'ml' } as Record<string, string>)[value] ?? value;
}

function paymentMethod(value: string) {
    return ({ cash: 'Efectivo', transfer: 'Transferencia', mercadopago: 'Mercado Pago', card: 'Tarjeta' } as Record<string, string>)[value] ?? value;
}

function deliveryLabel(value: string) {
    return ({ pickup: 'Retiro', delivery: 'Reparto', '': 'Sin definir' } as Record<string, string>)[value] ?? value;
}

function localDateTime() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
}

function fieldError(field: string) {
    return fieldErrors.value[field]?.join(' ');
}

function recipeSelectionLabel(row: Record<string, unknown> | null) {
    if (!row) return '';
    const product = row.product && typeof row.product === 'object'
        ? row.product as Record<string, unknown>
        : {};
    return `${String(product.name ?? 'Receta')} · v${String(row.version ?? '')}`;
}
</script>

<template>
    <section class="page module-page">
        <header class="list-page-header">
            <div>
                <p class="section-kicker">Sucursal · {{ branchName }}</p>
                <h2>{{ content.title }}</h2>
                <p>{{ content.description }}</p>
            </div>
            <button v-if="content.action && canCreate" class="primary" type="button" :disabled="!online" @click="openCreate()">{{ content.action }}</button>
        </header>

        <ServerDataTable
            :key="endpoint"
            ref="operationalTable"
            :endpoint="endpoint"
            :columns="tableColumns"
            :filters="tableFilters"
            :label="`Listado de ${content.title.toLowerCase()}`"
            :empty-label="content.empty"
            :empty-action="canCreate ? content.action : ''"
            :highlighted-id="highlightedId"
            initial-sort="id"
            persist-in-url
            @empty-action="openCreate()"
        >
            <template #cell-status="{ row }"><StatusBadge :status="String(row.status ?? 'active')" /></template>
            <template #cell-severity="{ row }"><StatusBadge :status="String(row.severity)" /></template>
            <template #cell-active="{ row }"><StatusBadge :status="row.active ? 'active' : 'inactive'" /></template>
            <template #actions="{ row }">
                <DataTableRowActions :actions="rowActions(row)" @action="handleRowAction(row, $event)" />
            </template>
        </ServerDataTable>
    </section>

    <BaseModal
        :open="modal.open && !confirmation.open"
        :title="modalTitle"
        :description="isFormModal ? 'Completá los datos y revisalos antes de guardar.' : 'Información de la sucursal activa.'"
        :size="modalSize"
        :busy="busy"
        :dirty="isFormModal && formDirty"
        @close="closeModal"
        @discard="formDirty = false"
    >
        <div v-if="detailLoading" class="modal-loading" role="status">Cargando información…</div>

        <form v-else-if="isFormModal" class="form-grid modal-form" @submit.prevent="submitForm" @input="formDirty = true">
            <template v-if="view === 'customers'">
                <label class="wide">Nombre o razón social
                    <input v-model="customerForm.name" autofocus required maxlength="255" :aria-invalid="Boolean(fieldError('name'))">
                    <small v-if="fieldError('name')" class="field-error">{{ fieldError('name') }}</small>
                </label>
                <label>CUIT o documento<input v-model="customerForm.tax_id" maxlength="32"></label>
                <label>Condición fiscal<input v-model="customerForm.tax_condition" maxlength="64"></label>
                <label>Correo electrónico<input v-model="customerForm.email" type="email"></label>
                <label>Teléfono<input v-model="customerForm.phone" maxlength="64"></label>
                <label>Límite de crédito<input v-model="customerForm.credit_limit" min="0" step="0.01" type="number"></label>
                <label class="check-field"><input v-model="customerForm.active" type="checkbox"> Cliente activo</label>
            </template>

            <template v-else-if="view === 'products'">
                <label class="wide">Nombre<input v-model="productForm.name" autofocus required maxlength="255"></label>
                <label>Tipo<select v-model="productForm.type"><option value="raw_material">Materia prima</option><option value="semi_finished">Semielaborado</option><option value="finished_product">Producto terminado</option><option value="packaging">Empaque</option></select></label>
                <label>Unidad<select v-model="productForm.unit"><option value="unit">Unidad</option><option value="kg">Kilogramo</option><option value="g">Gramo</option><option value="l">Litro</option><option value="ml">Mililitro</option></select></label>
                <label>Stock mínimo<input v-model="productForm.minimum_stock" required min="0" step="0.001" type="number"></label>
                <label>Precio<input v-model="productForm.price" required min="0" step="0.01" type="number"></label>
                <label class="check-field"><input v-model="productForm.active" type="checkbox"> Producto activo</label>
            </template>

            <template v-else-if="view === 'locations'">
                <label class="wide">Nombre de la ubicación<input v-model="locationForm.name" autofocus required maxlength="255" placeholder="Ej.: Cámara Centro"></label>
                <label class="check-field wide"><input v-model="locationForm.active" type="checkbox"> Ubicación activa</label>
            </template>

            <template v-else-if="view === 'lots'">
                <template v-if="modal.mode !== 'lot-adjust'">
                    <label>Producto
                        <RemoteSelect v-model="lotForm.product_id" endpoint="/products" sort="name" label-key="name" secondary-key="unit" placeholder="Buscar producto" @selected="selectedProductLabel = $event ? String($event.name) : ''" />
                    </label>
                    <label>Ubicación
                        <RemoteSelect v-model="lotForm.location_id" endpoint="/locations" sort="name" label-key="name" placeholder="Buscar ubicación" @selected="selectedLocationLabel = $event ? String($event.name) : ''" />
                    </label>
                    <label>Código de lote<input v-model="lotForm.code" required maxlength="128"></label>
                    <label>Unidad<select v-model="lotForm.unit"><option value="unit">Unidad</option><option value="kg">Kilogramo</option><option value="g">Gramo</option><option value="l">Litro</option><option value="ml">Mililitro</option></select></label>
                    <label>Vencimiento<input v-model="lotForm.expires_at" type="date"></label>
                </template>
                <div v-else class="form-context wide"><strong>{{ modal.row?.product?.name }}</strong><span>Lote {{ modal.row?.code }} · disponible {{ modal.row?.quantity }} {{ unitLabel(modal.row?.unit) }}</span></div>
                <label>Cantidad<input v-model="lotForm.quantity" autofocus required step="0.001" type="number" :placeholder="modal.mode === 'lot-adjust' ? 'Usá negativo para descontar' : ''"></label>
                <label>Tipo<select v-model="lotForm.type"><option value="receipt">Recepción</option><option value="adjustment">Ajuste</option><option value="waste">Merma</option><option value="reversal">Reversión</option></select></label>
                <label class="wide">Motivo<input v-model="lotForm.reason" required maxlength="255"></label>
            </template>

            <template v-else-if="view === 'orders' && modal.mode === 'create'">
                <label>Cliente registrado
                    <RemoteSelect v-model="orderForm.customer_id" endpoint="/customers" sort="name" label-key="name" secondary-key="tax_id" placeholder="Buscar cliente" @selected="selectedOrderLabel = $event ? String($event.name) : ''" />
                </label>
                <label v-if="!orderForm.customer_id">O nombre de mostrador<input v-model="orderForm.customer_name" :required="!orderForm.customer_id" maxlength="255"></label>
                <label>Fecha requerida<input v-model="orderForm.required_at" type="datetime-local"></label>
                <div class="form-section wide"><h3>Producto solicitado</h3><p>La primera versión operativa admite un renglón por pedido.</p></div>
                <label>Producto
                    <RemoteSelect v-model="orderForm.product_id" endpoint="/products" sort="name" label-key="name" secondary-key="unit" placeholder="Buscar producto" @selected="selectedProductLabel = $event ? String($event.name) : ''" />
                </label>
                <label>Cantidad<input v-model="orderForm.quantity" required min="0.001" step="0.001" type="number"></label>
                <label>Precio unitario<input v-model="orderForm.unit_price" required min="0" step="0.01" type="number"></label>
            </template>

            <template v-else-if="modal.mode === 'order-transition'">
                <div class="form-context wide"><strong>Pedido #{{ modal.row?.id }} · {{ modal.row?.customer_name }}</strong><span>Estado actual: {{ statusLabel(modal.row?.status) }}</span></div>
                <label class="wide">Nuevo estado<select v-model="transitionForm.status" autofocus><option v-for="status in allowedTransitions" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
            </template>

            <template v-else-if="modal.mode === 'order-delivery'">
                <div class="form-context wide"><strong>Pedido #{{ modal.row?.id }} · {{ modal.row?.customer_name }}</strong><span>Total {{ money(modal.row?.total) }} · estado {{ statusLabel(modal.row?.status) }}</span></div>
                <label>Método<select v-model="deliveryForm.method" autofocus><option value="pickup">Retiro</option><option value="delivery">Reparto</option></select></label>
                <label class="wide">Observaciones<textarea v-model="deliveryForm.notes" maxlength="2000" rows="3"></textarea></label>
            </template>

            <template v-else-if="view === 'recipes'">
                <label>Producto elaborado
                    <RemoteSelect v-model="recipeForm.product_id" endpoint="/products" sort="name" label-key="name" secondary-key="unit" placeholder="Buscar producto terminado" @selected="selectedProductLabel = $event ? String($event.name) : ''" />
                </label>
                <label>Rendimiento esperado<input v-model="recipeForm.expected_yield" required min="0.001" step="0.001" type="number"></label>
                <label>Unidad<select v-model="recipeForm.yield_unit"><option value="unit">Unidad</option><option value="kg">Kilogramo</option><option value="g">Gramo</option><option value="l">Litro</option><option value="ml">Mililitro</option></select></label>
                <label>Merma teórica %<input v-model="recipeForm.theoretical_waste_percent" min="0" max="100" step="0.01" type="number"></label>
                <label>Estado<select v-model="recipeForm.status"><option value="draft">Borrador</option><option value="approved">Aprobada</option></select></label>
                <div class="ingredient-editor wide">
                    <div class="form-section"><h3>Ingredientes</h3><button class="secondary" type="button" @click="addIngredient">Agregar ingrediente</button></div>
                    <div v-for="(item, index) in recipeForm.items" :key="index" class="ingredient-row">
                        <label>Ingrediente
                            <RemoteSelect v-model="item.ingredient_product_id" endpoint="/products" sort="name" label-key="name" secondary-key="unit" placeholder="Buscar ingrediente" />
                        </label>
                        <label>Cantidad<input v-model="item.quantity" min="0.001" step="0.001" required type="number"></label>
                        <label>Unidad<select v-model="item.unit"><option value="unit">Unidad</option><option value="kg">kg</option><option value="g">g</option><option value="l">l</option><option value="ml">ml</option></select></label>
                        <button class="text-button danger-text" type="button" :disabled="recipeForm.items.length === 1" @click="removeIngredient(index)">Quitar</button>
                    </div>
                </div>
            </template>

            <template v-else-if="view === 'production' && modal.mode === 'create'">
                <label>Pedido
                    <RemoteSelect v-model="productionForm.order_id" endpoint="/orders" sort="id" label-key="customer_name" secondary-key="status" placeholder="Buscar pedido por cliente" @selected="selectedOrderLabel = $event ? `Pedido #${$event.id} · ${$event.customer_name}` : ''" />
                </label>
                <label>Receta aprobada
                    <RemoteSelect v-model="productionForm.recipe_id" endpoint="/recipes" sort="id" label-key="product.name" secondary-key="version" placeholder="Buscar receta" @selected="selectedRecipeLabel = recipeSelectionLabel($event)" />
                </label>
                <label>Cantidad planificada<input v-model="productionForm.planned_quantity" required min="0.001" step="0.001" type="number"></label>
                <label>Unidad<select v-model="productionForm.unit"><option value="unit">Unidad</option><option value="kg">kg</option><option value="g">g</option><option value="l">l</option><option value="ml">ml</option></select></label>
            </template>

            <template v-else-if="modal.mode === 'production-complete'">
                <div class="form-context wide"><strong>Orden #{{ modal.row?.id }} · {{ modal.row?.recipe?.product?.name }}</strong><span>Planificado: {{ modal.row?.planned_quantity }} {{ unitLabel(modal.row?.unit) }}</span></div>
                <label>Rendimiento real<input v-model="productionForm.actual_yield" autofocus required min="0" step="0.001" type="number"></label>
                <label>Merma<input v-model="productionForm.waste_quantity" required min="0" step="0.001" type="number"></label>
                <label>Unidad<select v-model="productionForm.unit"><option value="unit">Unidad</option><option value="kg">kg</option><option value="g">g</option><option value="l">l</option><option value="ml">ml</option></select></label>
                <label>Ubicación destino
                    <RemoteSelect v-model="productionForm.destination_location_id" endpoint="/locations" sort="name" label-key="name" placeholder="Buscar ubicación" @selected="selectedLocationLabel = $event ? String($event.name) : ''" />
                </label>
                <label>Elaboración<input v-model="productionForm.manufactured_at" required type="datetime-local"></label>
                <label>Vencimiento<input v-model="productionForm.expires_at" type="date"></label>
                <label class="wide">Observaciones<textarea v-model="productionForm.observations" maxlength="4000" rows="3"></textarea></label>
            </template>

            <template v-else-if="modal.mode === 'payment-create' || view === 'payments'">
                <div v-if="modal.row" class="form-context wide"><strong>Pedido #{{ modal.row.id }} · {{ modal.row.customer_name }}</strong><span>Saldo pendiente: {{ money(decimalSubtract(modal.row.total, modal.row.paid_total)) }}</span></div>
                <label v-else>Pedido
                    <RemoteSelect v-model="paymentForm.order_id" endpoint="/orders" sort="id" label-key="customer_name" secondary-key="status" placeholder="Buscar pedido por cliente" @selected="selectedOrderLabel = $event ? `Pedido #${$event.id} · ${$event.customer_name}` : ''" />
                </label>
                <label>Importe<input v-model="paymentForm.amount" autofocus required min="0.01" step="0.01" type="number"></label>
                <label>Medio<select v-model="paymentForm.method"><option value="cash">Efectivo</option><option value="transfer">Transferencia</option><option value="mercadopago">Mercado Pago</option><option value="card">Tarjeta</option></select></label>
                <label>Referencia externa<input v-model="paymentForm.external_reference" maxlength="255" placeholder="Opcional"></label>
            </template>

            <div v-if="Object.keys(fieldErrors).length" class="form-error-summary wide" role="alert">
                <strong>Revisá los campos marcados</strong>
                <ul><li v-for="message in Object.values(fieldErrors).flat()" :key="message">{{ message }}</li></ul>
            </div>
        </form>

        <div v-else-if="detail" class="entity-detail">
            <template v-if="modal.mode === 'production-requirements'">
                <div class="detail-lead"><StatusBadge :status="detail.can_produce ? 'ready' : 'blocked'" /><strong>{{ detail.can_produce ? 'Ingredientes disponibles' : 'Producción bloqueada por faltantes' }}</strong></div>
                <div class="responsive-detail-table">
                    <table aria-label="Requerimientos de ingredientes">
                        <thead><tr><th>Ingrediente</th><th>Requerido</th><th>Disponible</th><th>Faltante</th><th>Estado</th></tr></thead>
                        <tbody><tr v-for="ingredient in detail.ingredients" :key="ingredient.snapshot_item_index">
                            <td data-label="Ingrediente">{{ ingredient.ingredient_name }}</td>
                            <td data-label="Requerido" class="numeric">{{ ingredient.required_quantity }} {{ unitLabel(ingredient.normalized_unit) }}</td>
                            <td data-label="Disponible" class="numeric">{{ ingredient.available_quantity }} {{ unitLabel(ingredient.normalized_unit) }}</td>
                            <td data-label="Faltante" class="numeric">{{ ingredient.missing_quantity }} {{ unitLabel(ingredient.normalized_unit) }}</td>
                            <td data-label="Estado"><StatusBadge :status="ingredient.can_produce ? 'ready' : 'blocked'" /></td>
                        </tr></tbody>
                    </table>
                </div>
            </template>

            <template v-else-if="modal.mode === 'production-traceability'">
                <div class="traceability-flow">
                    <section class="trace-step">
                        <span class="trace-marker">1</span><div><small>Receta congelada</small><strong>{{ detail.recipe_snapshot?.product?.name }} · versión {{ detail.recipe_snapshot?.version }}</strong><p>Rendimiento esperado {{ detail.recipe_snapshot?.expected_yield }} {{ unitLabel(detail.recipe_snapshot?.yield_unit) }}</p></div>
                    </section>
                    <section class="trace-step">
                        <span class="trace-marker">2</span><div><small>Lotes consumidos</small><strong>{{ detail.consumed_lots?.length ?? 0 }} lotes trazados</strong>
                            <ul><li v-for="lot in detail.consumed_lots" :key="lot.lot_id">Lote {{ lot.lot_code }} · {{ lot.quantity }} {{ unitLabel(lot.unit) }}</li></ul>
                        </div>
                    </section>
                    <section class="trace-step">
                        <span class="trace-marker">3</span><div><small>Resultado</small><strong v-if="detail.produced_lot">Lote {{ detail.produced_lot.code }}</strong><strong v-else>Sin lote terminado</strong><p v-if="detail.produced_lot">{{ detail.produced_lot.quantity }} {{ unitLabel(detail.produced_lot.unit) }} · vence {{ formatDate(detail.produced_lot.expires_at) }}</p></div>
                    </section>
                </div>
            </template>

            <template v-else-if="view === 'lots' && detail.data">
                <dl class="detail-grid">
                    <div><dt>Lote</dt><dd>{{ detail.data.code }}</dd></div>
                    <div><dt>Disponible</dt><dd>{{ detail.data.quantity }} {{ unitLabel(detail.data.unit) }}</dd></div>
                    <div><dt>Reservado</dt><dd>{{ detail.data.reserved_quantity }} {{ unitLabel(detail.data.unit) }}</dd></div>
                    <div><dt>Vencimiento</dt><dd>{{ formatDate(detail.data.expires_at) }}</dd></div>
                </dl>
                <h3>Movimientos recientes</h3>
                <div class="responsive-detail-table"><table><thead><tr><th>Tipo</th><th>Cantidad</th><th>Motivo</th><th>Fecha</th></tr></thead><tbody>
                    <tr v-for="movement in detail.movements?.data ?? []" :key="movement.id"><td data-label="Tipo">{{ statusLabel(movement.type) }}</td><td data-label="Cantidad" class="numeric">{{ movement.quantity }}</td><td data-label="Motivo">{{ movement.reason }}</td><td data-label="Fecha">{{ formatDate(movement.created_at) }}</td></tr>
                </tbody></table></div>
            </template>

            <template v-else-if="view === 'orders'">
                <dl class="detail-grid">
                    <div><dt>Pedido</dt><dd>#{{ detail.id }}</dd></div><div><dt>Cliente</dt><dd>{{ detail.customer_name }}</dd></div>
                    <div><dt>Estado</dt><dd><StatusBadge :status="detail.status" /></dd></div><div><dt>Total</dt><dd>{{ money(detail.total) }}</dd></div>
                    <div><dt>Pagado</dt><dd>{{ money(detail.paid_total) }}</dd></div><div><dt>Fecha requerida</dt><dd>{{ formatDate(detail.required_at) }}</dd></div>
                </dl>
                <h3>Productos</h3>
                <div class="responsive-detail-table"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th></tr></thead><tbody>
                    <tr v-for="item in detail.items ?? []" :key="item.id"><td data-label="Producto">#{{ item.product_id }}</td><td data-label="Cantidad" class="numeric">{{ item.quantity }}</td><td data-label="Precio" class="numeric">{{ money(item.unit_price) }}</td></tr>
                </tbody></table></div>
                <h3>Historial</h3>
                <ol class="timeline"><li v-for="transition in detail.transitions ?? []" :key="transition.id"><StatusBadge :status="transition.to_status" /><span>{{ formatDate(transition.created_at) }}</span></li></ol>
            </template>

            <template v-else-if="view === 'recipes'">
                <dl class="detail-grid">
                    <div><dt>Producto</dt><dd>{{ detail.product?.name }}</dd></div><div><dt>Versión</dt><dd>{{ detail.version }}</dd></div>
                    <div><dt>Rendimiento</dt><dd>{{ detail.yield_quantity }} {{ unitLabel(detail.yield_unit) }}</dd></div><div><dt>Estado</dt><dd><StatusBadge :status="detail.status" /></dd></div>
                </dl>
                <h3>Ingredientes</h3>
                <div class="responsive-detail-table"><table><thead><tr><th>Ingrediente</th><th>Cantidad</th><th>Unidad</th></tr></thead><tbody>
                    <tr v-for="item in detail.items ?? []" :key="item.id"><td data-label="Ingrediente">{{ item.ingredient?.name }}</td><td data-label="Cantidad" class="numeric">{{ item.quantity }}</td><td data-label="Unidad">{{ unitLabel(item.unit) }}</td></tr>
                </tbody></table></div>
            </template>

            <template v-else>
                <dl class="detail-grid">
                    <div v-for="(value, key) in detail" :key="key" v-show="!['organization_id', 'branch_id', 'updated_at'].includes(String(key)) && typeof value !== 'object'">
                        <dt>{{ String(key).replaceAll('_', ' ') }}</dt>
                        <dd>{{ key.toString().includes('at') ? formatDate(value) : value ?? '—' }}</dd>
                    </div>
                </dl>
            </template>
        </div>

        <template v-if="isFormModal" #footer="{ close }">
            <button class="secondary" type="button" :disabled="busy" @click="close">Cancelar</button>
            <button class="primary" type="button" :disabled="busy || !online" @click="submitForm">{{ busy ? 'Guardando…' : modal.mode === 'edit' ? 'Guardar cambios' : 'Continuar' }}</button>
        </template>
        <template v-else #footer="{ close }">
            <button class="primary" type="button" @click="close">Cerrar</button>
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
    >
        <ul class="confirm-details"><li v-for="item in confirmation.details.filter(Boolean)" :key="item">{{ item }}</li></ul>
    </ConfirmActionModal>
</template>
