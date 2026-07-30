<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api, errorMessages, HttpError } from '../api';
import brandLogo from '../assets/brand/la-victoria-bakery-logo.png';
import { formatDateTime } from '../dates';
import { useSessionStore } from '../stores/session';
import type { DashboardAlert, DashboardMetrics, DashboardSummary, Order, View } from '../types';
import AuthPage from './AuthPage.vue';
import DataState from './ui/DataState.vue';
import BaseModal from './ui/BaseModal.vue';
import MetricBlock from './ui/MetricBlock.vue';
import OperationalPage from './OperationalPage.vue';
import ProcurementPage from './ProcurementPage.vue';
import FinancialPage from './FinancialPage.vue';
import ManualReviewPage from './ManualReviewPage.vue';
import ImportPage from './ImportPage.vue';
import NotificationPage from './NotificationPage.vue';
import StatusBadge from './ui/StatusBadge.vue';

const session = useSessionStore();
const validViews: View[] = ['dashboard', 'customers', 'products', 'locations', 'lots', 'orders', 'recipes', 'production', 'payments', 'procurement', 'finance', 'review', 'imports', 'notifications', 'alerts'];
const initialHash = location.hash.replace('#', '') as View;
const view = ref<View>(validViews.includes(initialHash) ? initialHash : 'dashboard');
const online = ref(navigator.onLine);
const busy = ref(false);
const recoveringSession = ref(true);
const notice = ref('');
const errors = ref<string[]>([]);
const errorTitle = ref('No pudimos completar la operación');
const recentOrders = ref<Order[]>([]);
const dashboardAlerts = ref<DashboardAlert[]>([]);
const dashboardMetrics = ref<DashboardMetrics>({
    orders_total: 0,
    orders_today: 0,
    overdue_orders: 0,
    pending_production: 0,
    outstanding_balance: '0.00',
    open_alerts: 0,
    critical_stock: 0,
});
const mobileMenuOpen = ref(false);
let loadSequence = 0;

const navigation: Array<{ id: View; label: string; icon: string; group: string }> = [
    { id: 'dashboard', label: 'Resumen', icon: 'HO', group: 'Jornada' },
    { id: 'orders', label: 'Pedidos', icon: 'PE', group: 'Comercial' },
    { id: 'customers', label: 'Clientes', icon: 'CL', group: 'Comercial' },
    { id: 'payments', label: 'Cobranzas', icon: '$', group: 'Comercial' },
    { id: 'finance', label: 'Caja y finanzas', icon: 'CF', group: 'Comercial' },
    { id: 'production', label: 'Producción', icon: 'OP', group: 'Obrador' },
    { id: 'recipes', label: 'Recetas', icon: 'RE', group: 'Obrador' },
    { id: 'products', label: 'Productos', icon: 'PR', group: 'Inventario' },
    { id: 'lots', label: 'Lotes', icon: 'LO', group: 'Inventario' },
    { id: 'locations', label: 'Ubicaciones', icon: 'UB', group: 'Inventario' },
    { id: 'procurement', label: 'Compras', icon: 'OC', group: 'Inventario' },
    { id: 'alerts', label: 'Alertas', icon: '!', group: 'Control' },
    { id: 'review', label: 'Revisión', icon: 'RV', group: 'Control' },
    { id: 'imports', label: 'Importar', icon: 'IM', group: 'Control' },
    { id: 'notifications', label: 'Notificaciones', icon: 'NO', group: 'Control' },
];
const groups = ['Jornada', 'Comercial', 'Obrador', 'Inventario', 'Control'];
const primaryMobile: View[] = ['dashboard', 'orders', 'production', 'alerts'];

const pageTitle = computed(() => navigation.find((item) => item.id === view.value)?.label ?? 'Resumen');
const activeRole = computed(() => session.user?.organizations.find(
    (organization) => organization.id === session.organizationId,
)?.pivot.role ?? '');
const visibleNavigation = computed(() => navigation.filter((item) => {
    if (item.id === 'customers') return ['owner', 'admin', 'sales', 'finance'].includes(activeRole.value);
    if (item.id === 'payments') return ['owner', 'admin', 'sales', 'finance'].includes(activeRole.value);
    if (item.id === 'procurement') {
        return ['owner', 'admin', 'purchasing', 'inventory', 'production', 'finance'].includes(activeRole.value);
    }
    if (item.id === 'finance') return ['owner', 'admin', 'sales', 'finance'].includes(activeRole.value);
    if (item.id === 'review' || item.id === 'imports') return ['owner', 'admin'].includes(activeRole.value);
    return true;
}));
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
    procurement: 'Proveedores, órdenes de compra y recepción trazable.',
    finance: 'Caja, cuentas corrientes, obligaciones y conciliación.',
    alerts: 'Situaciones que requieren revisión o acción.',
    review: 'Fallos y evidencia que requieren una decisión auditada.',
    imports: 'Carga inicial validada con vista previa y confirmación en cola.',
    notifications: 'Mensajes internos, entregas y preferencias personales.',
})[view.value]);
function setConnection() {
    online.value = navigator.onLine;
}

function navigate(target: View, updateHistory = true) {
    if (!visibleNavigation.value.some((item) => item.id === target)) target = 'dashboard';
    const previous = view.value;
    view.value = target;
    errors.value = [];
    notice.value = '';
    mobileMenuOpen.value = false;
    const url = new URL(location.href);
    url.hash = target === 'dashboard' ? '' : target;
    if (updateHistory && previous !== target) history.pushState(null, '', url);
    else history.replaceState(null, '', url);
    if (target === 'dashboard') void loadDashboard();
}

function restoreHistoryView() {
    if (!session.authenticated) return;
    const target = location.hash.replace('#', '') as View;
    navigate(validViews.includes(target) ? target : 'dashboard', false);
}

async function loadDashboard() {
    if (!session.authenticated || !online.value) return;
    const sequence = ++loadSequence;
    await run(async () => {
        const summary = await api.get<DashboardSummary>('/dashboard/summary');
        if (sequence !== loadSequence || view.value !== 'dashboard') return;
        recentOrders.value = summary.recent_orders;
        dashboardAlerts.value = summary.open_alerts;
        dashboardMetrics.value = summary.metrics;
    });
}

async function changeBranch(event: Event) {
    const selectedBranch = Number((event.target as HTMLSelectElement).value);
    session.selectTenant(Number(session.organizationId), selectedBranch);
    loadSequence++;
    recentOrders.value = [];
    dashboardAlerts.value = [];
    dashboardMetrics.value = {
        orders_total: 0,
        orders_today: 0,
        overdue_orders: 0,
        pending_production: 0,
        outstanding_balance: '0.00',
        open_alerts: 0,
        critical_stock: 0,
    };
    notice.value = `Sucursal activa: ${session.branch}.`;
    if (view.value === 'dashboard') await loadDashboard();
}

async function handleAuthenticated() {
    errors.value = [];
    notice.value = '';
    if (!visibleNavigation.value.some((item) => item.id === view.value)) navigate('dashboard');
    await loadDashboard();
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

onMounted(async () => {
    addEventListener('online', setConnection);
    addEventListener('offline', setConnection);
    addEventListener('popstate', restoreHistoryView);
    try {
        await session.recover();
        if (!visibleNavigation.value.some((item) => item.id === view.value)) navigate('dashboard');
        if (session.authenticated && view.value === 'dashboard') await loadDashboard();
    } catch (error) {
        showError('No pudimos recuperar la sesión', errorMessages(error));
    } finally {
        recoveringSession.value = false;
    }
});

onBeforeUnmount(() => {
    removeEventListener('online', setConnection);
    removeEventListener('offline', setConnection);
    removeEventListener('popstate', restoreHistoryView);
});
</script>

<template>
    <main v-if="recoveringSession" class="session-recovery" aria-live="polite">
        <img class="recovery-logo" :src="brandLogo" alt="">
        <strong>Recuperando tu mesa de trabajo…</strong>
        <p>Estamos comprobando la sesión y la sucursal activa.</p>
    </main>

    <AuthPage v-else-if="!session.authenticated" :online="online" @authenticated="handleAuthenticated" />

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
                        v-for="item in visibleNavigation.filter((entry) => entry.group === group)"
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
                    <MetricBlock label="Pedidos de la sucursal" :value="dashboardMetrics.orders_total" :detail="`${dashboardMetrics.orders_today} cargados hoy`" action="Abrir pedidos" @activate="navigate('orders')" />
                    <MetricBlock label="Producción pendiente" :value="dashboardMetrics.pending_production" detail="Órdenes por completar" action="Abrir producción" @activate="navigate('production')" />
                    <MetricBlock label="Saldo pendiente" :value="money(dashboardMetrics.outstanding_balance)" detail="Sobre todos los pedidos" action="Abrir cobranzas" @activate="navigate('payments')" />
                    <MetricBlock label="Alertas abiertas" :value="dashboardMetrics.open_alerts" :detail="`${dashboardMetrics.critical_stock} productos con stock crítico`" action="Abrir alertas" @activate="navigate('alerts')" />
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
                                <div><strong>#{{ order.id }} · {{ order.customer_name }}</strong><span>{{ formatDateTime(order.required_at) }}</span></div>
                                <StatusBadge :status="order.status" />
                                <span class="numeric">{{ money(order.total) }}</span>
                            </li>
                        </ol>
                        <DataState v-else title="No hay pedidos para mostrar" message="Creá un pedido para comenzar la jornada." action="Abrir pedidos" @activate="navigate('orders')" />
                    </section>
                </div>
            </section>

            <ProcurementPage
                v-else-if="view === 'procurement'"
                :key="`procurement-${session.branchId}`"
                :online="online"
                :branch-name="session.branch"
                :role="activeRole"
                @notice="showNotice"
                @error="showError"
            />

            <FinancialPage
                v-else-if="view === 'finance'"
                :key="`finance-${session.branchId}`"
                :online="online"
                :branch-name="session.branch"
                :role="activeRole"
                @notice="showNotice"
                @error="showError"
            />

            <NotificationPage
                v-else-if="view === 'notifications'"
                :key="`notifications-${session.branchId}`"
                :online="online"
                :branch-name="session.branch"
                @notice="showNotice"
                @error="showError"
            />

            <ManualReviewPage
                v-else-if="view === 'review'"
                :key="`review-${session.branchId}`"
                :online="online"
                :branch-name="session.branch"
                @notice="showNotice"
                @error="showError"
            />

            <ImportPage
                v-else-if="view === 'imports'"
                :key="`imports-${session.branchId}`"
                :online="online"
                :branch-name="session.branch"
                @notice="showNotice"
                @error="showError"
            />

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
                <button v-for="item in visibleNavigation.filter(entry => primaryMobile.includes(entry.id))" :key="item.id" :class="{ active: view === item.id }" :aria-current="view === item.id ? 'page' : undefined" @click="navigate(item.id)">
                    <span aria-hidden="true">{{ item.icon }}</span>{{ item.label }}
                </button>
                <button :class="{ active: mobileMenuOpen }" :aria-expanded="mobileMenuOpen" aria-haspopup="dialog" @click="mobileMenuOpen = !mobileMenuOpen"><span aria-hidden="true">＋</span>Más</button>
            </nav>
            <BaseModal
                :open="mobileMenuOpen"
                title="Más módulos"
                description="Elegí otra sección de la mesa operativa."
                @close="mobileMenuOpen = false"
            >
                <nav class="mobile-more-sheet" aria-label="Más módulos">
                    <button v-for="item in visibleNavigation.filter(entry => !primaryMobile.includes(entry.id))" :key="item.id" :class="{ active: view === item.id }" @click="navigate(item.id)">
                        <span class="nav-icon" aria-hidden="true">{{ item.icon }}</span>{{ item.label }}
                    </button>
                </nav>
            </BaseModal>
        </main>
    </div>
</template>
