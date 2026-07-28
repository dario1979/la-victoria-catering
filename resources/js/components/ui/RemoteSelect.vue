<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { api, errorMessages } from '../../api';

const props = withDefaults(defineProps<{
    modelValue: number | null;
    endpoint: string;
    sort?: string;
    labelKey: string;
    secondaryKey?: string;
    selectedLabel?: string;
    placeholder?: string;
    disabled?: boolean;
    name?: string;
    required?: boolean;
    filters?: Record<string, string>;
}>(), {
    sort: 'id',
    secondaryKey: '',
    selectedLabel: '',
    placeholder: 'Buscar…',
    disabled: false,
    name: '',
    required: false,
    filters: () => ({}),
});

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
    selected: [row: Record<string, unknown> | null];
}>();

const search = ref('');
const options = ref<Record<string, unknown>[]>([]);
const open = ref(false);
const loading = ref(false);
const failures = ref<string[]>([]);
const activeIndex = ref(0);
const chosenLabel = ref(props.selectedLabel);
const listboxId = useId();
let debounce: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

const inputValue = computed(() => open.value ? search.value : chosenLabel.value);

function value(row: Record<string, unknown>, path: string) {
    return path.split('.').reduce<unknown>((current, key) => (
        current && typeof current === 'object' ? (current as Record<string, unknown>)[key] : undefined
    ), row);
}

function label(row: Record<string, unknown>) {
    const primary = String(value(row, props.labelKey) ?? `#${row.id}`);
    const secondary = props.secondaryKey ? value(row, props.secondaryKey) : null;
    return secondary ? `${primary} · ${secondary}` : primary;
}

async function load() {
    const request = ++sequence;
    loading.value = true;
    failures.value = [];
    try {
        const response = await api.page<Record<string, unknown>>(props.endpoint, {
            page: 1, per_page: 10, search: search.value, sort: props.sort, direction: 'asc', filters: props.filters,
        });
        if (request !== sequence) return;
        options.value = response.data;
        activeIndex.value = 0;
    } catch (error) {
        if (request !== sequence) return;
        failures.value = errorMessages(error);
    } finally {
        if (request === sequence) loading.value = false;
    }
}

function show() {
    if (props.disabled) return;
    open.value = true;
    search.value = '';
    void load();
}

function updateSearch(value: string) {
    search.value = value;
    clearTimeout(debounce);
    debounce = setTimeout(load, 300);
}

function choose(row: Record<string, unknown>) {
    chosenLabel.value = label(row);
    emit('update:modelValue', Number(row.id));
    emit('selected', row);
    open.value = false;
}

function clear() {
    chosenLabel.value = '';
    search.value = '';
    emit('update:modelValue', null);
    emit('selected', null);
}

function onKeydown(event: KeyboardEvent) {
    if (!open.value && ['ArrowDown', 'Enter'].includes(event.key)) {
        event.preventDefault();
        show();
        return;
    }
    if (!open.value) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, options.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && options.value[activeIndex.value]) {
        event.preventDefault();
        choose(options.value[activeIndex.value]);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        open.value = false;
    }
}

watch(() => props.selectedLabel, (next) => {
    if (next) chosenLabel.value = next;
});
watch(() => props.modelValue, (next) => {
    if (next === null) clear();
});
onBeforeUnmount(() => clearTimeout(debounce));
</script>

<template>
    <div class="remote-select" :class="{ 'is-open': open }">
        <div class="remote-select-control">
            <input
                role="combobox"
                autocomplete="off"
                :aria-expanded="open"
                aria-autocomplete="list"
                :aria-controls="listboxId"
                :aria-activedescendant="open && options[activeIndex] ? `${listboxId}-option-${options[activeIndex].id}` : undefined"
                :name="name"
                :required="required"
                :value="inputValue"
                :placeholder="placeholder"
                :disabled="disabled"
                @focus="show"
                @input="updateSearch(($event.target as HTMLInputElement).value)"
                @keydown="onKeydown"
            >
            <button v-if="modelValue" class="remote-select-clear" type="button" aria-label="Limpiar selección" @click="clear">×</button>
        </div>
        <div v-if="open" class="remote-select-popover">
            <p v-if="loading" role="status">Buscando…</p>
            <p v-else-if="failures.length" role="alert">{{ failures.join(' ') }}</p>
            <p v-else-if="!options.length">No encontramos opciones.</p>
            <ul v-else :id="listboxId" role="listbox">
                <li v-for="(option, index) in options" :key="String(option.id)">
                    <button
                        :id="`${listboxId}-option-${option.id}`"
                        type="button"
                        role="option"
                        :aria-selected="Number(option.id) === modelValue"
                        :class="{ active: index === activeIndex }"
                        @mousedown.prevent="choose(option)"
                    >{{ label(option) }}</button>
                </li>
            </ul>
        </div>
    </div>
</template>
