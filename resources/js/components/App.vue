<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, errorMessages, HttpError } from '../api';
import brandLogo from '../assets/brand/la-victoria-bakery-logo.png';
import { useSessionStore } from '../stores/session';
import type { Order, Payment, View } from '../types';
import DataState from './ui/DataState.vue';
import MetricBlock from './ui/MetricBlock.vue';
import OperationalPage from './OperationalPage.vue';
import StatusBadge from './ui/StatusBadge.vue';

const session = useSessionStore();
const validViews: View[] = ['dashboard', 'customers', 'products', 'locations', 'lots', 'orders', 'recipes', 'production', 'payments', 'alerts'];
const initialHash = location.hash.replace('#', '') as View;
const view = ref<View>(validViews.includes(initialHash) ? initialHash : 'dashboard');
const online = ref(navigator.onLine);
const busy = ref(false);
const recoveringSession = ref(true);
const notice = ref('');
const errors = ref<string[]>([]);
const errorTitle = ref('No pudimos completar la operación');
const recentOrders = ref<Order[]>([]);
const recentPayments = ref<Payment[]>([]);
const dashboardProductions = ref<Record<string, any>[]>([]);
const dashboardAlerts = ref<Record<string, any>[]>([]);
const mobileMenuOpen = ref(false);
const loginForm = reactive({ email: 'admin@lavictoria.test', password: '' });
let loadSequence = 0;

const navigation: Array<{ id: View; label: string; icon: string; group: string }> = [
    { id: 'dashboard', label: 'Resumen', icon: 'HO', group: 'Jornada' },
    { id: 'orders', label: 'Pedidos', icon: 'PE', group: 'Comercial' },
    { id: 'customers', label: 'Clientes', icon: 'CL', group: 'Comercial' },
    { id: 'payments', label: 'Cobranzas', icon: '$', group: 'Comercial' },
    { id: 'production', label: 'Producción', icon: 'OP', group: 'Obrador' },
    { id: 'recipes', label: 'Recetas', icon: 'RE', group: 'Obrador' },
    { id: 'products', label: 'Productos', icon: 'PR', group: 'Inventario' },
    { id: 'lots', label: 'Lotes', icon: 'LO', group: 'Inventario' },
    { id: 'locations', label: 'Ubicaciones', icon: 'UB', group: 'Inventario' },
    { id: 'alerts', label: 'Alertas', icon: '!', group: 'Control' },
];
const groups = ['Jornada', 'Comercial', 'Obrador', 'Inventario', 'Control'];
const primaryMobile: View[] = ['dashboard', 'orders', 'production', 'alerts'];

const pageTitle = computed(() => navigation.find((item) => item.id === view.value)?.label ?? 'Resumen');
const activeRole = computed(() => session.user?.organizations.find(
    (organization) => organization.id === session.organizationId,
)?.pivot.role ?? '');
const pageDescription = computed(() => ({
    dashboard: 'Prioridades y actividad de la sucursal activa.',
    customers: 'Relación comercial y datos de contacto.',
    products: 'Catálogo, unidades y niveles mínimos.',
    locations: 'Depósitos y sectores de la sucursal.',
    lots: 'Existencias, reservas y vencimientos.',
    orders: 'Alta, seguimiento y entrega de pedidos.',
    recipes: 'Versiones e ingredientes de elaboración.',
    production: 'Órdenes, rendimiento, merma y trazabilidad.',
    payments: 'Registro seguro y seguimiento de cobranzas.',
    alerts: 'Situaciones que requieren revisión o acción.',
})[view.value]);
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
    mobileMenuOpen.value = false;
    const url = new URL(location.href);
    url.hash = target === 'dashboard' ? '' : target;
    history.replaceState(null, '', url);
    if (target === 'dashboard') void loadDashboard();
}

async function loadDashboard() {
    if (!session.authenticated || !online.value) return;
    const sequence = ++loadSequence;
    await run(async () => {
        const [orders, payments, productions, alerts] = await Promise.all([
            api.get<Order[]>('/orders'),
            api.get<Payment[]>('/payments'),
            api.get<Record<string, any>[]>('/production-orders'),
            api.get<Record<string, any>[]>('/alerts'),
        ]);
        if (sequence !== loadSequence || view.value !== 'dashboard') return;
        recentOrders.value = orders;
        recentPayments.value = payments;
        dashboardProductions.value = productions;
        dashboardAlerts.value = alerts;
    });
}

async function changeBranch(event: Event) {
    const selectedBranch = Number((event.target as HTMLSelectElement).value);
    session.selectTenant(Number(session.organizationId), selectedBranch);
    loadSequence++;
    recentOrders.value = [];
    recentPayments.value = [];
    dashboardProductions.value = [];
    dashboardAlerts.value = [];
    notice.value = `Sucursal activa: ${session.branch}.`;
    if (view.value === 'dashboard') await loadDashboard();
}

function logIn() {
    return run(async () => {
        await session.login(loginForm.email, loginForm.password);
        await loadDashboard();
    });
}

async function logOut() {
    await run(async () => {
        await session.logout();
        view.value = 'dashboard';
    });
}

async function run(action: () => Promise<void>) {
    if (!online.value) {
        showError('Sin conexión', ['La operación no se envió. Recuperá conexión para continuar.']);
        return;
    }
    busy.value = true;
    errors.value = [];
    notice.value = '';
    try {
        await action();
    } catch (error) {
        const title = error instanceof HttpError
            ? ({
                0: 'Resultado desconocido',
                401: 'Tu sesión venció',
                403: 'No tenés permisos para esta acción',
                409: 'La información cambió',
                422: 'Revisá los datos ingresados',
            } as Record<number, string>)[error.status] ?? 'No pudimos completar la operación'
            : 'No pudimos completar la operación';
        showError(title, errorMessages(error));
    } finally {
        busy.value = false;
    }
}

function showError(title: string, messages: string[]) {
    errorTitle.value = title;
    errors.value = messages;
    notice.value = '';
}

function showNotice(message: string) {
    notice.value = message;
    errors.value = [];
}

function money(value: string) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(Number(value));
}

function decimalToCents(value: string) {
    const [whole = '0', fraction = ''] = value.split('.');
    return BigInt(whole || '0') * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2));
}

function centsToDecimal(value: bigint) {
    const sign = value < 0 ? '-' : '';
    const absolute = value < 0 ? -value : value;
    return `${sign}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
}

function formatDate(value: string | null | undefined) {
    if (!value) return 'Sin fecha';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('es-AR', { dateStyle: 'short' }).format(date);
}

onMounted(async () => {
    addEventListener('online', setConnection);
    addEventListener('offline', setConnection);
    try {
        await session.recover();
        if (session.authenticated && view.value === 'dashboard') await loadDashboard();
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
            <div class="brand">
                <img class="brand-logo" :src="brandLogo" alt="">
                <div>La Victoria<small>Bakery · Operaciones</small></div>
            </div>
            <nav aria-label="Navegación principal">
                <section v-for="group in groups" :key="group" class="nav-group">
                    <p>{{ group }}</p>
                    <button
                        v-for="item in navigation.filter((entry) => entry.group === group)"
                        :key="item.id"
                        :class="{ active: view === item.id }"
                        :aria-current="view === item.id ? 'page' : undefined"
                        @click="navigate(item.id)"
                    ><span aria-hidden="true" class="nav-icon">{{ item.icon }}</span>{{ item.label }}</button>
                </section>
            </nav>
            <div class="sidebar-foot">
                <span class="connection" :class="{ offline: !online }">{{ online ? 'Conectado' : 'Sin conexión' }}</span>
                <button class="logout" @click="logOut">Cerrar sesión</button>
            </div>
        </aside>

        <main class="workspace">
            <header class="topbar">
                <div>
                    <p class="section-kicker">Mesa operativa</p>
                    <h1>{{ pageTitle }}</h1>
                    <p class="page-description">{{ pageDescription }}</p>
                </div>
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

            <section v-if="view === 'dashboard'" class="page dashboard-page">
                <div class="hero">
                    <div><p class="section-kicker">Hoy · {{ session.branch }}</p><h2>La jornada, en orden.</h2>
                    <p>Revisá primero las excepciones y continuá cada tarea desde su registro.</p></div>
                    <button class="primary" :disabled="!online" @click="navigate('orders')">Ir a pedidos</button>
                </div>
                <div class="metric-grid">
                    <MetricBlock label="Pedidos visibles" :value="recentOrders.length" detail="En la sucursal activa" action="Abrir pedidos" @activate="navigate('orders')" />
                    <MetricBlock label="Producción pendiente" :value="dashboardProductions.filter(p => p.status === 'planned' || p.status === 'in_progress').length" detail="Órdenes por completar" action="Abrir producción" @activate="navigate('production')" />
                    <MetricBlock label="Saldo pendiente" :value="money(outstanding)" detail="Sobre pedidos cargados" action="Abrir cobranzas" @activate="navigate('payments')" />
                    <MetricBlock label="Alertas abiertas" :value="dashboardAlerts.filter(a => a.status !== 'resolved').length" detail="Requieren revisión" action="Abrir alertas" @activate="navigate('alerts')" />
                </div>
                <div class="dashboard-grid">
                    <section class="panel priority-panel">
                        <div class="panel-head"><div><p class="section-kicker">Atención</p><h3>Alertas abiertas</h3></div><button class="text-button" @click="navigate('alerts')">Ver todas</button></div>
                        <ol v-if="dashboardAlerts.filter(a => a.status !== 'resolved').length" class="priority-list">
                            <li v-for="alert in dashboardAlerts.filter(a => a.status !== 'resolved').slice(0, 5)" :key="alert.id">
                                <StatusBadge :status="alert.severity" />
                                <div><strong>{{ alert.event }}</strong><p>{{ alert.action }}</p></div>
                            </li>
                        </ol>
                        <DataState v-else title="No hay alertas abiertas" message="La sucursal no tiene excepciones activas en este momento." />
                    </section>
                    <section class="panel">
                        <div class="panel-head"><div><p class="section-kicker">Actividad</p><h3>Pedidos próximos</h3></div><button class="text-button" @click="navigate('orders')">Ver todos</button></div>
                        <ol v-if="recentOrders.length" class="order-list">
                            <li v-for="order in recentOrders.slice(0, 5)" :key="order.id">
                                <div><strong>#{{ order.id }} · {{ order.customer_name }}</strong><span>{{ formatDate(order.required_at) }}</span></div>
                                <StatusBadge :status="order.status" />
                                <span class="numeric">{{ money(order.total) }}</span>
                            </li>
                        </ol>
                        <DataState v-else title="No hay pedidos para mostrar" message="Creá un pedido para comenzar la jornada." action="Abrir pedidos" @activate="navigate('orders')" />
                    </section>
                </div>
            </section>

            <OperationalPage
                v-else
                :key="`${view}-${session.branchId}`"
                :view="view"
                :online="online"
                :branch-name="session.branch"
                :role="activeRole"
                @notice="showNotice"
                @error="showError"
            />

            <nav class="mobile-navigation" aria-label="Navegación móvil">
                <button v-for="item in navigation.filter(entry => primaryMobile.includes(entry.id))" :key="item.id" :class="{ active: view === item.id }" :aria-current="view === item.id ? 'page' : undefined" @click="navigate(item.id)">
                    <span aria-hidden="true">{{ item.icon }}</span>{{ item.label }}
                </button>
                <button :class="{ active: mobileMenuOpen }" :aria-expanded="mobileMenuOpen" @click="mobileMenuOpen = !mobileMenuOpen"><span aria-hidden="true">＋</span>Más</button>
            </nav>
            <div v-if="mobileMenuOpen" class="mobile-more-menu">
                <div class="mobile-more-sheet">
                    <div class="panel-head"><h2>Más módulos</h2><button class="modal-close" aria-label="Cerrar menú" @click="mobileMenuOpen = false">×</button></div>
                    <button v-for="item in navigation.filter(entry => !primaryMobile.includes(entry.id))" :key="item.id" :class="{ active: view === item.id }" @click="navigate(item.id)">
                        <span class="nav-icon" aria-hidden="true">{{ item.icon }}</span>{{ item.label }}
                    </button>
                </div>
            </div>
        </main>
    </div>
</template>
