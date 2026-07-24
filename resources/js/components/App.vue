<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, errorMessages, idempotencyKey } from '../api';
import { useSessionStore } from '../stores/session';
import type { CreateOrderInput, Order, OrderStatus, Payment, View } from '../types';

const session = useSessionStore();
const view = ref<View>('dashboard');
const online = ref(navigator.onLine);
const busy = ref(false);
const notice = ref('');
const errors = ref<string[]>([]);
const recentOrders = ref<Order[]>([]);
const recentPayments = ref<Payment[]>([]);
const moduleRows = ref<Record<string, unknown>[]>([]);
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
let orderKey = idempotencyKey('order');
let paymentKey = idempotencyKey('payment');
let transitionKey = idempotencyKey('transition');

const navigation: Array<{ id: View; label: string; icon: string }> = [
    { id: 'dashboard', label: 'Resumen', icon: '⌂' },
    { id: 'customers', label: 'Clientes', icon: 'C' },
    { id: 'products', label: 'Productos', icon: 'P' },
    { id: 'lots', label: 'Lotes', icon: 'L' },
    { id: 'orders', label: 'Pedidos', icon: 'O' },
    { id: 'production', label: 'Producción', icon: 'R' },
    { id: 'payments', label: 'Pagos', icon: '$' },
    { id: 'alerts', label: 'Alertas', icon: '!' },
];

const pageTitle = computed(() => navigation.find((item) => item.id === view.value)?.label ?? 'Resumen');
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
    void loadView(target);
}

async function loadView(target: View) {
    if (!session.authenticated || !online.value) return;
    const endpoints: Partial<Record<View, string>> = {
        customers: '/customers',
        products: '/products',
        lots: '/lots',
        orders: '/orders',
        production: '/production-orders',
        payments: '/payments',
        alerts: '/alerts',
    };
    const endpoint = endpoints[target];
    if (!endpoint) return;
    await run(async () => {
        const rows = await api.get<Record<string, unknown>[]>(endpoint);
        moduleRows.value = rows;
        if (target === 'orders') recentOrders.value = rows as unknown as Order[];
        if (target === 'payments') recentPayments.value = rows as unknown as Payment[];
    });
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
    notice.value = '';
    try {
        await action();
    } catch (error) {
        errors.value = errorMessages(error);
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
    });
}

function transitionOrder() {
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

onMounted(async () => {
    const hash = location.hash.slice(1) as View;
    if (navigation.some((item) => item.id === hash)) view.value = hash;
    addEventListener('online', setConnection);
    addEventListener('offline', setConnection);
    await session.recover();
    await loadView(view.value);
});
onBeforeUnmount(() => {
    removeEventListener('online', setConnection);
    removeEventListener('offline', setConnection);
});
</script>

<template>
    <main v-if="!session.authenticated" class="login-page">
        <section class="login-card">
            <p class="brand-mark">LV</p>
            <p class="eyebrow">LA VICTORIA · GESTIÓN</p>
            <h1>Bienvenido a la mesa de operaciones.</h1>
            <p class="muted">Ingresá para trabajar con pedidos, producción, inventario y cobranzas.</p>
            <form class="form-stack" @submit.prevent="logIn">
                <label>Correo electrónico
                    <input v-model="loginForm.email" required autocomplete="username" type="email">
                </label>
                <label>Contraseña
                    <input v-model="loginForm.password" required autocomplete="current-password" type="password">
                </label>
                <button class="primary" :disabled="busy || !online" type="submit">{{ busy ? 'Ingresando…' : 'Ingresar' }}</button>
            </form>
            <p class="helper">Usá un usuario generado por el seeder de demostración.</p>
        </section>
    </main>

    <div v-else class="app-layout">
        <aside class="sidebar">
            <div class="brand"><span>LV</span><div>La Victoria<small>Catering</small></div></div>
            <nav aria-label="Navegación principal">
                <button
                    v-for="item in navigation"
                    :key="item.id"
                    :class="{ active: view === item.id }"
                    @click="navigate(item.id)"
                ><span class="nav-icon">{{ item.icon }}</span>{{ item.label }}</button>
            </nav>
            <div class="sidebar-foot">
                <span class="connection" :class="{ offline: !online }">{{ online ? 'Conectado' : 'Sin conexión' }}</span>
                <button class="logout" @click="session.logout()">Cerrar sesión</button>
            </div>
        </aside>

        <div class="workspace">
            <header class="topbar">
                <div><p class="eyebrow">OPERACIONES</p><h1>{{ pageTitle }}</h1></div>
                <div class="top-actions">
                    <label class="branch">Sucursal
                        <select :value="session.branchId" @change="session.selectTenant(Number(session.organizationId), Number(($event.target as HTMLSelectElement).value))">
                            <option v-for="branch in session.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </label>
                    <span class="avatar">{{ session.name.slice(0, 2).toUpperCase() }}</span>
                </div>
            </header>

            <section v-if="!online" class="offline-banner" role="status">
                Estás sin conexión. Podés consultar esta pantalla, pero no enviar operaciones.
            </section>
            <section v-if="errors.length" class="message error" role="alert">
                <strong>No pudimos completar la operación</strong>
                <ul><li v-for="error in errors" :key="error">{{ error }}</li></ul>
            </section>
            <p v-if="notice" class="message success" role="status">{{ notice }}</p>

            <section v-if="view === 'dashboard'" class="page">
                <div class="hero">
                    <div><p class="eyebrow">HOY · {{ session.branch.toUpperCase() }}</p><h2>La operación, clara de punta a punta.</h2>
                    <p>Un punto de control para actuar sobre pedidos y cobranzas sin perder trazabilidad.</p></div>
                    <button class="primary" :disabled="!online" @click="navigate('orders')">Nuevo pedido</button>
                </div>
                <div class="metric-grid">
                    <article><span>Pedidos en sesión</span><strong>{{ recentOrders.length }}</strong><small>Creados desde este dispositivo</small></article>
                    <article><span>En producción</span><strong>{{ recentOrders.filter(o => o.status === 'in_production').length }}</strong><small>Seguimiento activo</small></article>
                    <article><span>Saldo pendiente</span><strong>{{ money(outstanding) }}</strong><small>Pedidos visibles en sesión</small></article>
                    <article><span>Alertas abiertas</span><strong>—</strong><small>Endpoint pendiente</small></article>
                </div>
                <div class="panel">
                    <div class="panel-head"><div><p class="eyebrow">ACTIVIDAD</p><h3>Pedidos recientes</h3></div><button class="text-button" @click="navigate('orders')">Ver pedidos</button></div>
                    <div v-if="recentOrders.length" class="table-wrap"><table><thead><tr><th>Pedido</th><th>Cliente</th><th>Estado</th><th>Total</th></tr></thead>
                        <tbody><tr v-for="order in recentOrders.slice(0, 5)" :key="order.id"><td>#{{ order.id }}</td><td>{{ order.customer_name }}</td><td><span class="badge">{{ statusLabel(order.status) }}</span></td><td>{{ money(order.total) }}</td></tr></tbody></table></div>
                    <p v-else class="empty">Todavía no creaste pedidos en esta sesión.</p>
                </div>
            </section>

            <section v-else-if="view === 'orders'" class="page split">
                <div class="panel">
                    <div class="panel-head"><div><p class="eyebrow">ALTA</p><h3>Nuevo pedido</h3></div></div>
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
                    <div class="panel-head"><div><p class="eyebrow">FLUJO</p><h3>Cambiar estado</h3></div></div>
                    <form class="form-stack" @submit.prevent="transitionOrder">
                        <label>ID de pedido<input v-model.number="transitionForm.order_id" required min="1" type="number"></label>
                        <label>Nuevo estado<select v-model="transitionForm.status"><option value="confirmed">Confirmado</option><option value="in_production">En producción</option><option value="ready">Listo</option><option value="delivered">Entregado</option><option value="cancelled">Cancelado</option></select></label>
                        <button class="secondary" :disabled="busy || !online">Actualizar estado</button>
                    </form>
                </div>
                <div class="panel full">
                    <div class="panel-head"><h3>Pedidos de esta sesión</h3></div>
                    <div v-if="recentOrders.length" class="table-wrap"><table><thead><tr><th>ID</th><th>Cliente</th><th>Estado</th><th>Total</th><th>Pagado</th></tr></thead><tbody>
                        <tr v-for="order in recentOrders" :key="order.id"><td>#{{ order.id }}</td><td>{{ order.customer_name }}</td><td><span class="badge">{{ statusLabel(order.status) }}</span></td><td>{{ money(order.total) }}</td><td>{{ money(order.paid_total) }}</td></tr>
                    </tbody></table></div><p v-else class="empty">Los pedidos creados aparecerán acá.</p>
                </div>
            </section>

            <section v-else-if="view === 'payments'" class="page split">
                <div class="panel">
                    <div class="panel-head"><div><p class="eyebrow">COBRANZAS</p><h3>Registrar pago</h3></div></div>
                    <form class="form-stack" @submit.prevent="createPayment">
                        <label>ID de pedido<input v-model.number="paymentForm.order_id" required min="1" type="number"></label>
                        <label>Importe<input v-model="paymentForm.amount" required min="0.01" step="0.01" type="number"></label>
                        <label>Medio<select v-model="paymentForm.method"><option value="cash">Efectivo</option><option value="transfer">Transferencia</option><option value="mercadopago">Mercado Pago</option><option value="card">Tarjeta</option></select></label>
                        <label>Referencia<input v-model="paymentForm.external_reference" maxlength="255" placeholder="Opcional"></label>
                        <button class="primary" :disabled="busy || !online">Registrar pago</button>
                    </form>
                </div>
                <div class="panel"><div class="panel-head"><h3>Pagos de esta sesión</h3></div>
                    <ul v-if="recentPayments.length" class="activity-list"><li v-for="payment in recentPayments" :key="payment.id"><div><strong>{{ money(payment.amount) }}</strong><span>Pedido #{{ payment.order_id }} · {{ payment.method }}</span></div><small>#{{ payment.id }}</small></li></ul>
                    <p v-else class="empty">Todavía no registraste pagos.</p>
                </div>
            </section>

            <section v-else class="page">
                <div class="coming-soon">
                    <span class="module-letter">{{ navigation.find(n => n.id === view)?.icon }}</span>
                    <p class="eyebrow">DATOS DEL SERVIDOR</p>
                    <h2>{{ pageTitle }}</h2>
                    <p v-if="!moduleRows.length">No hay registros para esta sucursal.</p>
                    <div v-else class="table-wrap">
                        <table>
                            <thead><tr><th>ID</th><th>Nombre / evento</th><th>Estado / tipo</th></tr></thead>
                            <tbody>
                                <tr v-for="row in moduleRows" :key="String(row.id)">
                                    <td>#{{ row.id }}</td>
                                    <td>{{ row.name ?? row.event ?? row.code ?? `Registro ${row.id}` }}</td>
                                    <td>{{ row.status ?? row.type ?? 'activo' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button class="secondary" :disabled="busy" @click="loadView(view)">Actualizar</button>
                </div>
            </section>
        </div>
    </div>
</template>
