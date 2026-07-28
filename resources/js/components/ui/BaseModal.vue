<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

const props = withDefaults(defineProps<{
    open: boolean;
    title: string;
    description?: string;
    size?: 'standard' | 'wide' | 'large' | 'full';
    busy?: boolean;
    dirty?: boolean;
}>(), {
    description: '',
    size: 'standard',
    busy: false,
    dirty: false,
});

const emit = defineEmits<{ close: []; discard: [] }>();
const modal = ref<HTMLElement | null>(null);
const discardDialog = ref<HTMLElement | null>(null);
const discardOpen = ref(false);
const titleId = useId();
const descriptionId = useId();
let returnFocus: HTMLElement | null = null;
let focusBeforeDiscard: HTMLElement | null = null;

const focusableSelector = [
    'button:not([disabled])',
    '[href]',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function focusFirst(root?: HTMLElement | null) {
    void nextTick(() => {
        const target = root ?? (discardOpen.value ? discardDialog.value : modal.value);
        const preferred = target?.querySelector<HTMLElement>('[autofocus]');
        const first = preferred ?? target?.querySelector<HTMLElement>(focusableSelector);
        first?.focus();
    });
}

function requestClose() {
    if (props.busy) return;
    if (props.dirty) {
        focusBeforeDiscard = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        discardOpen.value = true;
        focusFirst();
        return;
    }
    emit('close');
}

function keepEditing() {
    discardOpen.value = false;
    void nextTick(() => focusBeforeDiscard?.focus());
}

function discard() {
    discardOpen.value = false;
    emit('discard');
    emit('close');
}

function onKeydown(event: KeyboardEvent) {
    if (!props.open) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        if (discardOpen.value) keepEditing();
        else requestClose();
        return;
    }
    if (event.key !== 'Tab' || !modal.value) return;
    const focusRoot = discardOpen.value ? discardDialog.value : modal.value;
    if (!focusRoot) return;
    const focusable = Array.from(focusRoot.querySelectorAll<HTMLElement>(focusableSelector))
        .filter((element) => !element.closest('[inert]'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(() => props.open, (open) => {
    if (open) {
        returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', onKeydown);
        discardOpen.value = false;
        focusFirst();
    } else {
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onKeydown);
        discardOpen.value = false;
        void nextTick(() => returnFocus?.focus());
    }
}, { immediate: true });

onBeforeUnmount(() => {
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal-backdrop" @mousedown.self.prevent>
            <section
                ref="modal"
                class="base-modal"
                :class="`modal-${size}`"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="description ? descriptionId : undefined"
            >
                <header class="modal-header" :inert="discardOpen || undefined">
                    <div>
                        <h2 :id="titleId">{{ title }}</h2>
                        <p v-if="description" :id="descriptionId">{{ description }}</p>
                    </div>
                    <button class="modal-close" type="button" :disabled="busy" aria-label="Cerrar diálogo" @click="requestClose">×</button>
                </header>

                <div class="modal-body" :inert="discardOpen || undefined">
                    <slot />
                </div>

                <footer v-if="$slots.footer" class="modal-footer" :inert="discardOpen || undefined">
                    <slot name="footer" :close="requestClose" />
                </footer>

                <div v-if="discardOpen" ref="discardDialog" class="discard-shield" role="alertdialog" aria-modal="true" aria-labelledby="discard-title">
                    <div class="discard-dialog">
                        <p class="section-kicker">Cambios sin guardar</p>
                        <h3 id="discard-title">¿Descartar los cambios?</h3>
                        <p>La información ingresada en este formulario se perderá.</p>
                        <div class="modal-actions">
                            <button autofocus class="secondary" type="button" @click="keepEditing">Seguir editando</button>
                            <button class="danger-button" type="button" @click="discard">Descartar cambios</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>
