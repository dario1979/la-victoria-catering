import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, configureApi } from './api';

beforeEach(() => {
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
});

afterEach(() => {
    vi.unstubAllGlobals();
    configureApi({ csrfToken: '', organizationId: null, branchId: null });
});

describe('API transport failures', () => {
    it('describes a failed GET as a retryable read', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network')));

        await expect(api.get('/orders')).rejects.toEqual(expect.objectContaining({
            status: 0,
            message: 'No pudimos consultar el servidor. Revisá la conexión y reintentá.',
        }));
    });

    it('keeps the unknown-outcome warning for mutations', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network')));

        await expect(api.post('/orders', {}, 'same-key')).rejects.toEqual(expect.objectContaining({
            status: 0,
            message: expect.stringContaining('el servidor pudo haber aplicado la operación'),
        }));
    });
});

describe('password recovery transport', () => {
    it('sends CSRF without tenant headers and consumes the generic response', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: { token: 'csrf-token' } }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: { message: 'Revisá tu correo.' },
            }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);
        configureApi({ organizationId: 9, branchId: 4 });

        await api.csrf();
        await expect(api.forgotPassword('persona@lavictoria.test')).resolves.toBe('Revisá tu correo.');

        const [, init] = fetchMock.mock.calls[1] as [string, RequestInit];
        const headers = init.headers as Headers;
        expect(headers.get('X-CSRF-TOKEN')).toBe('csrf-token');
        expect(headers.has('X-Organization-ID')).toBe(false);
        expect(headers.has('X-Branch-ID')).toBe(false);
    });
});
