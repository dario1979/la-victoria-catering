<script setup lang="ts">
import { formatCalendarDate, formatDateTime } from '../dates';
import { statusLabel, unitLabel } from '../operational';
import type { OperationalModuleView } from '../operational';
import StatusBadge from './ui/StatusBadge.vue';

defineProps<{
    detail: Record<string, any>;
    view: OperationalModuleView;
    mode: string;
}>();

function money(value: string | number) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(Number(value || 0));
}
</script>

<template>
    <div class="entity-detail">
        <template v-if="mode === 'production-requirements'">
            <div class="detail-lead"><StatusBadge :status="detail.can_produce ? 'ready' : 'blocked'" /><strong>{{ detail.can_produce ? 'Ingredientes disponibles' : 'Producción bloqueada por faltantes' }}</strong></div>
            <div class="responsive-detail-table">
                <table aria-label="Requerimientos de ingredientes">
                    <thead><tr><th>Ingrediente</th><th>Requerido</th><th>Disponible</th><th>Faltante</th><th>Estado</th></tr></thead>
                    <tbody><tr v-for="ingredient in detail.ingredients" :key="ingredient.snapshot_item_index">
                        <td data-label="Ingrediente">{{ ingredient.ingredient_name }}</td>
                        <td data-label="Requerido" class="numeric">{{ ingredient.required_quantity }} {{ unitLabel(ingredient.normalized_unit) }}</td>
                        <td data-label="Disponible" class="numeric">{{ ingredient.available_quantity }} {{ unitLabel(ingredient.normalized_unit) }}</td>
                        <td data-label="Faltante" class="numeric">{{ ingredient.missing_quantity }} {{ unitLabel(ingredient.normalized_unit) }}</td>
                        <td data-label="Estado"><StatusBadge :status="ingredient.can_produce ? 'ready' : 'blocked'" /></td>
                    </tr></tbody>
                </table>
            </div>
        </template>

        <template v-else-if="mode === 'production-traceability'">
            <div class="traceability-flow">
                <section class="trace-step">
                    <span class="trace-marker">1</span><div><small>Receta congelada</small><strong>{{ detail.recipe_snapshot?.product?.name }} · versión {{ detail.recipe_snapshot?.version }}</strong><p>Rendimiento esperado {{ detail.recipe_snapshot?.expected_yield }} {{ unitLabel(detail.recipe_snapshot?.yield_unit) }}</p></div>
                </section>
                <section class="trace-step">
                    <span class="trace-marker">2</span><div><small>Lotes consumidos</small><strong>{{ detail.consumed_lots?.length ?? 0 }} lotes trazados</strong>
                        <ul><li v-for="lot in detail.consumed_lots" :key="lot.lot_id">Lote {{ lot.lot_code }} · {{ lot.quantity }} {{ unitLabel(lot.unit) }}</li></ul>
                    </div>
                </section>
                <section class="trace-step">
                    <span class="trace-marker">3</span><div><small>Resultado</small><strong v-if="detail.produced_lot">Lote {{ detail.produced_lot.code }}</strong><strong v-else>Sin lote terminado</strong><p v-if="detail.produced_lot">{{ detail.produced_lot.quantity }} {{ unitLabel(detail.produced_lot.unit) }} · vence {{ formatCalendarDate(detail.produced_lot.expires_at) }}</p></div>
                </section>
            </div>
        </template>

        <template v-else-if="view === 'lots' && detail.data">
            <dl class="detail-grid">
                <div><dt>Lote</dt><dd>{{ detail.data.code }}</dd></div>
                <div><dt>Disponible</dt><dd>{{ detail.data.quantity }} {{ unitLabel(detail.data.unit) }}</dd></div>
                <div><dt>Reservado</dt><dd>{{ detail.data.reserved_quantity }} {{ unitLabel(detail.data.unit) }}</dd></div>
                <div><dt>Vencimiento</dt><dd>{{ formatCalendarDate(detail.data.expires_at) }}</dd></div>
            </dl>
            <h3>Movimientos recientes</h3>
            <div class="responsive-detail-table"><table><thead><tr><th>Tipo</th><th>Cantidad</th><th>Motivo</th><th>Fecha</th></tr></thead><tbody>
                <tr v-for="movement in detail.movements?.data ?? []" :key="movement.id"><td data-label="Tipo">{{ statusLabel(movement.type) }}</td><td data-label="Cantidad" class="numeric">{{ movement.quantity }}</td><td data-label="Motivo">{{ movement.reason }}</td><td data-label="Fecha">{{ formatDateTime(movement.created_at) }}</td></tr>
            </tbody></table></div>
        </template>

        <template v-else-if="view === 'orders'">
            <dl class="detail-grid">
                <div><dt>Pedido</dt><dd>#{{ detail.id }}</dd></div><div><dt>Cliente</dt><dd>{{ detail.customer_name }}</dd></div>
                <div><dt>Estado</dt><dd><StatusBadge :status="detail.status" /></dd></div><div><dt>Total</dt><dd>{{ money(detail.total) }}</dd></div>
                <div><dt>Pagado</dt><dd>{{ money(detail.paid_total) }}</dd></div><div><dt>Fecha requerida</dt><dd>{{ formatDateTime(detail.required_at) }}</dd></div>
            </dl>
            <h3>Productos</h3>
            <div class="responsive-detail-table"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th></tr></thead><tbody>
                <tr v-for="item in detail.items ?? []" :key="item.id"><td data-label="Producto">#{{ item.product_id }}</td><td data-label="Cantidad" class="numeric">{{ item.quantity }}</td><td data-label="Precio" class="numeric">{{ money(item.unit_price) }}</td></tr>
            </tbody></table></div>
            <h3>Historial</h3>
            <ol class="timeline"><li v-for="transition in detail.transitions ?? []" :key="transition.id"><StatusBadge :status="transition.to_status" /><span>{{ formatDateTime(transition.created_at) }}</span></li></ol>
        </template>

        <template v-else-if="view === 'recipes'">
            <dl class="detail-grid">
                <div><dt>Producto</dt><dd>{{ detail.product?.name }}</dd></div><div><dt>Versión</dt><dd>{{ detail.version }}</dd></div>
                <div><dt>Rendimiento</dt><dd>{{ detail.yield_quantity }} {{ unitLabel(detail.yield_unit) }}</dd></div><div><dt>Estado</dt><dd><StatusBadge :status="detail.status" /></dd></div>
            </dl>
            <h3>Ingredientes</h3>
            <div class="responsive-detail-table"><table><thead><tr><th>Ingrediente</th><th>Cantidad</th><th>Unidad</th></tr></thead><tbody>
                <tr v-for="item in detail.items ?? []" :key="item.id"><td data-label="Ingrediente">{{ item.ingredient?.name }}</td><td data-label="Cantidad" class="numeric">{{ item.quantity }}</td><td data-label="Unidad">{{ unitLabel(item.unit) }}</td></tr>
            </tbody></table></div>
        </template>

        <template v-else>
            <dl class="detail-grid">
                <div v-for="(value, key) in detail" :key="key" v-show="!['organization_id', 'branch_id', 'updated_at'].includes(String(key)) && typeof value !== 'object'">
                    <dt>{{ String(key).replaceAll('_', ' ') }}</dt>
                    <dd>{{ key.toString().includes('at') ? formatDateTime(value) : value ?? '—' }}</dd>
                </div>
            </dl>
        </template>
    </div>
</template>
