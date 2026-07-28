<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, errorMessages, HttpError } from '../api';
import brandLogo from '../assets/brand/la-victoria-bakery-logo.png';
import { useSessionStore } from '../stores/session';

type AuthMode = 'login' | 'forgot' | 'reset';

defineProps<{ online: boolean }>();
const emit = defineEmits<{ authenticated: [] }>();
const session = useSessionStore();
const mode = ref<AuthMode>('login');
const busy = ref(false);
const errorTitle = ref('');
const errors = ref<string[]>([]);
const fieldErrors = ref<Record<string, string[]>>({});
const notice = ref('');
const resetToken = ref('');
const showLoginPassword = ref(false);
const showResetPassword = ref(false);
const showResetConfirmation = ref(false);
const loginForm = reactive({ email: 'admin@lavictoria.test', password: '' });
const forgotForm = reactive({ email: 'admin@lavictoria.test' });
const resetForm = reactive({
    email: '',
    password: '',
    password_confirmation: '',
});

function readLocation() {
    if (location.pathname.startsWith('/reset-password/')) {
        mode.value = 'reset';
        resetToken.value = decodeURIComponent(location.pathname.slice('/reset-password/'.length));
        resetForm.email = new URLSearchParams(location.search).get('email') ?? '';
        return;
    }
    mode.value = location.pathname === '/forgot-password' ? 'forgot' : 'login';
}

function changeMode(nextMode: Exclude<AuthMode, 'reset'>, replace = false) {
    const previousMode = mode.value;
    mode.value = nextMode;
    errors.value = [];
    fieldErrors.value = {};
    notice.value = '';
    if (nextMode === 'forgot') {
        forgotForm.email = previousMode === 'reset' ? resetForm.email : loginForm.email;
    }
    const path = nextMode === 'forgot' ? '/forgot-password' : '/';
    history[replace ? 'replaceState' : 'pushState'](null, '', path);
}

async function execute(action: () => Promise<void>) {
    busy.value = true;
    errors.value = [];
    fieldErrors.value = {};
    notice.value = '';
    try {
        await action();
    } catch (error) {
        fieldErrors.value = error instanceof HttpError ? error.errors : {};
        errorTitle.value = error instanceof HttpError
            ? ({
                0: 'No pudimos conectar con el servidor',
                422: 'Revisá los datos ingresados',
                429: 'Demasiados intentos',
            } as Record<number, string>)[error.status] ?? 'No pudimos completar la solicitud'
            : 'No pudimos completar la solicitud';
        errors.value = errorMessages(error);
        await nextTick();
        document.querySelector<HTMLElement>('.auth-form [aria-invalid="true"]')?.focus();
    } finally {
        busy.value = false;
    }
}

async function logIn() {
    await execute(async () => {
        await session.login(loginForm.email, loginForm.password);
        history.replaceState(null, '', '/');
        emit('authenticated');
    });
}

async function sendResetLink() {
    await execute(async () => {
        await api.csrf();
        notice.value = await api.forgotPassword(forgotForm.email);
    });
}

async function resetPassword() {
    if (resetForm.password !== resetForm.password_confirmation) {
        errorTitle.value = 'Revisá los datos ingresados';
        fieldErrors.value = { password_confirmation: ['Las contraseñas no coinciden.'] };
        errors.value = fieldErrors.value.password_confirmation;
        await nextTick();
        document.querySelector<HTMLElement>('[name="password_confirmation"]')?.focus();
        return;
    }

    await execute(async () => {
        await api.csrf();
        const message = await api.resetPassword({
            token: resetToken.value,
            ...resetForm,
        });
        loginForm.email = resetForm.email;
        changeMode('login', true);
        notice.value = message;
    });
}

function fieldError(name: string): string | undefined {
    return fieldErrors.value[name]?.[0];
}

function describedBy(name: string, hint?: string): string | undefined {
    const ids = [fieldError(name) ? `auth-error-${name}` : '', hint ?? ''].filter(Boolean);
    return ids.length ? ids.join(' ') : undefined;
}

readLocation();

onMounted(() => {
    addEventListener('popstate', readLocation);
});

onBeforeUnmount(() => removeEventListener('popstate', readLocation));
</script>

<template>
    <main class="login-page">
        <section class="login-intro" aria-label="La Victoria Bakery">
            <img class="login-logo" :src="brandLogo" alt="">
            <div>
                <p class="brand-name">La Victoria Bakery</p>
                <p>Pedidos, producción, inventario y cobranzas en una sola mesa operativa.</p>
            </div>
        </section>

        <section class="login-card">
            <p class="section-kicker">Acceso al sistema</p>

            <template v-if="mode === 'login'">
                <h1>Bienvenido a tu jornada.</h1>
                <p class="muted">Ingresá con tu usuario para continuar en la sucursal asignada.</p>
            </template>
            <template v-else-if="mode === 'forgot'">
                <h1>Recuperá tu acceso.</h1>
                <p class="muted">Ingresá tu correo y te enviaremos un enlace temporal para elegir una nueva contraseña.</p>
            </template>
            <template v-else>
                <h1>Creá una nueva contraseña.</h1>
                <p class="muted">El enlace funciona una sola vez. Elegí una contraseña de al menos ocho caracteres.</p>
            </template>

            <section v-if="errors.length" id="auth-errors" class="message error" role="alert">
                <strong>{{ errorTitle }}</strong>
                <ul><li v-for="error in errors" :key="error">{{ error }}</li></ul>
            </section>
            <p v-if="notice" class="message success" role="status" aria-live="polite">{{ notice }}</p>

            <form v-if="mode === 'login'" class="form-stack auth-form" @submit.prevent="logIn">
                <div class="auth-field">
                    <label for="login-email">Correo electrónico</label>
                    <input
                        id="login-email"
                        v-model="loginForm.email"
                        name="email"
                        required
                        autocomplete="username"
                        type="email"
                        :aria-invalid="Boolean(fieldError('email'))"
                        :aria-describedby="describedBy('email')"
                    >
                    <small v-if="fieldError('email')" id="auth-error-email" class="field-error">{{ fieldError('email') }}</small>
                </div>
                <div class="auth-field">
                    <label for="login-password">Contraseña</label>
                    <div class="password-field">
                        <input
                            id="login-password"
                            v-model="loginForm.password"
                            name="password"
                            required
                            autocomplete="current-password"
                            :type="showLoginPassword ? 'text' : 'password'"
                            :aria-invalid="Boolean(fieldError('password'))"
                            :aria-describedby="describedBy('password')"
                        >
                        <button
                            class="password-toggle"
                            data-password-toggle="login"
                            type="button"
                            :aria-label="showLoginPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                            :aria-pressed="showLoginPassword"
                            @click="showLoginPassword = !showLoginPassword"
                        >{{ showLoginPassword ? 'Ocultar' : 'Mostrar' }}</button>
                    </div>
                    <small v-if="fieldError('password')" id="auth-error-password" class="field-error">{{ fieldError('password') }}</small>
                </div>
                <button class="auth-link" data-auth-action="forgot" type="button" @click="changeMode('forgot')">Olvidé mi contraseña</button>
                <button class="primary" :disabled="busy || !online" type="submit">{{ busy ? 'Ingresando…' : 'Ingresar' }}</button>
            </form>

            <form v-else-if="mode === 'forgot'" class="form-stack auth-form" @submit.prevent="sendResetLink">
                <div class="auth-field">
                    <label for="forgot-email">Correo electrónico</label>
                    <input
                        id="forgot-email"
                        v-model="forgotForm.email"
                        name="email"
                        required
                        autocomplete="email"
                        type="email"
                        :aria-invalid="Boolean(fieldError('email'))"
                        :aria-describedby="describedBy('email')"
                    >
                    <small v-if="fieldError('email')" id="auth-error-email" class="field-error">{{ fieldError('email') }}</small>
                </div>
                <button class="primary" :disabled="busy || !online" type="submit">{{ busy ? 'Enviando…' : 'Enviar enlace' }}</button>
                <button class="auth-link auth-link-centered" type="button" @click="changeMode('login')">Volver al ingreso</button>
            </form>

            <form v-else class="form-stack auth-form" @submit.prevent="resetPassword">
                <div class="auth-field">
                    <label for="reset-email">Correo electrónico</label>
                    <input
                        id="reset-email"
                        v-model="resetForm.email"
                        name="email"
                        required
                        autocomplete="username"
                        type="email"
                        :aria-invalid="Boolean(fieldError('email'))"
                        :aria-describedby="describedBy('email')"
                    >
                    <small v-if="fieldError('email')" id="auth-error-email" class="field-error">{{ fieldError('email') }}</small>
                </div>
                <div class="auth-field">
                    <label for="reset-password">Nueva contraseña</label>
                    <div class="password-field">
                        <input
                            id="reset-password"
                            v-model="resetForm.password"
                            name="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            :type="showResetPassword ? 'text' : 'password'"
                            :aria-invalid="Boolean(fieldError('password'))"
                            :aria-describedby="describedBy('password', 'password-hint')"
                        >
                        <button
                            class="password-toggle"
                            type="button"
                            :aria-label="showResetPassword ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'"
                            :aria-pressed="showResetPassword"
                            @click="showResetPassword = !showResetPassword"
                        >{{ showResetPassword ? 'Ocultar' : 'Mostrar' }}</button>
                    </div>
                    <small id="password-hint" class="field-hint">Usá al menos ocho caracteres.</small>
                    <small v-if="fieldError('password')" id="auth-error-password" class="field-error">{{ fieldError('password') }}</small>
                </div>
                <div class="auth-field">
                    <label for="reset-password-confirmation">Repetir contraseña</label>
                    <div class="password-field">
                        <input
                            id="reset-password-confirmation"
                            v-model="resetForm.password_confirmation"
                            name="password_confirmation"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            :type="showResetConfirmation ? 'text' : 'password'"
                            :aria-invalid="Boolean(fieldError('password_confirmation'))"
                            :aria-describedby="describedBy('password_confirmation')"
                        >
                        <button
                            class="password-toggle"
                            type="button"
                            :aria-label="showResetConfirmation ? 'Ocultar confirmación' : 'Mostrar confirmación'"
                            :aria-pressed="showResetConfirmation"
                            @click="showResetConfirmation = !showResetConfirmation"
                        >{{ showResetConfirmation ? 'Ocultar' : 'Mostrar' }}</button>
                    </div>
                    <small v-if="fieldError('password_confirmation')" id="auth-error-password_confirmation" class="field-error">{{ fieldError('password_confirmation') }}</small>
                </div>
                <button class="primary" :disabled="busy || !online || !resetToken" type="submit">{{ busy ? 'Actualizando…' : 'Actualizar contraseña' }}</button>
                <div class="auth-secondary-actions">
                    <button class="auth-link" type="button" @click="changeMode('forgot')">Solicitar otro enlace</button>
                    <button class="auth-link" type="button" @click="changeMode('login')">Volver al ingreso</button>
                </div>
            </form>

            <p class="helper">{{ online ? 'Acceso exclusivo para personal autorizado.' : 'Conectate a internet para continuar.' }}</p>
        </section>
    </main>
</template>
