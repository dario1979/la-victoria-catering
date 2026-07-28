<script setup lang="ts">
withDefaults(defineProps<{ filtered?: boolean; entityLabel?: string; actionLabel?: string }>(), {
    entityLabel: 'registro',
    actionLabel: '',
});
defineEmits<{ clear: []; create: [] }>();
</script>

<template>
    <div class="data-table-state">
        <strong>{{ filtered ? 'No encontramos coincidencias' : `Todavía no hay ${entityLabel}s` }}</strong>
        <p>{{ filtered ? 'Probá con otra búsqueda o limpiá los filtros.' : `Los nuevos ${entityLabel}s aparecerán en este listado.` }}</p>
        <button v-if="filtered" class="text-button" type="button" @click="$emit('clear')">Limpiar búsqueda y filtros</button>
        <button v-else-if="actionLabel" class="primary" type="button" @click="$emit('create')">{{ actionLabel }}</button>
    </div>
</template>
