<script setup lang="ts">
import DataTableExportButton from './DataTableExportButton.vue';

defineProps<{
    search: string;
    loading?: boolean;
    exporting?: boolean;
    canExport?: boolean;
    hasFilters?: boolean;
    filtersOpen?: boolean;
    activeFilterCount?: number;
}>();
defineEmits<{
    'update:search': [value: string];
    refresh: [];
    export: [];
    'toggle-filters': [];
    clear: [];
}>();
</script>

<template>
    <div class="data-table-toolbar">
        <label class="table-search">
            <span>Buscar</span>
            <input
                type="search"
                :value="search"
                placeholder="Buscar en este listado"
                @input="$emit('update:search', ($event.target as HTMLInputElement).value)"
            >
        </label>
        <div class="table-toolbar-actions">
            <button
                v-if="hasFilters"
                class="secondary table-action"
                type="button"
                :aria-expanded="filtersOpen"
                @click="$emit('toggle-filters')"
            >
                Filtros
                <span v-if="activeFilterCount" class="filter-count">{{ activeFilterCount }}</span>
            </button>
            <button v-if="search || activeFilterCount" class="text-button table-action" type="button" @click="$emit('clear')">Limpiar</button>
            <button class="secondary table-action" type="button" :disabled="loading" @click="$emit('refresh')">Actualizar</button>
            <DataTableExportButton :busy="exporting" :allowed="canExport" @export="$emit('export')" />
        </div>
    </div>
</template>
