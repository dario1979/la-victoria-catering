<script setup lang="ts">
import type { DataTableFilter } from '../../types';

defineProps<{ filters: DataTableFilter[]; modelValue: Record<string, string>; expanded?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>]; clear: [] }>();

function update(current: Record<string, string>, key: string, value: string) {
    emit('update:modelValue', { ...current, [key]: value });
}
</script>

<template>
    <div v-if="filters.length" class="data-table-filters" :class="{ expanded }" role="group" aria-label="Filtros del listado">
        <label v-for="filter in filters" :key="filter.key">
            <span>{{ filter.label }}</span>
            <select :value="modelValue[filter.key] ?? ''" @change="update(modelValue, filter.key, ($event.target as HTMLSelectElement).value)">
                <option value="">Todos</option>
                <option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </label>
        <button v-if="Object.values(modelValue).some(Boolean)" class="text-button" type="button" @click="$emit('clear')">Limpiar filtros</button>
    </div>
</template>
