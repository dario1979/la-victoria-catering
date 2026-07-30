import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../api';
import ImportPage from '../ImportPage.vue';
import ConfirmActionModal from '../ui/ConfirmActionModal.vue';

vi.mock('../../api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api')>();

    return {
        ...actual,
        api: {
            ...actual.api,
            get: vi.fn(),
            getRaw: vi.fn(),
            uploadRaw: vi.fn(),
            post: vi.fn(),
            download: vi.fn(),
        },
    };
});

const definitions = {
    customers: {
        label: 'Clientes',
        fields: ['name', 'email'],
        required: ['name'],
        example: ['Cliente Piloto', 'cliente@example.test'],
    },
};

const analyzedBatch = {
    id: 41,
    uuid: 'import-41',
    type: 'customers',
    status: 'analyzed',
    original_name: 'clientes.csv',
    headers: ['source_name', 'email'],
    mapping: { email: 'email' },
    total_rows: 2,
    valid_rows: 0,
    error_rows: 0,
    created_at: '2026-07-30T12:00:00Z',
};

afterEach(() => {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/');
});

function prepareApi() {
    vi.mocked(api.get).mockResolvedValue(definitions as never);
    vi.mocked(api.getRaw).mockResolvedValue({ data: [] } as never);
}

describe('ImportPage', () => {
    it('offers a reusable template and states tenant-owned limits', async () => {
        prepareApi();
        const wrapper = mount(ImportPage, { props: { online: true, branchName: 'Centro' } });
        await flushPromises();

        expect(wrapper.text()).toContain('hasta 5 MB y 5.000 filas');
        expect(wrapper.text()).toContain('provienen de tu sesión');
        await wrapper.get('.import-step .secondary').trigger('click');
        await flushPromises();

        expect(api.download).toHaveBeenCalledWith('/import-templates/customers', 'plantilla-customers.csv');
        wrapper.unmount();
    });

    it('requires mapping and dry-run before confirming with a stable idempotency key', async () => {
        prepareApi();
        vi.mocked(api.uploadRaw).mockResolvedValue({ data: analyzedBatch } as never);
        vi.mocked(api.post)
            .mockResolvedValueOnce({
                ...analyzedBatch,
                status: 'validated',
                mapping: { name: 'source_name', email: 'email' },
                preview: [{ name: 'Cliente Uno', email: 'uno@example.test' }],
                valid_rows: 2,
                error_rows: 0,
            } as never)
            .mockResolvedValueOnce({ ...analyzedBatch, status: 'queued' } as never);
        const wrapper = mount(ImportPage, { props: { online: true, branchName: 'Centro' } });
        await flushPromises();

        const fileInput = wrapper.get('input[type="file"]');
        const file = new File(['source_name,email\nCliente Uno,uno@example.test'], 'clientes.csv', { type: 'text/csv' });
        Object.defineProperty(fileInput.element, 'files', { value: [file] });
        await fileInput.trigger('change');
        await wrapper.findAll('button').find(button => button.text() === 'Subir y analizar')!.trigger('click');
        await flushPromises();

        expect(api.uploadRaw).toHaveBeenCalledWith(
            '/imports',
            expect.any(FormData),
            expect.stringMatching(/^import-upload:/),
        );
        expect(wrapper.text()).toContain('clientes.csv');
        const nameMapping = wrapper.findAll('.mapping-grid label').find(label => label.text().includes('name'))!;
        await nameMapping.get('select').setValue('source_name');
        const confirmButton = wrapper.findAll('button').find(button => button.text() === 'Confirmar e importar')!;
        expect(confirmButton.attributes('disabled')).toBeDefined();

        await wrapper.findAll('button').find(button => button.text() === 'Ejecutar dry-run')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('table').text()).toContain('Cliente Uno');
        expect(confirmButton.attributes('disabled')).toBeUndefined();
        await confirmButton.trigger('click');
        expect(api.post).toHaveBeenCalledTimes(1);
        expect(wrapper.getComponent(ConfirmActionModal).props('open')).toBe(true);
        wrapper.getComponent(ConfirmActionModal).vm.$emit('confirm');
        await flushPromises();

        const confirmCall = vi.mocked(api.post).mock.calls[1];
        expect(confirmCall[0]).toBe('/imports/41/confirm');
        expect(confirmCall[2]).toMatch(/^import-confirm-import-41:/);
        wrapper.unmount();
    });

    it('locks the visible type and lets an operator resume a persisted recent batch', async () => {
        vi.mocked(api.get)
            .mockResolvedValueOnce(definitions as never)
            .mockResolvedValueOnce(analyzedBatch as never);
        vi.mocked(api.getRaw).mockResolvedValue({ data: [analyzedBatch] } as never);
        const wrapper = mount(ImportPage, { props: { online: true, branchName: 'Centro' } });
        await flushPromises();

        await wrapper.findAll('button').find(button => button.text() === 'Abrir lote')!.trigger('click');
        await flushPromises();

        expect(api.get).toHaveBeenLastCalledWith('/imports/41');
        expect(wrapper.get('select').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('clientes.csv');
        expect(window.location.search).toContain('import_batch=41');
        wrapper.unmount();
    });

    it('keeps all mutations disabled while offline', async () => {
        prepareApi();
        const wrapper = mount(ImportPage, { props: { online: false, branchName: 'Centro' } });
        await flushPromises();

        expect(wrapper.get('.offline-banner').text()).toContain('Modo consulta');
        expect(wrapper.findAll('button').filter(button => ['Descargar plantilla CSV', 'Subir y analizar']
            .includes(button.text())).every(button => button.attributes('disabled') !== undefined)).toBe(true);
        wrapper.unmount();
    });
});
