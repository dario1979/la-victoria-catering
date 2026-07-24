<script setup lang="ts">
defineProps<{ filtered?: boolean }>();
defineEmits<{ clear: [] }>();
</script>

<template>
    <div class="data-table-state">
        <strong>{{ filtered ? 'No encontramos coincidencias' : 'Todavía no hay registros' }}</strong>
        <p>{{ filtered ? 'Probá con otra búsqueda o limpiá los filtros.' : 'Los nuevos registros aparecerán en este listado.' }}</p>
        <button v-if="filtered" class="text-button" type="button" @click="$emit('clear')">Limpiar búsqueda y filtros</button>
    </div>
</template>
