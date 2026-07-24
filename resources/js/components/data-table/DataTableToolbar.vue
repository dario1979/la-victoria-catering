<script setup lang="ts">
import DataTableExportButton from './DataTableExportButton.vue';

defineProps<{ search: string; loading?: boolean; exporting?: boolean; canExport?: boolean }>();
defineEmits<{ 'update:search': [value: string]; refresh: []; export: [] }>();
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
            <button class="secondary table-action" type="button" :disabled="loading" @click="$emit('refresh')">Actualizar</button>
            <DataTableExportButton :busy="exporting" :allowed="canExport" @export="$emit('export')" />
        </div>
    </div>
</template>
