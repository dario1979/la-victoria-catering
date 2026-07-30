import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, HttpError } from '../../api';
import FinancialPage from '../FinancialPage.vue';

vi.mock('../../api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api')>();

    return {
        ...actual,
        api: {
            ...actual.api,
            post: vi.fn(),
        },
    };
});

const RowTable = (row: Record<string, unknown>) => defineComponent({
    template: '<div class="table-stub"><slot name="actions" :row="row" /></div>',
    setup(_, { expose }) {
        expose({ refresh: vi.fn() });

        return { row };
    },
});

afterEach(() => {
    document.body.innerHTML = '';
    document.body.className = '';
    vi.clearAllMocks();
});

describe('FinancialPage', () => {
    it('lets an authorized sales user operate an open cash session without exposing register management', async () => {
        const wrapper = mount(FinancialPage, {
            attachTo: document.body,
            props: { online: true, branchName: 'Centro', role: 'sales' },
            global: {
                stubs: {
                    ServerDataTable: RowTable({ id: 8, status: 'open', register: { name: 'Mostrador' } }),
                },
            },
        });

        expect(wrapper.text()).toContain('Abrir caja');
        expect(wrapper.text()).not.toContain('Nueva caja');
        expect(wrapper.text()).toContain('Movimiento');
        expect(wrapper.text()).toContain('Cerrar');

        await wrapper.findAll('.table-stub button').find(button => button.text() === 'Movimiento')!.trigger('click');
        expect(document.querySelector('.base-modal')?.textContent).toContain('Registrar egreso');

        wrapper.unmount();
    });

    it('keeps every mutating control disabled while offline', () => {
        const wrapper = mount(FinancialPage, {
            props: { online: false, branchName: 'Centro', role: 'admin' },
            global: {
                stubs: {
                    ServerDataTable: RowTable({ id: 8, status: 'open', register: { name: 'Mostrador' } }),
                },
            },
        });

        expect(wrapper.get('.offline-banner').text()).toContain('Modo consulta');
        wrapper.findAll('button').filter(button => ['Nueva caja', 'Abrir caja', 'Movimiento', 'Cerrar'].includes(button.text()))
            .forEach(button => expect(button.attributes('disabled')).toBeDefined());
    });

    it('reuses the same idempotency key when an uncertain reconciliation submission is retried', async () => {
        vi.mocked(api.post)
            .mockRejectedValueOnce(new HttpError({ message: 'Resultado desconocido', status: 0, errors: {} }))
            .mockResolvedValueOnce({ id: 1 });
        const wrapper = mount(FinancialPage, {
            attachTo: document.body,
            props: { online: true, branchName: 'Centro', role: 'finance' },
            global: {
                stubs: {
                    ServerDataTable: RowTable({ id: 1, status: 'resolved' }),
                },
            },
        });
        await wrapper.findAll('.module-tabs button').find(button => button.text() === 'Conciliación')!.trigger('click');
        await wrapper.get('.list-page-header .primary').trigger('click');
        const form = document.querySelector<HTMLFormElement>('#finance-form')!;
        const inputs = form.querySelectorAll<HTMLInputElement>('input');
        const internalId = Array.from(inputs).find(input => input.type === 'number')!;
        const externalAmount = Array.from(inputs).find(input => input.inputMode === 'decimal')!;
        const externalReference = Array.from(inputs).find(input => input.maxLength === 255)!;
        internalId.value = '7';
        internalId.dispatchEvent(new Event('input'));
        externalAmount.value = '100.00';
        externalAmount.dispatchEvent(new Event('input'));
        externalReference.value = 'BANK-7';
        externalReference.dispatchEvent(new Event('input'));

        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        expect(api.post).toHaveBeenCalledTimes(2);
        expect(vi.mocked(api.post).mock.calls[0][2]).toBe(vi.mocked(api.post).mock.calls[1][2]);

        wrapper.unmount();
    });
});
