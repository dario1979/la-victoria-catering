import { DOMWrapper, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, expect, it } from 'vitest';
import DataTableRowActions from '../data-table/DataTableRowActions.vue';

describe('DataTableRowActions', () => {
    it('moves focus into the menu and supports arrows, Home and Escape', async () => {
        const wrapper = mount(DataTableRowActions, {
            attachTo: document.body,
            props: {
                actions: [
                    { key: 'view', label: 'Ver detalle' },
                    { key: 'edit', label: 'Editar' },
                    { key: 'remove', label: 'Eliminar', disabled: true },
                ],
            },
        });
        const trigger = wrapper.get('.row-actions-trigger');
        await trigger.trigger('click');
        await nextTick();

        const items = Array.from(document.querySelectorAll<HTMLElement>('[role="menuitem"]'))
            .map((element) => new DOMWrapper(element));
        expect(document.activeElement).toBe(items[0].element);

        await items[0].trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(items[1].element);
        await items[1].trigger('keydown', { key: 'Home' });
        expect(document.activeElement).toBe(items[0].element);
        await items[0].trigger('keydown', { key: 'Escape' });
        expect(document.querySelector('[role="menu"]')).toBeNull();
        expect(document.activeElement).toBe(trigger.element);

        wrapper.unmount();
    });
});
