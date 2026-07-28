import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AuthPage from '../AuthPage.vue';

const mocks = vi.hoisted(() => ({
    login: vi.fn(),
    csrf: vi.fn(),
    forgotPassword: vi.fn(),
    resetPassword: vi.fn(),
}));

vi.mock('../../stores/session', () => ({
    useSessionStore: () => ({ login: mocks.login }),
}));

vi.mock('../../api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api')>();

    return {
        ...actual,
        api: {
            ...actual.api,
            csrf: mocks.csrf,
            forgotPassword: mocks.forgotPassword,
            resetPassword: mocks.resetPassword,
        },
    };
});

afterEach(() => {
    history.replaceState(null, '', '/');
    vi.clearAllMocks();
});

describe('AuthPage', () => {
    it('shows and hides the login password with an accessible pressed state', async () => {
        const wrapper = mount(AuthPage, { props: { online: true } });
        const password = wrapper.get('input[autocomplete="current-password"]');
        const toggle = wrapper.get('[data-password-toggle="login"]');

        expect(password.attributes('type')).toBe('password');
        expect(toggle.attributes('aria-pressed')).toBe('false');

        await toggle.trigger('click');

        expect(password.attributes('type')).toBe('text');
        expect(toggle.attributes('aria-pressed')).toBe('true');
        expect(toggle.text()).toContain('Ocultar');
    });

    it('requests a reset link and displays the enumeration-safe response', async () => {
        mocks.forgotPassword.mockResolvedValue(
            'Si el correo está registrado, vas a recibir un enlace para restablecer la contraseña.',
        );
        const wrapper = mount(AuthPage, { props: { online: true } });

        await wrapper.get('[data-auth-action="forgot"]').trigger('click');
        await wrapper.get('input[autocomplete="email"]').setValue('persona@lavictoria.test');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(mocks.csrf).toHaveBeenCalledOnce();
        expect(mocks.forgotPassword).toHaveBeenCalledWith('persona@lavictoria.test');
        expect(wrapper.get('[role="status"]').text()).toContain('Si el correo está registrado');
    });

    it('uses the token and email from the reset link, then returns to login', async () => {
        history.replaceState(null, '', '/reset-password/token-123?email=persona%40lavictoria.test');
        mocks.resetPassword.mockResolvedValue('La contraseña se actualizó. Ya podés ingresar.');
        const wrapper = mount(AuthPage, { props: { online: true } });

        expect(wrapper.get('input[autocomplete="username"]').element).toHaveProperty(
            'value',
            'persona@lavictoria.test',
        );
        await wrapper.get('input[autocomplete="new-password"]').setValue('new-secure-password');
        await wrapper.get('input[name="password_confirmation"]').setValue('new-secure-password');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(mocks.resetPassword).toHaveBeenCalledWith({
            token: 'token-123',
            email: 'persona@lavictoria.test',
            password: 'new-secure-password',
            password_confirmation: 'new-secure-password',
        });
        expect(wrapper.get('h1').text()).toContain('Bienvenido');
        expect(wrapper.get('[role="status"]').text()).toContain('La contraseña se actualizó');
        expect(location.pathname).toBe('/');
    });
});
