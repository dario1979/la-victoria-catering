import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../api';
import ManualReviewPage from '../ManualReviewPage.vue';

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

const TableStub = defineComponent({
    props: ['endpoint', 'columns'],
    template: `
        <div class="table-stub">
            <slot
                name="actions"
                :row="endpoint.includes('failures')
                    ? { id: 7, status: 'failed', job_name: 'SafeJob' }
                    : endpoint.includes('notification')
                        ? { id: 8, status: 'failed', subject: 'Alerta vigente', channel: 'internal', attempts: 3 }
                        : {
                            id: 9,
                            status: 'rejected',
                            provider: 'warehouse',
                            external_id: 'safe-reference',
                            signature_valid: false,
                            signature: 'signature-secret',
                            headers: 'headers-secret',
                            payload: 'payload-secret'
                        }"
            />
        </div>
    `,
    setup(_, { expose }) {
        expose({ refresh: vi.fn() });
    },
});

afterEach(() => {
    document.body.innerHTML = '';
    document.body.className = '';
    vi.clearAllMocks();
});

describe('ManualReviewPage', () => {
    it('records a job resolution without offering a generic retry', async () => {
        vi.mocked(api.post).mockResolvedValue({ id: 7, status: 'resolved' });
        const wrapper = mount(ManualReviewPage, {
            attachTo: document.body,
            props: { online: true, branchName: 'Centro' },
            global: { stubs: { ServerDataTable: TableStub } },
        });

        expect(wrapper.text()).toContain('Metadata sanitizada');
        expect(wrapper.text()).not.toContain('Reintentar');
        await wrapper.get('.table-stub button').trigger('click');
        expect(document.querySelector('.base-modal')?.textContent).toContain('SafeJob');
        const textarea = document.querySelector<HTMLTextAreaElement>('textarea')!;
        textarea.value = 'Se verificó la evidencia y no corresponde reintento.';
        textarea.dispatchEvent(new Event('input'));
        document.querySelector<HTMLFormElement>('#manual-review-form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        expect(api.post).toHaveBeenCalledWith(
            '/operations/failures/7/resolution',
            { resolution: 'Se verificó la evidencia y no corresponde reintento.' },
            expect.any(String),
        );
        expect(wrapper.emitted('notice')?.[0]).toEqual(['Resolución del job registrada sin reintento genérico.']);
        expect(textarea.value).toContain('evidencia');
        wrapper.unmount();
    });

    it('confirms a notification retry and keeps controls disabled offline', async () => {
        vi.mocked(api.post).mockResolvedValue({ id: 8, status: 'pending' });
        const wrapper = mount(ManualReviewPage, {
            attachTo: document.body,
            props: { online: true, branchName: 'Centro' },
            global: { stubs: { ServerDataTable: TableStub } },
        });
        await wrapper.findAll('.module-tabs button').find(button => button.text() === 'Entregas')!.trigger('click');
        await wrapper.get('.table-stub button').trigger('click');
        expect(document.querySelector('.base-modal')?.textContent).toContain('evento dejó de ser accionable');
        document.querySelector<HTMLFormElement>('#manual-review-form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        expect(api.post).toHaveBeenCalledWith(
            '/operations/notification-deliveries/8/retry',
            {},
            expect.any(String),
        );

        await wrapper.setProps({ online: false });
        expect(wrapper.get('.offline-banner').text()).toContain('Modo consulta');
        expect(wrapper.get('.table-stub button').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('records a webhook review without rendering original evidence', async () => {
        vi.mocked(api.post).mockResolvedValue({ id: 9, status: 'manual_review' });
        const wrapper = mount(ManualReviewPage, {
            attachTo: document.body,
            props: { online: true, branchName: 'Centro' },
            global: { stubs: { ServerDataTable: TableStub } },
        });
        await wrapper.findAll('.module-tabs button').find(button => button.text() === 'Webhooks')!.trigger('click');

        expect(wrapper.text()).toContain('ocultos e inmutables');
        expect(wrapper.text()).not.toContain('payload-secret');
        const tableColumns = wrapper.getComponent(TableStub).props('columns') as Array<{ key: string }>;
        expect(tableColumns.map(column => column.key)).not.toEqual(expect.arrayContaining(['signature', 'headers', 'payload']));
        await wrapper.get('.table-stub button').trigger('click');
        expect(document.querySelector('.base-modal')?.textContent).toContain('warehouse · safe-reference');
        expect(document.querySelector('.base-modal')?.textContent).toContain('Firma: Inválida');
        expect(document.querySelector('.base-modal')?.textContent).not.toContain('signature-secret');
        const textarea = document.querySelector<HTMLTextAreaElement>('textarea')!;
        textarea.value = 'La firma inválida requiere revisión externa.';
        textarea.dispatchEvent(new Event('input'));
        document.querySelector<HTMLFormElement>('#manual-review-form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        expect(api.post).toHaveBeenCalledWith(
            '/operations/webhooks/9/resolution',
            {
                status: 'manual_review',
                resolution: 'La firma inválida requiere revisión externa.',
            },
            expect.any(String),
        );
        wrapper.unmount();
    });

    it('does not offer retries for the disabled push channel', async () => {
        const PushTableStub = defineComponent({
            template: `
                <div class="table-stub">
                    <slot name="actions" :row="{ id: 10, status: 'failed', channel: 'pwa_push' }" />
                </div>
            `,
        });
        const wrapper = mount(ManualReviewPage, {
            props: { online: true, branchName: 'Centro' },
            global: { stubs: { ServerDataTable: PushTableStub } },
        });

        await wrapper.findAll('.module-tabs button').find(button => button.text() === 'Entregas')!.trigger('click');
        expect(wrapper.get('.muted-action').text()).toBe('Push apagado');
        expect(wrapper.find('.table-stub button').exists()).toBe(false);
        wrapper.unmount();
    });
});
