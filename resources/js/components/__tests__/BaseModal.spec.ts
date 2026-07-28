import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
import BaseModal from '../ui/BaseModal.vue';

afterEach(() => {
    document.body.innerHTML = '';
    document.body.className = '';
});

describe('BaseModal', () => {
    it('opens as an accessible dialog, focuses its first control and restores focus', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const wrapper = mount(BaseModal, {
            attachTo: document.body,
            props: { open: true, title: 'Nuevo cliente', description: 'Datos comerciales' },
            slots: { default: '<input autofocus aria-label="Nombre">' },
        });
        await nextTick();

        const dialog = document.querySelector('[role="dialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog?.getAttribute('aria-modal')).toBe('true');
        expect(document.body.classList.contains('modal-open')).toBe(true);
        expect((document.activeElement as HTMLElement).getAttribute('aria-label')).toBe('Nombre');

        await wrapper.setProps({ open: false });
        await nextTick();
        expect(document.activeElement).toBe(opener);
    });

    it('closes with Escape when safe', async () => {
        const wrapper = mount(BaseModal, {
            attachTo: document.body,
            props: { open: true, title: 'Detalle' },
        });
        await nextTick();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('requires an explicit decision before discarding dirty changes', async () => {
        const wrapper = mount(BaseModal, {
            attachTo: document.body,
            props: { open: true, title: 'Editar cliente', dirty: true },
        });
        await nextTick();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(document.querySelector('[role="alertdialog"]')).not.toBeNull();
        expect(wrapper.emitted('close')).toBeUndefined();

        const discard = Array.from(document.querySelectorAll('button'))
            .find((button) => button.textContent?.includes('Descartar cambios')) as HTMLButtonElement;
        discard.click();
        expect(wrapper.emitted('discard')).toHaveLength(1);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('traps focus inside the discard alert instead of covered form controls', async () => {
        const wrapper = mount(BaseModal, {
            attachTo: document.body,
            props: { open: true, title: 'Editar cliente', dirty: true },
            slots: { default: '<input aria-label="Nombre">' },
        });
        await nextTick();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        const alert = document.querySelector('[role="alertdialog"]') as HTMLElement;
        const buttons = Array.from(alert.querySelectorAll('button'));
        expect(document.activeElement).toBe(buttons[0]);

        buttons[1].focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab' }));
        expect(document.activeElement).toBe(buttons[0]);

        buttons[0].focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true }));
        expect(document.activeElement).toBe(buttons[1]);
        expect(document.activeElement?.getAttribute('aria-label')).not.toBe('Nombre');

        wrapper.unmount();
    });
});
