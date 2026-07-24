<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, errorMessages, HttpError, idempotencyKey } from '../api';
import brandLogo from '../assets/brand/la-victoria-bakery-logo.png';
import { useSessionStore } from '../stores/session';
import type { CreateOrderInput, Order, OrderStatus, Payment, View } from '../types';
import type { DataTableColumn, DataTableFilter } from '../types';
import ServerDataTable from './data-table/ServerDataTable.vue';
import DataState from './ui/DataState.vue';
import MetricBlock from './ui/MetricBlock.vue';
import StatusBadge from './ui/StatusBadge.vue';

const session = useSessionStore();
const view = ref<View>('dashboard');
const online = ref(navigator.onLine);
const busy = ref(false);
const recoveringSession = ref(true);
const notice = ref('');
const errors = ref<string[]>([]);
const errorTitle = ref('No pudimos completar la operación');
const recentOrders = ref<Order[]>([]);
const recentPayments = ref<Payment[]>([]);
const dashboardProductions = ref<Record<string, unknown>[]>([]);
const dashboardAlerts = ref<Record<string, unknown>[]>([]);
const moduleRows = ref<Record<string, unknown>[]>([]);
const productionRequirements = ref<Record<string, unknown> | null>(null);
const productionTraceability = ref<Record<string, unknown> | null>(null);
const operationalTable = ref<InstanceType<typeof ServerDataTable> | null>(null);
const loginForm = reactive({ email: 'admin@lavictoria.test', password: '' });
const orderForm = reactive({
    customer_name: '',
    required_at: '',
    product_id: 1,
    quantity: '1.000',
    unit_price: '0.00',
});
const paymentForm = reactive({
    order_id: 0,
    amount: '0.00',
    method: 'transfer' as Payment['method'],
    external_reference: '',
});
const transitionForm = reactive({ order_id: 0, status: 'confirmed' as OrderStatus });
const customerForm = reactive({ name: '', email: '' });
const productForm = reactive({
    name: '', type: 'finished_product', unit: 'unit', minimum_stock: '0.000', price: '0.00',
});
const lotForm = reactive({
    product_id: 0, location_id: 0, code: '', unit: 'unit',
    quantity: '0.000', expires_at: '', reason: 'initial receipt', type: 'receipt',
});
const productionForm = reactive({
    id: 0, order_id: 0, recipe_id: 0, planned_quantity: '1.000',
    actual_yield: '1.000', waste_quantity: '0.000', unit: 'unit',
    destination_location_id: 0, manufactured_at: '', expires_at: '', observations: '',
});
const deliveryForm = reactive({ order_id: 0, method: 'pickup', notes: '' });
const alertForm = reactive({ id: 0 });
let orderKey = idempotencyKey('order');
let paymentKey = idempotencyKey('payment');
let transitionKey = idempotencyKey('transition');
let lotKey = idempotencyKey('lot');
let productionKey = idempotencyKey('production');
let productionActionKey = idempotencyKey('production-action');
let deliveryKey = idempotencyKey('delivery');
let loadSequence = 0;

const navigation: Array<{ id: View; label: string; icon: string }> = [
    { id: 'dashboard', label: 'Resumen', icon: '⌂' },
    { id: 'customers', label: 'Clientes', icon: 'CL' },
    { id: 'products', label: 'Productos', icon: 'PR' },
    { id: 'lots', label: 'Inventario', icon: 'IN' },
    { id: 'orders', label: 'Pedidos', icon: 'PE' },
    { id: 'production', label: 'Producción', icon: 'OP' },
    { id: 'payments', label: 'Cobranzas', icon: '$' },
    { id: 'alerts', label: 'Alertas', icon: '!' },
];

const pageTitle = computed(() => navigation.find((item) => item.id === view.value)?.label ?? 'Resumen');
const pageDescription = computed(() => ({
    dashboard: 'Prioridades y actividad de la sucursal activa.',
    customers: 'Datos de contacto y relación comercial.',
    products: 'Catálogo, unidades y niveles mínimos.',
    lots: 'Disponibilidad, ubicación y vencimiento por lote.',
    orders: 'Alta, seguimiento y entrega de pedidos.',
    production: 'Órdenes, rendimiento, merma y trazabilidad.',
    payments: 'Registro seguro y seguimiento de cobranzas.',
    alerts: 'Situaciones que requieren revisión o acción.',
})[view.value]);
const tableEndpoints: Partial<Record<View, string>> = {
    customers: '/customers',
    products: '/products',
    lots: '/lots',
    orders: '/orders',
    production: '/production-orders',
    payments: '/payments',
    alerts: '/alerts',
};
const columnsByView: Partial<Record<View, DataTableColumn[]>> = {
    customers: [
        { key: 'id', label: 'ID', sortable: true },
        { key: 'name', label: 'Cliente', sortable: true },
        { key: 'email', label: 'Correo', sortable: true },
        { key: 'phone', label: 'Teléfono' },
        { key: 'active', label: 'Estado', render: (row) => row.active ? 'Activo' : 'Inactivo' },
    ],
    products: [
        { key: 'id', label: 'ID', sortable: true },
        { key: 'name', label: 'Producto', sortable: true },
        { key: 'type', label: 'Tipo', sortable: true },
        { key: 'unit', label: 'Unidad' },
        { key: 'price', label: 'Precio', sortable: true, align: 'end', render: (row) => money(String(row.price ?? '0')) },
    ],
    lots: [
        { key: 'id', label: 'ID', sortable: true },
        { key: 'code', label: 'Lote', sortable: true },
        { key: 'product.name', label: 'Producto' },
        { key: 'location.name', label: 'Ubicación' },
        { key: 'quantity', label: 'Disponible', sortable: true, align: 'end' },
        { key: 'expires_at', label: 'Vencimiento', sortable: true },
        { key: 'status', label: 'Estado', sortable: true },
    ],
    orders: [
        { key: 'id', label: 'Pedido', sortable: true, render: (row) => `#${row.id}` },
        { key: 'customer_name', label: 'Cliente', sortable: true },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'total', label: 'Total', sortable: true, align: 'end', render: (row) => money(String(row.total ?? '0')) },
        { key: 'paid_total', label: 'Pagado', align: 'end', render: (row) => money(String(row.paid_total ?? '0')) },
        { key: 'required_at', label: 'Fecha requerida', sortable: true },
    ],
    production: [
        { key: 'id', label: 'Orden', sortable: true, render: (row) => `#${row.id}` },
        { key: 'order_id', label: 'Pedido' },
        { key: 'recipe.product.name', label: 'Producto' },
        { key: 'planned_quantity', label: 'Planificado', sortable: true, align: 'end' },
        { key: 'actual_yield', label: 'Rendimiento', align: 'end' },
        { key: 'status', label: 'Estado', sortable: true },
    ],
    payments: [
        { key: 'id', label: 'Pago', sortable: true, render: (row) => `#${row.id}` },
        { key: 'order_id', label: 'Pedido', sortable: true, render: (row) => `#${row.order_id}` },
        { key: 'order.customer_name', label: 'Cliente' },
        { key: 'amount', label: 'Importe', sortable: true, align: 'end', render: (row) => money(String(row.amount ?? '0')) },
        { key: 'method', label: 'Medio', sortable: true },
        { key: 'created_at', label: 'Fecha', sortable: true },
    ],
    alerts: [
        { key: 'id', label: 'Alerta', sortable: true, render: (row) => `#${row.id}` },
        { key: 'event', label: 'Evento', sortable: true },
        { key: 'expected_action', label: 'Acción esperada' },
        { key: 'severity', label: 'Severidad', sortable: true },
        { key: 'status', label: 'Estado', sortable: true },
        { key: 'last_seen_at', label: 'Última detección', sortable: true },
    ],
};
const filtersByView: Partial<Record<View, DataTableFilter[]>> = {
    customers: [{ key: 'active', label: 'Estado', options: [{ label: 'Activos', value: '1' }, { label: 'Inactivos', value: '0' }] }],
    products: [
        { key: 'active', label: 'Estado', options: [{ label: 'Activos', value: '1' }, { label: 'Inactivos', value: '0' }] },
        { key: 'type', label: 'Tipo', options: [{ label: 'Materia prima', value: 'raw_material' }, { label: 'Semielaborado', value: 'semi_finished' }, { label: 'Terminado', value: 'finished_product' }, { label: 'Empaque', value: 'packaging' }] },
    ],
    orders: [{ key: 'status', label: 'Estado', options: ['draft', 'confirmed', 'in_production', 'ready', 'delivered', 'cancelled'].map((value) => ({ label: statusLabel(value as OrderStatus), value })) }],
    payments: [{ key: 'method', label: 'Medio', options: [{ label: 'Efectivo', value: 'cash' }, { label: 'Transferencia', value: 'transfer' }, { label: 'Mercado Pago', value: 'mercadopago' }, { label: 'Tarjeta', value: 'card' }] }],
    alerts: [
        { key: 'severity', label: 'Severidad', options: ['low', 'medium', 'high', 'critical'].map((value) => ({ label: value, value })) },
        { key: 'status', label: 'Estado', options: ['open', 'acknowledged', 'resolved'].map((value) => ({ label: value, value })) },
    ],
};
const tableEndpoint = computed(() => tableEndpoints[view.value] ?? '/orders');
const tableColumns = computed<DataTableColumn[]>(() => columnsByView[view.value] ?? []);
const tableFilters = computed<DataTableFilter[]>(() => filtersByView[view.value] ?? []);
const outstanding = computed(() => centsToDecimal(recentOrders.value.reduce(
    (sum, order) => sum + decimalToCents(order.total) - decimalToCents(order.paid_total),
    0n,
)));

function setConnection() {
    online.value = navigator.onLine;
}

function navigate(target: View) {
    view.value = target;
    errors.value = [];
    notice.value = '';
    history.replaceState(null, '', target === 'dashboard' ? '/' : `/#${target}`);
    if (target === 'dashboard') void loadView(target);
}

async function loadView(target: View) {
    if (!session.authenticated || !online.value) return;
    const sequence = ++loadSequence;
    if (target === 'dashboard') {
        await run(async () => {
            const [orders, payments, productions, alerts] = await Promise.all([
                api.get<Order[]>('/orders'),
                api.get<Payment[]>('/payments'),
                api.get<Record<string, unknown>[]>('/production-orders'),
                api.get<Record<string, unknown>[]>('/alerts'),
            ]);
            if (sequence !== loadSequence || view.value !== target) return;
            recentOrders.value = orders;
            recentPayments.value = payments;
            dashboardProductions.value = productions;
            dashboardAlerts.value = alerts;
        });
        return;
    }
    return;
}

function handleTableLoaded(rows: Record<string, unknown>[]) {
    moduleRows.value = rows;
    if (view.value === 'orders') recentOrders.value = rows as unknown as Order[];
    if (view.value === 'payments') recentPayments.value = rows as unknown as Payment[];
}

function refreshTable() {
    operationalTable.value?.refresh();
}

async function changeBranch(event: Event) {
    const selectedBranch = Number((event.target as HTMLSelectElement).value);
    session.selectTenant(Number(session.organizationId), selectedBranch);
    loadSequence++;
    moduleRows.value = [];
    recentOrders.value = [];
    recentPayments.value = [];
    dashboardProductions.value = [];
    dashboardAlerts.value = [];
    productionRequirements.value = null;
    productionTraceability.value = null;
    notice.value = `Sucursal activa: ${session.branch}.`;
    if (view.value === 'dashboard') await loadView(view.value);
    else {
        await nextTick();
        refreshTable();
    }
}

function logIn() {
    return run(async () => {
        await session.login(loginForm.email, loginForm.password);
    });
}

async function run(action: () => Promise<void>) {
    if (!online.value) {
        errors.value = ['Sin conexión. Las operaciones se bloquean para evitar datos inconsistentes.'];
        return;
    }
    busy.value = true;
    errors.value = [];
    errorTitle.value = 'No pudimos completar la operación';
    notice.value = '';
    try {
        await action();
    } catch (error) {
        errors.value = errorMessages(error);
        if (error instanceof HttpError) {
            errorTitle.value = ({
                0: 'Resultado desconocido',
                401: 'Tu sesión venció',
                403: 'No tenés permisos para esta acción',
                409: 'La información cambió',
                422: 'Revisá los datos ingresados',
            } as Record<number, string>)[error.status] ?? errorTitle.value;
        }
    } finally {
        busy.value = false;
    }
}

function createOrder() {
    return run(async () => {
        const payload: CreateOrderInput = {
            customer_name: orderForm.customer_name,
            required_at: orderForm.required_at || undefined,
            items: [{
                product_id: Number(orderForm.product_id),
                quantity: orderForm.quantity,
                unit_price: orderForm.unit_price,
            }],
        };
        const created = await api.post<Order>('/orders', payload, orderKey);
        recentOrders.value.unshift(created);
        paymentForm.order_id = created.id;
        transitionForm.order_id = created.id;
        orderKey = idempotencyKey('order');
        orderForm.customer_name = '';
        notice.value = `Pedido #${created.id} creado correctamente.`;
        refreshTable();
    });
}

function transitionOrder() {
    if (!window.confirm(`¿Confirmás cambiar el pedido #${transitionForm.order_id} a ${statusLabel(transitionForm.status)}?`)) return;
    return run(async () => {
        const updated = await api.post<Order>(
            `/orders/${transitionForm.order_id}/transitions`,
            { status: transitionForm.status },
            transitionKey,
        );
        const index = recentOrders.value.findIndex((order) => order.id === updated.id);
        if (index >= 0) recentOrders.value[index] = updated;
        else recentOrders.value.unshift(updated);
        transitionKey = idempotencyKey('transition');
        notice.value = `Pedido #${updated.id}: estado actualizado a ${statusLabel(updated.status)}.`;
    });
}

function createPayment() {
    if (!window.confirm(`¿Confirmás registrar ${money(paymentForm.amount)} en el pedido #${paymentForm.order_id}?`)) return;
    return run(async () => {
        const created = await api.post<Payment>('/payments', {
            order_id: Number(paymentForm.order_id),
            amount: paymentForm.amount,
            method: paymentForm.method,
            external_reference: paymentForm.external_reference || undefined,
        }, paymentKey);
        recentPayments.value.unshift(created);
        const order = recentOrders.value.find((item) => item.id === created.order_id);
        if (order) {
            order.paid_total = centsToDecimal(decimalToCents(order.paid_total) + decimalToCents(created.amount));
        }
        paymentKey = idempotencyKey('payment');
        paymentForm.amount = '0.00';
        paymentForm.external_reference = '';
        notice.value = `Pago #${created.id} registrado correctamente.`;
        refreshTable();
    });
}

function createCustomer() {
    return run(async () => {
        await api.post('/customers', customerForm);
        customerForm.name = '';
        customerForm.email = '';
        refreshTable();
        notice.value = 'Cliente creado.';
    });
}

function createProduct() {
    return run(async () => {
        await api.post('/products', productForm);
        productForm.name = '';
        refreshTable();
        notice.value = 'Producto creado.';
    });
}

function adjustLot() {
    return run(async () => {
        await api.post('/lots/adjustments', {
            ...lotForm, expires_at: lotForm.expires_at || undefined,
        }, lotKey);
        lotKey = idempotencyKey('lot');
        refreshTable();
        notice.value = 'Movimiento de stock registrado.';
    });
}

function createProduction() {
    return run(async () => {
        const created = await api.post<{ id: number }>('/production-orders', {
            order_id: productionForm.order_id, recipe_id: productionForm.recipe_id,
            planned_quantity: productionForm.planned_quantity, unit: productionForm.unit,
        }, productionKey);
        productionForm.id = created.id;
        productionKey = idempotencyKey('production');
        refreshTable();
        notice.value = `Producción #${created.id} creada.`;
    });
}

function productionAction(action: 'start' | 'complete') {
    const label = action === 'start' ? 'iniciar' : 'completar';
    if (!window.confirm(`¿Confirmás ${label} la producción #${productionForm.id}?`)) return;
    return run(async () => {
        const body = action === 'complete'
            ? {
                actual_yield: productionForm.actual_yield,
                unit: productionForm.unit,
                waste_quantity: productionForm.waste_quantity,
                destination_location_id: productionForm.destination_location_id,
                manufactured_at: productionForm.manufactured_at,
                expires_at: productionForm.expires_at || undefined,
                observations: productionForm.observations || undefined,
            }
            : {};
        await api.post(`/production-orders/${productionForm.id}/${action}`, body, productionActionKey);
        productionActionKey = idempotencyKey('production-action');
        refreshTable();
        notice.value = action === 'start' ? 'Producción iniciada.' : 'Producción completada.';
    });
}

function loadProductionDetail(kind: 'requirements' | 'traceability') {
    return run(async () => {
        const data = await api.get<Record<string, unknown>>(
            `/production-orders/${productionForm.id}/${kind}`,
        );
        if (kind === 'requirements') productionRequirements.value = data;
        else productionTraceability.value = data;
        notice.value = kind === 'requirements'
            ? 'Disponibilidad recalculada con datos del servidor.'
            : 'Trazabilidad actualizada.';
    });
}

function deliverOrder() {
    if (!window.confirm(`¿Confirmás la entrega del pedido #${deliveryForm.order_id}? Esta acción actualiza stock y estado.`)) return;
    return run(async () => {
        await api.post(`/orders/${deliveryForm.order_id}/delivery`, {
            method: deliveryForm.method, notes: deliveryForm.notes || undefined,
        }, deliveryKey);
        deliveryKey = idempotencyKey('delivery');
        refreshTable();
        notice.value = `Pedido #${deliveryForm.order_id} entregado.`;
    });
}

function resolveAlert() {
    return run(async () => {
        await api.post(`/alerts/${alertForm.id}/resolve`, {});
        refreshTable();
        notice.value = `Alerta #${alertForm.id} resuelta.`;
    });
}

function statusLabel(status: OrderStatus) {
    return ({
        draft: 'Borrador',
        confirmed: 'Confirmado',
        in_production: 'En producción',
        ready: 'Listo',
        delivered: 'Entregado',
        cancelled: 'Cancelado',
    })[status];
}

function decimalToCents(value: string): bigint {
    const [whole, fraction = ''] = value.split('.');
    const negative = whole.startsWith('-');
    const absoluteWhole = whole.replace('-', '');
    const cents = BigInt(absoluteWhole || '0') * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2));
    return negative ? -cents : cents;
}

function centsToDecimal(cents: bigint): string {
    const negative = cents < 0n ? '-' : '';
    const absolute = cents < 0n ? -cents : cents;
    return `${negative}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
}

function money(value: string) {
    const cents = decimalToCents(value);
    const negative = cents < 0n ? '-' : '';
    const absolute = cents < 0n ? -cents : cents;
    return `${negative}$ ${BigInt(absolute / 100n).toLocaleString('es-AR')},${String(absolute % 100n).padStart(2, '0')}`;
}

function rowPrimary(row: Record<string, unknown>): string {
    const product = row.product as Record<string, unknown> | undefined;
    return String(row.name ?? row.event ?? row.code ?? product?.name ?? `Registro ${row.id}`);
}

function rowDetail(row: Record<string, unknown>): string {
    const location = row.location as Record<string, unknown> | undefined;
    const parts = [
        location?.name && `Ubicación: ${location.name}`,
        row.expires_at && `Vence: ${row.expires_at}`,
        row.quantity && `Disponible: ${row.quantity} ${row.unit ?? ''}`,
        row.reserved_quantity && `Reservado: ${row.reserved_quantity}`,
        row.planned_quantity && `Plan: ${row.planned_quantity} ${row.unit ?? ''}`,
        row.severity && `Severidad: ${row.severity}`,
        row.expected_action && `Acción: ${row.expected_action}`,
    ].filter(Boolean);
    return parts.length ? parts.join(' · ') : 'Sin detalle adicional';
}

onMounted(async () => {
    const hash = location.hash.slice(1) as View;
    if (navigation.some((item) => item.id === hash)) view.value = hash;
    addEventListener('online', setConnection);
    addEventListener('offline', setConnection);
    try {
        await session.recover();
        await loadView(view.value);
    } finally {
        recoveringSession.value = false;
    }
});
onBeforeUnmount(() => {
    removeEventListener('online', setConnection);
    removeEventListener('offline', setConnection);
});
</script>

<template>
    <main v-if="recoveringSession" class="session-recovery" aria-live="polite">
        <img class="recovery-logo" :src="brandLogo" alt="">
        <strong>Recuperando tu mesa de trabajo…</strong>
        <p>Estamos comprobando la sesión y la sucursal activa.</p>
    </main>

    <main v-else-if="!session.authenticated" class="login-page">
        <section class="login-intro" aria-label="La Victoria Bakery">
            <img class="login-logo" :src="brandLogo" alt="">
            <div>
                <p class="brand-name">La Victoria Bakery</p>
                <p>Pedidos, producción, inventario y cobranzas en una sola mesa operativa.</p>
            </div>
        </section>
        <section class="login-card">
            <p class="section-kicker">Acceso al sistema</p>
            <h1>Bienvenido a tu jornada.</h1>
            <p class="muted">Ingresá con tu usuario para continuar en la sucursal asignada.</p>
            <section v-if="errors.length" id="login-errors" class="message error" role="alert">
                <strong>No pudimos iniciar sesión</strong>
                <ul><li v-for="error in errors" :key="error">{{ error }}</li></ul>
            </section>
            <form class="form-stack" @submit.prevent="logIn">
                <label for="login-email">Correo electrónico</label>
                <input id="login-email" v-model="loginForm.email" required autocomplete="username" type="email" :aria-describedby="errors.length ? 'login-errors' : undefined">
                <label for="login-password">Contraseña</label>
                <input id="login-password" v-model="loginForm.password" required autocomplete="current-password" type="password" :aria-describedby="errors.length ? 'login-errors' : undefined">
                <button class="primary" :disabled="busy || !online" type="submit">{{ busy ? 'Ingresando…' : 'Ingresar' }}</button>
            </form>
            <p class="helper">{{ online ? 'Acceso exclusivo para personal autorizado.' : 'Conectate a internet para iniciar sesión.' }}</p>
        </section>
    </main>

    <div v-else class="app-layout">
        <aside class="sidebar">
            <div class="brand"><img class="brand-logo" :src="brandLogo" alt=""><div>La Victoria<small>Bakery · Operaciones</small></div></div>
            <nav aria-label="Navegación principal">
                <button
                    v-for="item in navigation"
                    :key="item.id"
                    :class="{ active: view === item.id }"
                    :aria-current="view === item.id ? 'page' : undefined"
                    @click="navigate(item.id)"
                ><span aria-hidden="true" class="nav-icon">{{ item.icon }}</span>{{ item.label }}</button>
            </nav>
            <div class="sidebar-foot">
                <span class="connection" :class="{ offline: !online }">{{ online ? 'Conectado' : 'Sin conexión' }}</span>
                <button class="logout" @click="session.logout()">Cerrar sesión</button>
            </div>
        </aside>

        <main class="workspace">
            <header class="topbar">
                <div><p class="section-kicker">Mesa operativa</p><h1>{{ pageTitle }}</h1><p class="page-description">{{ pageDescription }}</p></div>
                <div class="top-actions">
                    <label class="branch">Sucursal
                        <select :value="session.branchId" @change="changeBranch">
                            <option v-for="branch in session.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </label>
                    <div class="user-chip"><span class="avatar" aria-hidden="true">{{ session.name.slice(0, 2).toUpperCase() }}</span><span><strong>{{ session.name }}</strong><small>{{ session.branch }}</small></span></div>
                </div>
            </header>

            <section v-if="!online" class="offline-banner" role="status" aria-live="polite">
                <strong>Sin conexión.</strong> Podés revisar la información visible, pero las operaciones están bloqueadas.
            </section>
            <section v-if="errors.length" class="message error" role="alert">
                <strong>{{ errorTitle }}</strong>
                <ul><li v-for="error in errors" :key="error">{{ error }}</li></ul>
            </section>
            <p v-if="notice" class="message success" role="status" aria-live="polite">{{ notice }}</p>

            <section v-if="view === 'dashboard'" class="page">
                <div class="hero">
                    <div><p class="section-kicker">Hoy · {{ session.branch }}</p><h2>Lo que necesita atención, en orden.</h2>
                    <p>Revisá pedidos, producción, inventario y cobranzas desde la misma jornada.</p></div>
                    <button class="primary" :disabled="!online" @click="navigate('orders')">Nuevo pedido</button>
                </div>
                <div class="metric-grid">
                    <MetricBlock label="Pedidos visibles" :value="recentOrders.length" detail="En la sucursal activa" action="Abrir pedidos" @activate="navigate('orders')" />
                    <MetricBlock label="Producción pendiente" :value="dashboardProductions.filter(p => p.status === 'planned' || p.status === 'in_progress').length" detail="Órdenes por completar" action="Abrir producción" @activate="navigate('production')" />
                    <MetricBlock label="Saldo pendiente" :value="money(outstanding)" detail="Sobre pedidos cargados" action="Abrir cobranzas" @activate="navigate('payments')" />
                    <MetricBlock label="Alertas abiertas" :value="dashboardAlerts.filter(a => a.status !== 'resolved').length" detail="Priorizadas por severidad" action="Abrir alertas" @activate="navigate('alerts')" />
                </div>
                <div class="panel">
                    <div class="panel-head"><div><p class="section-kicker">Actividad</p><h3>Pedidos recientes</h3></div><button class="text-button" @click="navigate('orders')">Ver todos</button></div>
                    <div v-if="recentOrders.length" class="table-wrap"><table><thead><tr><th>Pedido</th><th>Cliente</th><th>Estado</th><th>Total</th></tr></thead>
                        <tbody><tr v-for="order in recentOrders.slice(0, 5)" :key="order.id"><td data-label="Pedido">#{{ order.id }}</td><td data-label="Cliente">{{ order.customer_name }}</td><td data-label="Estado"><StatusBadge :status="order.status" /></td><td data-label="Total" class="numeric">{{ money(order.total) }}</td></tr></tbody></table></div>
                    <DataState v-else title="No hay pedidos para mostrar" message="Creá un pedido o actualizá la lista para comenzar la jornada." action="Crear pedido" @activate="navigate('orders')" />
                </div>
            </section>

            <section v-else-if="view === 'orders'" class="page split">
                <div class="panel">
                    <div class="panel-head"><div><p class="section-kicker">Alta</p><h3>Nuevo pedido</h3></div></div>
                    <form class="form-grid" @submit.prevent="createOrder">
                        <label class="wide">Cliente<input v-model="orderForm.customer_name" required maxlength="255" placeholder="Nombre o razón social"></label>
                        <label>Fecha requerida<input v-model="orderForm.required_at" type="datetime-local"></label>
                        <label>ID de producto<input v-model.number="orderForm.product_id" required min="1" type="number"></label>
                        <label>Cantidad<input v-model="orderForm.quantity" required min="0.001" step="0.001" type="number"></label>
                        <label>Precio unitario<input v-model="orderForm.unit_price" required min="0" step="0.01" type="number"></label>
                        <button class="primary wide" :disabled="busy || !online">{{ busy ? 'Guardando…' : 'Crear pedido' }}</button>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head"><div><p class="section-kicker">Flujo</p><h3>Cambiar estado</h3></div></div>
                    <form class="form-stack" @submit.prevent="transitionOrder">
                        <label>ID de pedido<input v-model.number="transitionForm.order_id" required min="1" type="number"></label>
                        <label>Nuevo estado<select v-model="transitionForm.status"><option value="confirmed">Confirmado</option><option value="in_production">En producción</option><option value="ready">Listo</option><option value="delivered">Entregado</option><option value="cancelled">Cancelado</option></select></label>
                        <button class="secondary" :disabled="busy || !online">Actualizar estado</button>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head"><div><p class="section-kicker">Entrega</p><h3>Entregar pedido listo</h3></div></div>
                    <form class="form-stack" @submit.prevent="deliverOrder">
                        <label>ID de pedido<input v-model.number="deliveryForm.order_id" required min="1" type="number"></label>
                        <label>Método<select v-model="deliveryForm.method"><option value="pickup">Retiro</option><option value="delivery">Reparto</option></select></label>
                        <label>Observaciones<input v-model="deliveryForm.notes" maxlength="2000"></label>
                        <button class="primary" :disabled="busy || !online">Confirmar entrega</button>
                    </form>
                </div>
                <div class="panel full">
                    <div class="panel-head"><h3>Pedidos</h3></div>
                    <ServerDataTable
                        ref="operationalTable"
                        endpoint="/orders"
                        :columns="tableColumns"
                        :filters="tableFilters"
                        initial-sort="id"
                        persist-in-url
                        @loaded="handleTableLoaded"
                        @error="errors = $event"
                    >
                        <template #cell-status="{ row }"><StatusBadge :status="String(row.status)" /></template>
                    </ServerDataTable>
                </div>
            </section>

            <section v-else-if="view === 'payments'" class="page split">
                <div class="panel">
                    <div class="panel-head"><div><p class="section-kicker">Cobranza</p><h3>Registrar pago</h3></div></div>
                    <form class="form-stack" @submit.prevent="createPayment">
                        <label>ID de pedido<input v-model.number="paymentForm.order_id" required min="1" type="number"></label>
                        <label>Importe<input v-model="paymentForm.amount" required min="0.01" step="0.01" type="number"></label>
                        <label>Medio<select v-model="paymentForm.method"><option value="cash">Efectivo</option><option value="transfer">Transferencia</option><option value="mercadopago">Mercado Pago</option><option value="card">Tarjeta</option></select></label>
                        <label>Referencia<input v-model="paymentForm.external_reference" maxlength="255" placeholder="Opcional"></label>
                        <button class="primary" :disabled="busy || !online">Registrar pago</button>
                    </form>
                </div>
                <div class="panel full"><div class="panel-head"><h3>Pagos</h3></div>
                    <ServerDataTable
                        ref="operationalTable"
                        endpoint="/payments"
                        :columns="tableColumns"
                        :filters="tableFilters"
                        initial-sort="id"
                        persist-in-url
                        @loaded="handleTableLoaded"
                        @error="errors = $event"
                    />
                </div>
            </section>

            <section v-else class="page module-page">
                <div class="module-heading">
                    <div><p class="section-kicker">Sucursal · {{ session.branch }}</p><h2>{{ pageTitle }}</h2><p>{{ pageDescription }}</p></div>
                </div>
                <div class="operational-grid">
                    <div class="task-panel">
                    <form v-if="view === 'customers'" class="form-grid" @submit.prevent="createCustomer">
                        <h3 class="wide">Nuevo cliente</h3>
                        <label>Nombre<input v-model="customerForm.name" required maxlength="255"></label>
                        <label>Correo<input v-model="customerForm.email" type="email"></label>
                        <button class="primary wide" :disabled="busy || !online">Crear cliente</button>
                    </form>
                    <form v-if="view === 'products'" class="form-grid" @submit.prevent="createProduct">
                        <h3 class="wide">Nuevo producto</h3>
                        <label>Nombre<input v-model="productForm.name" required></label>
                        <label>Tipo<select v-model="productForm.type"><option value="raw_material">Materia prima</option><option value="semi_finished">Semielaborado</option><option value="finished_product">Terminado</option><option value="packaging">Empaque</option></select></label>
                        <label>Unidad<select v-model="productForm.unit"><option>unit</option><option>kg</option><option>g</option><option>l</option><option>ml</option></select></label>
                        <label>Stock mínimo<input v-model="productForm.minimum_stock" type="number" step="0.001" min="0"></label>
                        <label>Precio<input v-model="productForm.price" type="number" step="0.01" min="0"></label>
                        <button class="primary wide" :disabled="busy || !online">Crear producto</button>
                    </form>
                    <form v-if="view === 'lots'" class="form-grid" @submit.prevent="adjustLot">
                        <h3 class="wide">Registrar ingreso de lote</h3>
                        <label>Producto ID<input v-model.number="lotForm.product_id" required min="1" type="number"></label>
                        <label>Ubicación ID<input v-model.number="lotForm.location_id" required min="1" type="number"></label>
                        <label>Código<input v-model="lotForm.code" required></label>
                        <label>Unidad<select v-model="lotForm.unit"><option>unit</option><option>kg</option><option>g</option><option>l</option><option>ml</option></select></label>
                        <label>Cantidad<input v-model="lotForm.quantity" required type="number" step="0.001"></label>
                        <label>Vencimiento<input v-model="lotForm.expires_at" type="date"></label>
                        <button class="primary wide" :disabled="busy || !online">Registrar recepción</button>
                    </form>
                    <div v-if="view === 'production'" class="form-stack">
                        <form class="form-grid" @submit.prevent="createProduction">
                            <h3 class="wide">Crear orden de producción</h3>
                            <label>Pedido ID<input v-model.number="productionForm.order_id" required min="1" type="number"></label>
                            <label>Receta ID<input v-model.number="productionForm.recipe_id" required min="1" type="number"></label>
                            <label>Cantidad<input v-model="productionForm.planned_quantity" required type="number" step="0.001"></label>
                            <label>Unidad<select v-model="productionForm.unit"><option>unit</option><option>kg</option><option>g</option><option>l</option><option>ml</option></select></label>
                            <button class="primary wide" :disabled="busy || !online">Crear producción</button>
                        </form>
                        <form class="form-grid production-completion" @submit.prevent>
                            <h3 class="wide">Seguimiento y finalización</h3>
                            <label>Producción ID<input v-model.number="productionForm.id" required min="1" type="number"></label>
                            <label>Rendimiento<input v-model="productionForm.actual_yield" type="number" step="0.001"></label>
                            <label>Merma<input v-model="productionForm.waste_quantity" type="number" step="0.001"></label>
                            <label>Ubicación destino ID<input v-model.number="productionForm.destination_location_id" min="1" type="number"></label>
                            <label>Fecha de elaboración<input v-model="productionForm.manufactured_at" type="datetime-local"></label>
                            <label>Vencimiento<input v-model="productionForm.expires_at" type="date"></label>
                            <label class="wide">Observaciones<input v-model="productionForm.observations" maxlength="2000"></label>
                            <div class="wide action-row">
                                <button class="secondary" :disabled="busy || !online || !productionForm.id" type="button" @click="loadProductionDetail('requirements')">Ver requerimientos</button>
                                <button class="secondary" :disabled="busy || !online || !productionForm.id" type="button" @click="productionAction('start')">Iniciar</button>
                                <button class="primary" :disabled="busy || !online || !productionForm.id" type="button" @click="productionAction('complete')">Completar producción</button>
                            </div>
                        </form>
                        <div v-if="productionRequirements" class="detail-sheet">
                            <div class="panel-head"><h3>Requerimientos calculados</h3><StatusBadge :status="productionRequirements.can_produce ? 'ready' : 'blocked'" /></div>
                            <pre>{{ JSON.stringify(productionRequirements, null, 2) }}</pre>
                        </div>
                        <div class="action-row">
                            <button class="text-button" :disabled="!productionForm.id" type="button" @click="loadProductionDetail('traceability')">Consultar trazabilidad</button>
                        </div>
                        <div v-if="productionTraceability" class="detail-sheet">
                            <h3>Trazabilidad de producción</h3>
                            <pre>{{ JSON.stringify(productionTraceability, null, 2) }}</pre>
                        </div>
                    </div>
                    <form v-if="view === 'alerts'" class="form-grid" @submit.prevent="resolveAlert">
                        <h3 class="wide">Resolver alerta</h3>
                        <label>Alerta ID<input v-model.number="alertForm.id" required min="1" type="number"></label>
                        <button class="primary align-end" :disabled="busy || !online">Marcar como resuelta</button>
                    </form>
                    </div>
                    <div class="data-panel">
                    <ServerDataTable
                        :key="tableEndpoint"
                        ref="operationalTable"
                        :endpoint="tableEndpoint"
                        :columns="tableColumns"
                        :filters="tableFilters"
                        initial-sort="id"
                        persist-in-url
                        @loaded="handleTableLoaded"
                        @error="errors = $event"
                    >
                        <template #cell-status="{ row }"><StatusBadge :status="String(row.status ?? 'active')" /></template>
                    </ServerDataTable>
                    </div>
                </div>
            </section>
            <nav class="mobile-navigation" aria-label="Navegación móvil">
                <button v-for="item in navigation" :key="item.id" :class="{ active: view === item.id }" :aria-current="view === item.id ? 'page' : undefined" @click="navigate(item.id)">
                    <span aria-hidden="true">{{ item.icon }}</span>{{ item.label }}
                </button>
            </nav>
        </main>
    </div>
</template>
