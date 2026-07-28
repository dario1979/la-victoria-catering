<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import type { DataTableRowAction } from '../../types';

defineProps<{ actions: DataTableRowAction[]; label?: string }>();
const emit = defineEmits<{ action: [key: string] }>();
const open = ref(false);
const root = ref<HTMLElement | null>(null);

function closeOnOutside(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

function toggle() {
    open.value = !open.value;
    if (open.value) document.addEventListener('mousedown', closeOnOutside);
    else document.removeEventListener('mousedown', closeOnOutside);
}

function choose(key: string) {
    open.value = false;
    document.removeEventListener('mousedown', closeOnOutside);
    emit('action', key);
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        open.value = false;
        (event.currentTarget as HTMLElement).querySelector<HTMLElement>('.row-actions-trigger')?.focus();
    }
}

onBeforeUnmount(() => document.removeEventListener('mousedown', closeOnOutside));
</script>

<template>
    <div ref="root" class="row-actions" @keydown="onKeydown">
        <button class="row-actions-trigger" type="button" :aria-expanded="open" aria-haspopup="menu" @click="toggle">
            {{ label ?? 'Acciones' }} <span aria-hidden="true">⋯</span>
        </button>
        <div v-if="open" class="row-actions-menu" role="menu">
            <button
                v-for="action in actions"
                :key="action.key"
                type="button"
                role="menuitem"
                :disabled="action.disabled"
                :class="{ danger: action.tone === 'danger' }"
                @click="choose(action.key)"
            >{{ action.label }}</button>
        </div>
    </div>
</template>
