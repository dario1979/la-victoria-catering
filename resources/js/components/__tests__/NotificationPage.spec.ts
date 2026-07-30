import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../api';
import NotificationPage from '../NotificationPage.vue';

vi.mock('../../api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api')>();

    return {
        ...actual,
        api: {
            ...actual.api,
            get: vi.fn(),
            put: vi.fn(),
        },
    };
});

const TableStub = defineComponent({
    template: '<div class="table-stub">Centro personal</div>',
});

afterEach(() => {
    vi.clearAllMocks();
});

describe('NotificationPage', () => {
    it('loads and saves all channel preferences', async () => {
        vi.mocked(api.get).mockResolvedValue([
            {
                channel: 'email',
                enabled: false,
                quiet_hours_start: '20:00',
                quiet_hours_end: '07:00',
                timezone: 'America/Argentina/Buenos_Aires',
            },
        ]);
        vi.mocked(api.put).mockResolvedValue([]);
        const wrapper = mount(NotificationPage, {
            props: { online: true, branchName: 'Centro' },
            global: { stubs: { ServerDataTable: TableStub } },
        });
        await flushPromises();

        expect(api.get).toHaveBeenCalledWith('/notification-preferences');
        expect(wrapper.text()).toContain('Push PWA permanece desactivado');
        expect(wrapper.findAll('fieldset')).toHaveLength(3);
        await wrapper.get('.panel-head .primary').trigger('click');
        await flushPromises();

        expect(api.put).toHaveBeenCalledWith(
            '/notification-preferences',
            expect.objectContaining({ preferences: expect.any(Array) }),
        );
        expect(wrapper.emitted('notice')?.[0]).toEqual(['Preferencias de notificación actualizadas.']);
    });

    it('disables preference changes while offline', async () => {
        vi.mocked(api.get).mockResolvedValue([]);
        const wrapper = mount(NotificationPage, {
            props: { online: false, branchName: 'Centro' },
            global: { stubs: { ServerDataTable: TableStub } },
        });
        await flushPromises();

        expect(wrapper.get('.panel-head .primary').attributes('disabled')).toBeDefined();
    });
});
