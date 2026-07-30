<!--
THESIS: Una mesa de preparación que hace visible cada decisión antes de incorporar datos al sistema.
OWN-WORLD: Obrador editorial operativo; pasos numerados, papel cálido y evidencia tabular contenida.
STORY: Elegir plantilla → analizar archivo → mapear columnas → validar → confirmar el lote.
FIRST VIEWPORT: Propósito, límites y selector de tipo; ninguna acción irreversible aparece antes del dry-run.
FORM: Flujo lineal en una sola página, con estado persistente del lote y confirmación explícita.
-->
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { api, errorMessages, idempotencyKey } from '../api';
import { formatDateTime } from '../dates';
import ConfirmActionModal from './ui/ConfirmActionModal.vue';
import StatusBadge from './ui/StatusBadge.vue';

type ImportType = 'customers' | 'products' | 'suppliers' | 'supplier_products' | 'locations' | 'initial_stock' | 'recipes';
interface Definition {
    label: string;
    fields: string[];
    required: string[];
    example: string[];
}
interface ImportError { row: number; field: string; message: string }
interface Batch {
    id: number;
    uuid: string;
    type: ImportType;
    status: string;
    original_name: string;
    headers: string[];
    mapping?: Record<string, string>;
    preview?: Array<Record<string, string>>;
    errors?: ImportError[];
    total_rows: number;
    valid_rows: number;
    error_rows: number;
    created_at: string;
    completed_at?: string | null;
}

const props = defineProps<{ online: boolean; branchName: string }>();
const emit = defineEmits<{ notice: [message: string]; error: [title: string, messages: string[]] }>();
const definitions = ref<Record<ImportType, Definition> | null>(null);
const recent = ref<Batch[]>([]);
const selectedType = ref<ImportType>('customers');
const selectedFile = ref<File | null>(null);
const batch = ref<Batch | null>(null);
const mapping = reactive<Record<string, string>>({});
const busy = ref(false);
const loading = ref(true);
const loadFailed = ref(false);
const pollingIssue = ref('');
const polling = ref<number | null>(null);
const confirmation = reactive({
    open: false,
    action: null as 'confirm' | 'rollback' | null,
});
let confirmKey = idempotencyKey('import-confirm');
let rollbackKey = idempotencyKey('import-rollback');
let uploadKey = idempotencyKey('import-upload');

const definition = computed(() => definitions.value?.[batch.value?.type ?? selectedType.value] ?? null);
const canDryRun = computed(() => batch.value?.status && ['analyzed', 'invalid', 'validated'].includes(batch.value.status));
const canConfirm = computed(() => batch.value?.status === 'validated' && batch.value.error_rows === 0);
const previewFields = computed(() => definition.value?.fields ?? []);
const hasEvidence = computed(() => Boolean(batch.value && (
    batch.value.preview?.length
    || batch.value.errors?.length
    || ['queued', 'processing', 'completed', 'failed', 'rolled_back'].includes(batch.value.status)
)));
const statusLabel = (status: string) => ({
    analyzed: 'Analizado',
    invalid: 'Con errores',
    validated: 'Validado',
    queued: 'En cola',
    processing: 'Procesando',
    completed: 'Completado',
    failed: 'Fallido',
    rolled_back: 'Revertido',
} as Record<string, string>)[status] ?? status;

async function load() {
    loading.value = true;
    loadFailed.value = false;
    try {
        definitions.value = await api.get<Record<ImportType, Definition>>('/imports/definitions');
        await refreshRecent();
        const requestedBatch = Number(new URL(window.location.href).searchParams.get('import_batch'));
        if (Number.isInteger(requestedBatch) && requestedBatch > 0) {
            const restored = await openBatch(requestedBatch);
            if (!restored) clearBatchQuery();
        }
    } catch (error) {
        loadFailed.value = true;
        emit('error', 'No pudimos preparar las importaciones', errorMessages(error));
    } finally {
        loading.value = false;
    }
}

function chooseFile(event: Event) {
    selectedFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    uploadKey = idempotencyKey('import-upload');
}

async function downloadTemplate() {
    if (!definition.value) return;
    await perform(async () => {
        await api.download(`/import-templates/${selectedType.value}`, `plantilla-${selectedType.value}.csv`);
    });
}

async function upload() {
    if (!selectedFile.value) return;
    await perform(async () => {
        const body = new FormData();
        body.append('type', selectedType.value);
        body.append('file', selectedFile.value!);
        const response = await api.uploadRaw<{ data: Batch }>(`/imports`, body, uploadKey);
        setActiveBatch(response.data);
        emit('notice', `Archivo analizado: ${response.data.total_rows} filas. Revisá el mapeo antes del dry-run.`);
        await refreshRecent();
    });
}

async function dryRun() {
    if (!batch.value) return;
    await perform(async () => {
        batch.value = await api.post<Batch>(`/imports/${batch.value!.id}/dry-run`, { mapping: { ...mapping } });
        emit('notice', batch.value.error_rows === 0
            ? `Dry-run aprobado: ${batch.value.valid_rows} filas listas.`
            : `Dry-run detenido: ${batch.value.error_rows} filas requieren corrección.`);
        await refreshRecent();
    });
}

async function confirm() {
    if (!batch.value || !canConfirm.value) return;
    await perform(async () => {
        batch.value = await api.post<Batch>(`/imports/${batch.value!.id}/confirm`, {}, confirmKey);
        confirmation.open = false;
        emit('notice', 'Lote confirmado y enviado a la cola.');
        startPolling();
    });
}

async function rollback() {
    if (!batch.value || batch.value.status !== 'completed') return;
    await perform(async () => {
        batch.value = await api.post<Batch>(`/imports/${batch.value!.id}/rollback`, {}, rollbackKey);
        confirmation.open = false;
        emit('notice', 'Rollback lógico registrado y auditado.');
        await refreshRecent();
    });
}

async function downloadErrors() {
    if (!batch.value) return;
    await perform(() => api.download(`/imports/${batch.value!.id}/errors`, `import-errors-${batch.value!.uuid}.csv`));
}

async function refreshRecent() {
    const response = await api.getRaw<{ data: Batch[] }>('/imports?per_page=10');
    recent.value = response.data;
}

async function openBatch(id: number): Promise<boolean> {
    if (busy.value || !props.online) return false;
    let opened = false;
    await perform(async () => {
        const selected = await api.get<Batch>(`/imports/${id}`);
        setActiveBatch(selected);
        opened = true;
    });

    return opened;
}

function setActiveBatch(selected: Batch) {
    batch.value = selected;
    selectedType.value = selected.type;
    Object.keys(mapping).forEach(key => delete mapping[key]);
    Object.assign(mapping, selected.mapping ?? {});
    confirmKey = idempotencyKey(`import-confirm-${selected.uuid}`);
    rollbackKey = idempotencyKey(`import-rollback-${selected.uuid}`);
    pollingIssue.value = '';
    const url = new URL(window.location.href);
    url.searchParams.set('import_batch', String(selected.id));
    window.history.replaceState(window.history.state, '', url);
    if (['queued', 'processing'].includes(selected.status)) startPolling();
}

function clearBatch() {
    stopPolling();
    batch.value = null;
    selectedFile.value = null;
    pollingIssue.value = '';
    Object.keys(mapping).forEach(key => delete mapping[key]);
    uploadKey = idempotencyKey('import-upload');
    clearBatchQuery();
}

function clearBatchQuery() {
    const url = new URL(window.location.href);
    url.searchParams.delete('import_batch');
    window.history.replaceState(window.history.state, '', url);
}

function requestConfirmation(action: 'confirm' | 'rollback') {
    confirmation.action = action;
    confirmation.open = true;
}

async function executeConfirmation() {
    if (confirmation.action === 'confirm') await confirm();
    if (confirmation.action === 'rollback') await rollback();
}

function startPolling() {
    stopPolling();
    polling.value = window.setInterval(async () => {
        if (!batch.value || !['queued', 'processing'].includes(batch.value.status)) {
            stopPolling();
            return;
        }
        if (!props.online) {
            pollingIssue.value = 'Seguimiento pausado sin conexión. Se reanudará automáticamente.';
            return;
        }
        try {
            batch.value = await api.get<Batch>(`/imports/${batch.value.id}`);
            pollingIssue.value = '';
            if (!['queued', 'processing'].includes(batch.value.status)) {
                stopPolling();
                await refreshRecent();
            }
        } catch {
            pollingIssue.value = 'No pudimos actualizar el estado. Seguiremos intentando automáticamente.';
        }
    }, 2000);
}

function stopPolling() {
    if (polling.value !== null) window.clearInterval(polling.value);
    polling.value = null;
}

async function perform(action: () => Promise<void>) {
    if (!props.online || busy.value) return;
    busy.value = true;
    try {
        await action();
    } catch (error) {
        emit('error', 'No pudimos avanzar la importación', errorMessages(error));
    } finally {
        busy.value = false;
    }
}

onMounted(load);
onBeforeUnmount(stopPolling);
watch(() => props.online, (online) => {
    if (online && batch.value && ['queued', 'processing'].includes(batch.value.status)) startPolling();
});
</script>

<template>
    <section class="page import-page">
        <header class="list-page-header">
            <div>
                <p class="section-kicker">Carga inicial · {{ branchName }}</p>
                <h2>Prepará los datos antes de incorporarlos.</h2>
                <p>CSV o XLSX, hasta 5 MB y 5.000 filas. El tenant y la sucursal siempre provienen de tu sesión.</p>
            </div>
        </header>

        <p v-if="!online" class="offline-banner" role="status">Modo consulta: recuperá conexión para subir, validar o confirmar un lote.</p>
        <p v-if="loading" class="loading-state" role="status">Preparando definiciones y lotes recientes…</p>
        <div v-else-if="loadFailed" class="error-state" role="alert">
            <strong>No pudimos cargar esta mesa de importación.</strong>
            <button class="secondary" type="button" :disabled="!online" @click="load">Reintentar</button>
        </div>

        <div v-else class="import-grid">
            <section class="import-step">
                <div class="step-number" aria-hidden="true">01</div>
                <div>
                    <p class="section-kicker">Plantilla</p>
                    <h3>Elegí qué vas a cargar</h3>
                    <label>Tipo de datos
                        <select v-model="selectedType" :disabled="busy || Boolean(batch)">
                            <option v-for="(item, key) in definitions" :key="key" :value="key">{{ item.label }}</option>
                        </select>
                    </label>
                    <button class="secondary" type="button" :disabled="busy || !online || !definition" @click="downloadTemplate">
                        Descargar plantilla CSV
                    </button>
                </div>
            </section>

            <section class="import-step">
                <div class="step-number" aria-hidden="true">02</div>
                <div>
                    <p class="section-kicker">Archivo temporal</p>
                    <h3>Analizá encabezados y tamaño</h3>
                    <label>Archivo CSV o XLSX
                        <input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :disabled="busy" @change="chooseFile">
                    </label>
                    <p v-if="selectedFile" class="file-evidence">{{ selectedFile.name }} · {{ Math.ceil(selectedFile.size / 1024) }} KB</p>
                    <button class="primary" type="button" :disabled="busy || !online || !selectedFile" @click="upload">
                        {{ busy ? 'Analizando…' : 'Subir y analizar' }}
                    </button>
                </div>
            </section>
        </div>

        <section v-if="batch && definition" class="mapping-panel">
            <div class="mapping-head">
                <div>
                    <p class="section-kicker">03 · Mapeo</p>
                    <h3>{{ batch.original_name }}</h3>
                    <p>{{ batch.total_rows }} filas · {{ definition.label }} · {{ branchName }}</p>
                </div>
                <div class="batch-head-actions">
                    <StatusBadge :status="batch.status" :label="statusLabel(batch.status)" />
                    <button class="secondary compact-action" type="button" :disabled="busy" @click="clearBatch">Preparar otro lote</button>
                </div>
            </div>
            <div class="mapping-grid">
                <label v-for="fieldName in definition.fields" :key="fieldName">
                    {{ fieldName }} <span v-if="definition.required.includes(fieldName)" aria-label="obligatorio">*</span>
                    <select v-model="mapping[fieldName]" :required="definition.required.includes(fieldName)" :disabled="busy">
                        <option value="">No importar</option>
                        <option v-for="header in batch.headers" :key="header" :value="header">{{ header }}</option>
                    </select>
                </label>
            </div>
            <div class="mapping-actions">
                <button class="secondary" type="button" :disabled="busy || !online || !canDryRun" @click="dryRun">Ejecutar dry-run</button>
                <button class="primary" type="button" :disabled="busy || !online || !canConfirm" @click="requestConfirmation('confirm')">Confirmar e importar</button>
            </div>
        </section>

        <section v-if="hasEvidence && batch" class="result-panel">
            <div class="result-head">
                <div>
                    <p class="section-kicker">04 · Evidencia</p>
                    <h3>Vista previa del dry-run</h3>
                </div>
                <div class="result-counts">
                    <span><strong>{{ batch.valid_rows }}</strong> válidas</span>
                    <span :class="{ danger: batch.error_rows }"><strong>{{ batch.error_rows }}</strong> con error</span>
                </div>
            </div>
            <div v-if="batch.preview?.length" class="table-wrap">
                <table aria-label="Vista previa de importación">
                    <thead><tr><th v-for="fieldName in previewFields" :key="fieldName">{{ fieldName }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(row, index) in batch.preview" :key="index">
                            <td v-for="fieldName in previewFields" :key="fieldName">{{ row[fieldName] || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="batch.errors?.length" class="error-summary" role="status">
                <strong>Corregí el archivo antes de confirmar.</strong>
                <p>El informe enumera fila, campo y motivo sin replicar el valor original.</p>
                <button class="secondary" type="button" :disabled="busy || !online" @click="downloadErrors">Descargar errores CSV</button>
            </div>
            <div v-else-if="['queued', 'processing'].includes(batch.status)" class="process-summary" role="status" aria-live="polite">
                <strong>{{ batch.status === 'queued' ? 'Lote en cola' : 'Importación en proceso' }}</strong>
                <p>Podés salir de esta pantalla: el lote queda guardado y se puede retomar desde la trazabilidad.</p>
                <p v-if="pollingIssue" class="polling-issue">{{ pollingIssue }}</p>
            </div>
            <div v-else-if="batch.status === 'failed'" class="error-summary" role="alert">
                <strong>La importación no pudo completarse.</strong>
                <p>No se incorporaron filas parcialmente. Prepará un lote nuevo después de revisar el archivo.</p>
            </div>
            <div v-else-if="batch.status === 'completed'" class="completion-summary" role="status">
                <strong>Importación completada</strong>
                <p>{{ batch.valid_rows }} filas incorporadas · {{ formatDateTime(batch.completed_at) }}</p>
                <button class="secondary" type="button" :disabled="busy || !online" @click="requestConfirmation('rollback')">Solicitar rollback seguro</button>
            </div>
            <div v-else-if="batch.status === 'rolled_back'" class="process-summary" role="status">
                <strong>Rollback completado</strong>
                <p>La reversión quedó registrada y auditada sin alterar el historial original.</p>
            </div>
        </section>

        <section class="recent-panel">
            <div>
                <p class="section-kicker">Trazabilidad</p>
                <h3>Lotes recientes de la sucursal</h3>
            </div>
            <p v-if="loading" class="muted" role="status">Cargando lotes recientes…</p>
            <div v-else-if="recent.length" class="recent-list">
                <article v-for="item in recent" :key="item.id" :class="{ active: batch?.id === item.id }">
                    <div><strong>{{ definitions?.[item.type]?.label ?? item.type }}</strong><span>{{ item.original_name }}</span></div>
                    <div>
                        <StatusBadge :status="item.status" :label="statusLabel(item.status)" />
                        <small>{{ formatDateTime(item.created_at) }}</small>
                        <button class="secondary compact-action" type="button" :disabled="busy || !online || batch?.id === item.id" @click="openBatch(item.id)">
                            {{ batch?.id === item.id ? 'Lote abierto' : 'Abrir lote' }}
                        </button>
                    </div>
                </article>
            </div>
            <p v-else-if="!loadFailed" class="muted">Todavía no hay lotes de importación en esta sucursal.</p>
        </section>

        <ConfirmActionModal
            :open="confirmation.open"
            :title="confirmation.action === 'rollback' ? 'Confirmar rollback seguro' : 'Confirmar importación'"
            :description="confirmation.action === 'rollback'
                ? 'La reversión sólo avanzará si los registros siguen sin dependencias incompatibles.'
                : 'El lote validado se enviará a la cola y ya no podrá editarse.'"
            :entity="batch ? `${batch.original_name} · ${definition?.label ?? batch.type}` : ''"
            :confirm-label="confirmation.action === 'rollback' ? 'Ejecutar rollback' : 'Importar lote'"
            :tone="confirmation.action === 'rollback' ? 'danger' : 'primary'"
            :busy="busy"
            @close="confirmation.open = false"
            @confirm="executeConfirmation"
        >
            <ul v-if="batch" class="confirm-details">
                <li>Sucursal: {{ branchName }}</li>
                <li>{{ batch.valid_rows }} filas válidas</li>
                <li v-if="confirmation.action === 'confirm'">La ejecución será transaccional e idempotente.</li>
                <li v-else>Los movimientos de stock se compensarán sin borrar evidencia.</li>
            </ul>
        </ConfirmActionModal>
    </section>
</template>

<style scoped>
.import-page { display: grid; gap: var(--space-6); }
.import-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-5); }
.import-step, .mapping-panel, .result-panel, .recent-panel {
    border: 1px solid var(--line); border-radius: var(--radius-lg); background: var(--surface);
    padding: clamp(1rem, 2.5vw, 1.5rem);
}
.import-step { display: grid; grid-template-columns: auto 1fr; gap: var(--space-4); }
.import-step > div:last-child { display: grid; align-content: start; gap: var(--space-3); }
.import-step h3, .mapping-panel h3, .result-panel h3, .recent-panel h3 { margin: 0; font-family: var(--font-display); font-size: 1.35rem; }
.step-number { color: var(--terracotta); font-family: var(--font-display); font-size: 2rem; line-height: 1; }
.file-evidence { margin: 0; color: var(--ink-muted); font-size: .82rem; }
.mapping-head, .result-head { display: flex; align-items: start; justify-content: space-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.batch-head-actions { display: grid; justify-items: end; gap: var(--space-2); }
.mapping-head p, .completion-summary p, .error-summary p, .process-summary p { margin: var(--space-1) 0 0; color: var(--ink-muted); }
.mapping-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-3); }
.mapping-actions { display: flex; justify-content: flex-end; gap: var(--space-3); border-top: 1px solid var(--line); margin-top: var(--space-5); padding-top: var(--space-4); }
.result-counts { display: flex; gap: var(--space-4); color: var(--ink-muted); font-size: .82rem; }
.result-counts strong { color: var(--ink); font-family: var(--font-display); font-size: 1.5rem; }
.result-counts .danger, .result-counts .danger strong { color: var(--danger); }
.error-summary, .completion-summary, .process-summary { margin-top: var(--space-4); border: 1px solid var(--line); background: var(--surface-muted); padding: var(--space-4); }
.error-summary button, .completion-summary button { margin-top: var(--space-3); }
.polling-issue { color: var(--danger) !important; }
.loading-state, .error-state { border: 1px solid var(--line); border-radius: var(--radius-lg); background: var(--surface); padding: var(--space-4); }
.error-state { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
.recent-panel { display: grid; gap: var(--space-4); }
.recent-list { display: grid; }
.recent-list article { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); border-top: 1px solid var(--line); padding: var(--space-3) 0; }
.recent-list article.active { background: var(--surface-muted); }
.recent-list article > div { display: grid; gap: var(--space-1); }
.recent-list article > div:last-child { justify-items: end; }
.recent-list span, .recent-list small { color: var(--ink-muted); }
@media (max-width: 860px) {
    .import-grid, .mapping-grid { grid-template-columns: 1fr; }
}
@media (max-width: 560px) {
    .import-step { grid-template-columns: 1fr; }
    .mapping-head, .result-head, .mapping-actions, .recent-list article, .error-state { align-items: stretch; flex-direction: column; }
    .batch-head-actions { justify-items: stretch; }
    .mapping-actions button { width: 100%; }
    .result-counts { justify-content: space-between; }
    .recent-list article > div:last-child { justify-items: start; }
}
</style>
