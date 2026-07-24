<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    detail: string;
    action?: string;
}>();

defineEmits<{ activate: [] }>();
</script>

<template>
    <button v-if="action" class="metric-block metric-action" type="button" @click="$emit('activate')">
        <span>{{ label }}</span><strong>{{ value }}</strong><small>{{ detail }}</small>
        <b>{{ action }} <span aria-hidden="true">→</span></b>
    </button>
    <article v-else class="metric-block">
        <span>{{ label }}</span><strong>{{ value }}</strong><small>{{ detail }}</small>
    </article>
</template>
