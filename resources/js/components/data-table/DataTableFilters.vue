<script setup lang="ts">
import type { DataTableFilter } from '../../types';

defineProps<{ filters: DataTableFilter[]; modelValue: Record<string, string> }>();
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>] }>();

function update(current: Record<string, string>, key: string, value: string) {
    emit('update:modelValue', { ...current, [key]: value });
}
</script>

<template>
    <div v-if="filters.length" class="data-table-filters" role="group" aria-label="Filtros del listado">
        <label v-for="filter in filters" :key="filter.key">
            <span>{{ filter.label }}</span>
            <select :value="modelValue[filter.key] ?? ''" @change="update(modelValue, filter.key, ($event.target as HTMLSelectElement).value)">
                <option value="">Todos</option>
                <option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </label>
    </div>
</template>
