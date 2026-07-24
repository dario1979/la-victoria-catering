<script setup lang="ts">
defineProps<{ busy?: boolean; allowed?: boolean }>();
defineEmits<{ export: [] }>();
</script>

<template>
    <button v-if="allowed !== false" class="secondary table-action" type="button" :disabled="busy" @click="$emit('export')">
        {{ busy ? 'Preparando…' : 'Descargar Excel' }}
    </button>
</template>
