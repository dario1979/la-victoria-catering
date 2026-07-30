import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../api';
import ProcurementPage from '../ProcurementPage.vue';

vi.mock('../../api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api')>();

    return {
        ...actual,
        api: {
            ...actual.api,
            get: vi.fn(),
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

const RowActions = defineComponent({
    props: ['actions'],
    emits: ['action'],
    template: '<div><button v-for="action in actions" :key="action.key" type="button" @click="$emit(\'action\', action.key)">{{ action.label }}</button></div>',
});

afterEach(() => {
    document.body.innerHTML = '';
    document.body.className = '';
    vi.clearAllMocks();
});

describe('ProcurementPage', () => {
    it('lets inventory receive a sent order but does not expose management tabs actions', async () => {
        vi.mocked(api.get).mockResolvedValue({
            id: 9,
            number: 'OC-2026-000009',
            status: 'sent',
            supplier: { trade_name: 'Molino Sur' },
            items: [{
                id: 31,
                product_name: 'Harina',
                purchase_unit: 'kg',
                quantity: '5.000',
                received_quantity: '2.000',
                unit_price: '1500.00',
            }],
        });
        const wrapper = mount(ProcurementPage, {
            attachTo: document.body,
            props: { online: true, branchName: 'Centro', role: 'inventory' },
            global: {
                stubs: {
                    ServerDataTable: RowTable({
                        id: 9,
                        number: 'OC-2026-000009',
                        status: 'sent',
                        supplier: { trade_name: 'Molino Sur' },
                    }),
                    DataTableRowActions: RowActions,
                },
            },
        });

        expect(wrapper.text()).toContain('Proveedores');
        expect(wrapper.find('.list-page-header .primary').exists()).toBe(false);
        expect(wrapper.text()).toContain('Registrar recepción');
        expect(wrapper.text()).not.toContain('Aprobar');

        const receive = wrapper.findAll('.table-stub button')
            .find(button => button.text() === 'Registrar recepción');
        expect(receive).toBeDefined();
        await receive!.trigger('click');
        await flushPromises();

        const modal = document.querySelector('.base-modal') as HTMLElement;
        expect(api.get).toHaveBeenCalledWith('/purchase-orders/9');
        expect(modal.textContent).toContain('Registrar recepción');
        expect(modal.textContent).toContain('Pendiente: 3.000 kg');
        expect(modal.querySelector('input[type="datetime-local"]')).not.toBeNull();
        expect(modal.querySelector('.modal-footer button[type="submit"]')).not.toBeNull();

        wrapper.unmount();
    });

    it('confirms approval with an idempotency key and freezes the order contract', async () => {
        vi.mocked(api.post).mockResolvedValue({ id: 4, status: 'approved' });
        const wrapper = mount(ProcurementPage, {
            attachTo: document.body,
            props: { online: true, branchName: 'Centro', role: 'purchasing' },
            global: {
                stubs: {
                    ServerDataTable: RowTable({
                        id: 4,
                        number: 'OC-2026-000004',
                        status: 'draft',
                        supplier: { trade_name: 'Molino Norte' },
                    }),
                    DataTableRowActions: RowActions,
                },
            },
        });

        const approve = wrapper.findAll('.table-stub button').find(button => button.text() === 'Aprobar');
        expect(approve).toBeDefined();
        await approve!.trigger('click');
        await flushPromises();

        const confirm = Array.from(document.querySelectorAll<HTMLButtonElement>('.modal-footer button'))
            .find(button => button.textContent?.includes('Aprobar orden'));
        expect(confirm).toBeDefined();
        confirm!.click();
        await flushPromises();

        expect(api.post).toHaveBeenCalledWith(
            '/purchase-orders/4/transitions',
            { status: 'approved' },
            expect.stringMatching(/^purchase-order-approved:/),
        );

        wrapper.unmount();
    });

    it('keeps mutating controls disabled while offline', () => {
        const wrapper = mount(ProcurementPage, {
            props: { online: false, branchName: 'Centro', role: 'admin' },
            global: {
                stubs: {
                    ServerDataTable: RowTable({ id: 1, status: 'received' }),
                    DataTableRowActions: RowActions,
                },
            },
        });

        expect(wrapper.get('.list-page-header .primary').attributes('disabled')).toBeDefined();
    });
});
