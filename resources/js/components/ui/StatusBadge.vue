<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status?: string | null }>();

const labels: Record<string, string> = {
    draft: 'Borrador',
    confirmed: 'Confirmado',
    in_production: 'En producción',
    ready: 'Listo',
    delivered: 'Entregado',
    cancelled: 'Cancelado',
    available: 'Disponible',
    blocked: 'Bloqueado',
    open: 'Nueva',
    acknowledged: 'Reconocida',
    resolved: 'Resuelta',
    active: 'Activo',
};

const normalized = computed(() => props.status || 'active');
const label = computed(() => labels[normalized.value] ?? normalized.value.replaceAll('_', ' '));
</script>

<template>
    <span class="status-badge" :data-status="normalized">
        <span aria-hidden="true" class="status-dot"></span>{{ label }}
    </span>
</template>
