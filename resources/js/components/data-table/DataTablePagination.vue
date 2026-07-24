<script setup lang="ts">
import type { DataTableMeta } from '../../types';

defineProps<{ meta: DataTableMeta; loading?: boolean }>();
defineEmits<{ page: [value: number]; 'per-page': [value: number] }>();
</script>

<template>
    <div class="data-table-pagination">
        <p>{{ meta.total ? `${meta.from}–${meta.to} de ${meta.total}` : '0 registros' }}</p>
        <label>Filas
            <select :value="meta.per_page" :disabled="loading" @change="$emit('per-page', Number(($event.target as HTMLSelectElement).value))">
                <option v-for="size in [10, 20, 50, 100]" :key="size" :value="size">{{ size }}</option>
            </select>
        </label>
        <div class="pagination-actions">
            <button type="button" :disabled="loading || meta.current_page <= 1" aria-label="Página anterior" @click="$emit('page', meta.current_page - 1)">Anterior</button>
            <span>Página {{ meta.current_page }} de {{ Math.max(meta.last_page, 1) }}</span>
            <button type="button" :disabled="loading || meta.current_page >= meta.last_page" aria-label="Página siguiente" @click="$emit('page', meta.current_page + 1)">Siguiente</button>
        </div>
    </div>
</template>
