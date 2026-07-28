import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../api';
import ServerDataTable from '../data-table/ServerDataTable.vue';

vi.mock('../../api', () => ({
    api: {
        page: vi.fn(),
        exportTable: vi.fn(),
    },
    errorMessages: (error: unknown) => [String(error)],
}));

const response = {
    data: [
        { id: 1, name: 'Pan de campo', active: true },
        { id: 2, name: 'Medialuna', active: true },
    ],
    meta: { current_page: 1, from: 1, last_page: 2, per_page: 25, to: 2, total: 30 },
};

beforeEach(() => {
    vi.mocked(api.page).mockResolvedValue(response);
    vi.mocked(api.exportTable).mockResolvedValue(undefined);
});

afterEach(() => {
    vi.useRealTimers();
});

describe('ServerDataTable', () => {
    it('loads, searches with debounce and keeps the query server-side', async () => {
        vi.useFakeTimers();
        const wrapper = mount(ServerDataTable, {
            props: {
                endpoint: '/products',
                columns: [
                    { key: 'name', label: 'Producto', sortable: true },
                    { key: 'active', label: 'Estado' },
                ],
                label: 'Listado de productos',
            },
        });
        await flushPromises();

        expect(api.page).toHaveBeenCalledWith('/products', expect.objectContaining({ per_page: 25, search: '' }));
        await wrapper.get('input[type="search"]').setValue('pan');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(api.page).toHaveBeenLastCalledWith('/products', expect.objectContaining({ search: 'pan', page: 1 }));
        expect(wrapper.get('table').attributes('aria-label')).toBe('Listado de productos');
    });

    it('sends sorting and pagination changes back to the API', async () => {
        const wrapper = mount(ServerDataTable, {
            props: {
                endpoint: '/products',
                columns: [{ key: 'name', label: 'Producto', sortable: true }],
            },
        });
        await flushPromises();

        await wrapper.get('.column-sort').trigger('click');
        await flushPromises();
        expect(api.page).toHaveBeenLastCalledWith('/products', expect.objectContaining({ sort: 'name', direction: 'asc' }));

        const next = wrapper.findAll('.pagination-actions button')[1];
        await next.trigger('click');
        await flushPromises();
        expect(api.page).toHaveBeenLastCalledWith('/products', expect.objectContaining({ page: 2 }));
    });

    it('exports through the backend with the active query', async () => {
        const wrapper = mount(ServerDataTable, {
            props: {
                endpoint: '/products',
                columns: [{ key: 'name', label: 'Producto', sortable: true }],
            },
        });
        await flushPromises();
        await wrapper.get('.table-toolbar-actions button:last-child').trigger('click');
        await flushPromises();

        expect(api.exportTable).toHaveBeenCalledWith('/products', expect.objectContaining({ per_page: 25 }));
    });
});
