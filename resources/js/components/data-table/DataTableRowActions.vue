<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, type CSSProperties } from 'vue';
import type { DataTableRowAction } from '../../types';

defineProps<{ actions: DataTableRowAction[]; label?: string }>();
const emit = defineEmits<{ action: [key: string] }>();
const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const menu = ref<HTMLElement | null>(null);
const menuStyle = ref<CSSProperties>({});

function closeOnOutside(event: MouseEvent) {
    const target = event.target as Node;
    if (!root.value?.contains(target) && !menu.value?.contains(target)) close();
}

function items() {
    return Array.from(menu.value?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]:not(:disabled)') ?? []);
}

function positionMenu() {
    if (!trigger.value || !menu.value) return;
    const gap = 4;
    const edge = 8;
    const triggerRect = trigger.value.getBoundingClientRect();
    const width = Math.min(190, window.innerWidth - edge * 2);
    const height = menu.value.scrollHeight;
    const roomBelow = window.innerHeight - triggerRect.bottom - edge;
    const openBelow = roomBelow >= height + gap;
    const top = openBelow
        ? triggerRect.bottom + gap
        : Math.max(edge, triggerRect.top - height - gap);
    const left = Math.min(
        window.innerWidth - width - edge,
        Math.max(edge, triggerRect.right - width),
    );

    menuStyle.value = {
        left: `${left}px`,
        top: `${top}px`,
        width: `${width}px`,
        maxHeight: `${window.innerHeight - edge * 2}px`,
    };
}

function addPositionListeners() {
    window.addEventListener('resize', positionMenu);
    window.addEventListener('scroll', positionMenu, true);
}

function removePositionListeners() {
    window.removeEventListener('resize', positionMenu);
    window.removeEventListener('scroll', positionMenu, true);
}

function show(focusLast = false) {
    open.value = true;
    document.addEventListener('mousedown', closeOnOutside);
    addPositionListeners();
    void nextTick(() => {
        positionMenu();
        const available = items();
        available[focusLast ? available.length - 1 : 0]?.focus();
    });
}

function close(returnToTrigger = false) {
    open.value = false;
    document.removeEventListener('mousedown', closeOnOutside);
    removePositionListeners();
    if (returnToTrigger) void nextTick(() => trigger.value?.focus());
}

function toggle() {
    if (open.value) close();
    else show();
}

function choose(key: string) {
    close();
    emit('action', key);
}

function onKeydown(event: KeyboardEvent) {
    if (!open.value && event.target === trigger.value && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
        event.preventDefault();
        show(event.key === 'ArrowUp');
        return;
    }
    if (!open.value) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        close(true);
        return;
    }
    if (event.key === 'Tab') {
        close();
        return;
    }
    const available = items();
    const current = Math.max(0, available.indexOf(document.activeElement as HTMLButtonElement));
    let target = current;
    if (event.key === 'ArrowDown') target = (current + 1) % available.length;
    else if (event.key === 'ArrowUp') target = (current - 1 + available.length) % available.length;
    else if (event.key === 'Home') target = 0;
    else if (event.key === 'End') target = available.length - 1;
    else return;
    event.preventDefault();
    available[target]?.focus();
}

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', closeOnOutside);
    removePositionListeners();
});
</script>

<template>
    <div ref="root" class="row-actions" @keydown="onKeydown">
        <button ref="trigger" class="row-actions-trigger" type="button" :aria-expanded="open" aria-haspopup="menu" @click="toggle">
            {{ label ?? 'Acciones' }} <span aria-hidden="true">⋯</span>
        </button>
        <Teleport to="body">
            <div
                v-if="open"
                ref="menu"
                class="row-actions-menu"
                role="menu"
                :style="menuStyle"
                @keydown="onKeydown"
            >
                <button
                    v-for="action in actions"
                    :key="action.key"
                    type="button"
                    role="menuitem"
                    tabindex="-1"
                    :disabled="action.disabled"
                    :class="{ danger: action.tone === 'danger' }"
                    @click="choose(action.key)"
                >{{ action.label }}</button>
            </div>
        </Teleport>
    </div>
</template>
