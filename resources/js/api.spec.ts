import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from './api';

beforeEach(() => {
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
});

afterEach(() => {
    vi.unstubAllGlobals();
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
