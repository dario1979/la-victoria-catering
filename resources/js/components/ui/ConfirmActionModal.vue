<script setup lang="ts">
import BaseModal from './BaseModal.vue';

withDefaults(defineProps<{
    open: boolean;
    title: string;
    description: string;
    entity?: string;
    confirmLabel: string;
    busy?: boolean;
    tone?: 'primary' | 'danger';
}>(), {
    entity: '',
    busy: false,
    tone: 'primary',
});

defineEmits<{ close: []; confirm: [] }>();
</script>

<template>
    <BaseModal :open="open" :title="title" :description="description" :busy="busy" @close="$emit('close')">
        <div class="confirm-summary">
            <strong v-if="entity">{{ entity }}</strong>
            <slot />
        </div>
        <template #footer>
            <button class="secondary" type="button" :disabled="busy" @click="$emit('close')">Volver</button>
            <button :class="tone === 'danger' ? 'danger-button' : 'primary'" type="button" :disabled="busy" @click="$emit('confirm')">
                {{ busy ? 'Procesando…' : confirmLabel }}
            </button>
        </template>
    </BaseModal>
</template>
