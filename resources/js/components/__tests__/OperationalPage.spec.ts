import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, HttpError } from '../../api';
import OperationalPage from '../OperationalPage.vue';

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

afterEach(() => {
    document.body.innerHTML = '';
    document.body.className = '';
    vi.clearAllMocks();
});

describe('OperationalPage forms', () => {
    it('uses native form submission and links server errors to the field', async () => {
        vi.mocked(api.post).mockRejectedValue(new HttpError({
            message: 'Los datos no son válidos.',
            status: 422,
            errors: { name: ['Ingresá el nombre.'] },
        }));
        const wrapper = mount(OperationalPage, {
            attachTo: document.body,
            props: {
                view: 'customers',
                online: true,
                branchName: 'Centro',
                role: 'admin',
            },
            global: {
                stubs: {
                    ServerDataTable: { template: '<div />', methods: { refresh() {} } },
                    DataTableRowActions: true,
                },
            },
        });

        await wrapper.get('.list-page-header .primary').trigger('click');
        await flushPromises();
        const form = document.querySelector('form.modal-form') as HTMLFormElement;
        const submit = document.querySelector('.modal-footer .primary') as HTMLButtonElement;
        expect(form.id).not.toBe('');
        expect(submit.type).toBe('submit');
        expect(submit.getAttribute('form')).toBe(form.id);

        const name = form.querySelector('input[name="name"]') as HTMLInputElement;
        name.value = 'Cliente';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(name.getAttribute('aria-invalid')).toBe('true');
        expect(name.getAttribute('aria-describedby')).toBe('field-error-name');
        expect(document.getElementById('field-error-name')?.textContent).toContain('Ingresá el nombre.');

        wrapper.unmount();
    });
});
