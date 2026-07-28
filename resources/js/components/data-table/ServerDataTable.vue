<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { api, errorMessages } from '../../api';
import type { DataTableColumn, DataTableFilter, DataTableMeta, DataTableQuery } from '../../types';
import DataTableColumnHeader from './DataTableColumnHeader.vue';
import DataTableEmptyState from './DataTableEmptyState.vue';
import DataTableFilters from './DataTableFilters.vue';
import DataTablePagination from './DataTablePagination.vue';
import DataTableSkeleton from './DataTableSkeleton.vue';
import DataTableToolbar from './DataTableToolbar.vue';

const props = withDefaults(defineProps<{
    endpoint: string;
    columns: DataTableColumn[];
    filters?: DataTableFilter[];
    initialSort?: string;
    initialDirection?: 'asc' | 'desc';
    initialPerPage?: number;
    persistInUrl?: boolean;
    canExport?: boolean;
    selectable?: boolean;
    label?: string;
    emptyLabel?: string;
    emptyAction?: string;
    highlightedId?: string | number | null;
}>(), {
    filters: () => [],
    initialSort: 'id',
    initialDirection: 'desc',
    initialPerPage: 25,
    persistInUrl: false,
    canExport: true,
    selectable: false,
    label: 'Listado',
    emptyLabel: 'registro',
    emptyAction: '',
    highlightedId: null,
});

const emit = defineEmits<{
    loaded: [rows: Record<string, unknown>[]];
    error: [messages: string[]];
    selection: [rows: Record<string, unknown>[]];
    emptyAction: [];
}>();

const rows = ref<Record<string, unknown>[]>([]);
const loading = ref(true);
const exporting = ref(false);
const failures = ref<string[]>([]);
const announcement = ref('');
const filtersOpen = ref(false);
const selectedIds = ref<Array<string | number>>([]);
const meta = reactive<DataTableMeta>({
    current_page: 1, from: null, last_page: 1, per_page: props.initialPerPage, to: null, total: 0,
});
const query = reactive<DataTableQuery>({
    page: 1,
    per_page: props.initialPerPage,
    search: '',
    sort: props.initialSort,
    direction: props.initialDirection,
    filters: {},
});
let debounce: ReturnType<typeof setTimeout> | undefined;
let requestSequence = 0;

const hasCriteria = computed(() => query.search !== '' || Object.values(query.filters).some(Boolean));
const activeFilterCount = computed(() => Object.values(query.filters).filter(Boolean).length);
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(row.id as string | number)));

function readUrl() {
    if (!props.persistInUrl) return;
    const params = new URLSearchParams(location.search);
    const prefix = urlPrefix();
    query.page = Math.max(1, Number(params.get(`${prefix}page`) ?? 1));
    query.per_page = [10, 25, 50, 100].includes(Number(params.get(`${prefix}per_page`))) ? Number(params.get(`${prefix}per_page`)) : props.initialPerPage;
    query.search = params.get(`${prefix}search`) ?? '';
    const persistedSort = params.get(`${prefix}sort`);
    query.sort = persistedSort && props.columns.some((column) => column.key === persistedSort && column.sortable) ? persistedSort : props.initialSort;
    query.direction = params.get(`${prefix}direction`) === 'asc' ? 'asc' : props.initialDirection;
    props.filters.forEach((filter) => {
        query.filters[filter.key] = params.get(`${prefix}filter_${filter.key}`) ?? '';
    });
}

function writeUrl() {
    if (!props.persistInUrl) return;
    const url = new URL(location.href);
    const prefix = urlPrefix();
    ['page', 'per_page', 'search', 'sort', 'direction'].forEach((key) => url.searchParams.delete(`${prefix}${key}`));
    props.filters.forEach((filter) => url.searchParams.delete(`${prefix}filter_${filter.key}`));
    url.searchParams.set(`${prefix}page`, String(query.page));
    url.searchParams.set(`${prefix}per_page`, String(query.per_page));
    if (query.search) url.searchParams.set(`${prefix}search`, query.search);
    url.searchParams.set(`${prefix}sort`, query.sort);
    url.searchParams.set(`${prefix}direction`, query.direction);
    Object.entries(query.filters).forEach(([key, value]) => {
        if (value) url.searchParams.set(`${prefix}filter_${key}`, value);
    });
    history.replaceState(null, '', url);
}

function urlPrefix() {
    return `dt_${props.endpoint.replace(/[^a-z0-9]+/gi, '_')}_`;
}

async function load() {
    const sequence = ++requestSequence;
    loading.value = true;
    failures.value = [];
    try {
        const response = await api.page<Record<string, unknown>>(props.endpoint, query);
        if (sequence !== requestSequence) return;
        rows.value = response.data;
        Object.assign(meta, response.meta);
        selectedIds.value = selectedIds.value.filter((id) => rows.value.some((row) => row.id === id));
        writeUrl();
        announcement.value = `${response.meta.total} registro${response.meta.total === 1 ? '' : 's'} en el listado.`;
        emit('loaded', rows.value);
    } catch (error) {
        if (sequence !== requestSequence) return;
        failures.value = errorMessages(error);
        emit('error', failures.value);
    } finally {
        if (sequence === requestSequence) loading.value = false;
    }
}

function scheduleLoad() {
    clearTimeout(debounce);
    query.page = 1;
    debounce = setTimeout(load, 280);
}

function sort(column: DataTableColumn) {
    if (!column.sortable) return;
    if (query.sort === column.key) query.direction = query.direction === 'asc' ? 'desc' : 'asc';
    else {
        query.sort = column.key;
        query.direction = 'asc';
    }
    query.page = 1;
    void load();
}

function setPage(page: number) {
    query.page = page;
    void load();
}

function setPerPage(perPage: number) {
    query.page = 1;
    query.per_page = perPage;
    void load();
}

function clearCriteria() {
    query.search = '';
    query.filters = {};
    query.page = 1;
    void load();
}

async function exportRows() {
    exporting.value = true;
    failures.value = [];
    try {
        await api.exportTable(props.endpoint, query);
        announcement.value = 'El archivo Excel se descargó correctamente.';
    } catch (error) {
        failures.value = errorMessages(error);
    } finally {
        exporting.value = false;
    }
}

function value(row: Record<string, unknown>, column: DataTableColumn) {
    if (column.render) return column.render(row);
    return column.key.split('.').reduce<unknown>((current, key) => (
        current && typeof current === 'object' ? (current as Record<string, unknown>)[key] : undefined
    ), row) ?? '—';
}

function toggleAll() {
    selectedIds.value = allSelected.value ? [] : rows.value.map((row) => row.id as string | number);
}

watch(() => query.search, scheduleLoad);
watch(() => query.filters, scheduleLoad, { deep: true });
watch(selectedIds, () => emit('selection', rows.value.filter((row) => selectedIds.value.includes(row.id as string | number))), { deep: true });
onMounted(() => {
    readUrl();
    void load();
});
onBeforeUnmount(() => clearTimeout(debounce));
defineExpose({ refresh: load });
</script>

<template>
    <section class="server-data-table" :class="{ 'is-updating': loading && rows.length }" :aria-busy="loading">
        <p class="sr-only" aria-live="polite">{{ loading ? 'Actualizando listado.' : announcement }}</p>
        <DataTableToolbar
            :search="query.search"
            :loading="loading"
            :exporting="exporting"
            :can-export="canExport"
            :active-filter-count="activeFilterCount"
            :has-filters="filters.length > 0"
            :filters-open="filtersOpen"
            @update:search="query.search = $event"
            @refresh="load"
            @export="exportRows"
            @toggle-filters="filtersOpen = !filtersOpen"
            @clear="clearCriteria"
        />
        <DataTableFilters v-model="query.filters" :filters="filters" :expanded="filtersOpen" @clear="clearCriteria" />
        <div v-if="selectedIds.length" class="bulk-action-bar" role="status">
            <span>{{ selectedIds.length }} seleccionado{{ selectedIds.length === 1 ? '' : 's' }}</span>
            <slot name="bulk-actions" :selected-ids="selectedIds" :refresh="load" />
        </div>
        <DataTableSkeleton v-if="loading && !rows.length && !failures.length" />
        <div v-if="failures.length" class="data-table-state error" role="alert">
            <strong>No pudimos cargar el listado</strong>
            <p>{{ failures.join(' ') }}</p>
            <button class="secondary" type="button" @click="load">Reintentar</button>
        </div>
        <DataTableEmptyState
            v-if="!loading && !failures.length && !rows.length"
            :filtered="hasCriteria"
            :entity-label="emptyLabel"
            :action-label="emptyAction"
            @clear="clearCriteria"
            @create="$emit('emptyAction')"
        />
        <div v-if="rows.length" class="table-wrap">
            <table :aria-label="label">
                <thead>
                    <tr>
                        <th v-if="selectable" class="selection-cell">
                            <input type="checkbox" :checked="allSelected" aria-label="Seleccionar esta página" @change="toggleAll">
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="[{ numeric: column.align === 'end' }, `priority-${column.priority ?? 'primary'}`]"
                            :aria-sort="column.sortable && query.sort === column.key ? (query.direction === 'asc' ? 'ascending' : 'descending') : undefined"
                        >
                            <DataTableColumnHeader
                                :label="column.label"
                                :sortable="column.sortable"
                                :active="query.sort === column.key"
                                :direction="query.direction"
                                @sort="sort(column)"
                            />
                        </th>
                        <th v-if="$slots.actions" class="actions-column">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="String(row.id)" :class="{ 'is-highlighted': String(row.id) === String(highlightedId) }">
                        <td v-if="selectable" class="selection-cell" data-label="Seleccionar">
                            <input v-model="selectedIds" type="checkbox" :value="row.id" :aria-label="`Seleccionar registro ${row.id}`">
                        </td>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :data-label="column.label"
                            :class="[{ numeric: column.align === 'end' }, `priority-${column.priority ?? 'primary'}`]"
                        >
                            <slot :name="`cell-${column.key}`" :row="row" :value="value(row, column)">
                                {{ value(row, column) }}
                            </slot>
                        </td>
                        <td v-if="$slots.actions" data-label="Acciones"><slot name="actions" :row="row" :refresh="load" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <DataTablePagination v-if="rows.length" :meta="meta" :loading="loading" @page="setPage" @per-page="setPerPage" />
    </section>
</template>
