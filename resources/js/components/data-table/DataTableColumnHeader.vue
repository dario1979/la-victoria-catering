<script setup lang="ts">
defineProps<{ label: string; active?: boolean; direction?: 'asc' | 'desc'; sortable?: boolean }>();
defineEmits<{ sort: [] }>();
</script>

<template>
    <button
        v-if="sortable"
        class="column-sort"
        type="button"
        :aria-label="active ? `${label}, orden ${direction === 'asc' ? 'ascendente' : 'descendente'}. Cambiar orden.` : `Ordenar por ${label}`"
        @click="$emit('sort')"
    >
        {{ label }} <span aria-hidden="true">{{ active ? (direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
    </button>
    <span v-else>{{ label }}</span>
</template>
