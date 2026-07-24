<script setup lang="ts">
defineProps<{
    title: string;
    message: string;
    action?: string;
    tone?: 'neutral' | 'error';
}>();

defineEmits<{ activate: [] }>();
</script>

<template>
    <div class="data-state" :data-tone="tone ?? 'neutral'">
        <strong>{{ title }}</strong>
        <p>{{ message }}</p>
        <button v-if="action" class="text-button" type="button" @click="$emit('activate')">{{ action }}</button>
    </div>
</template>
