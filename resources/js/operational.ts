import type { View } from './types';

export type OperationalModuleView = Exclude<View, 'dashboard' | 'procurement' | 'finance' | 'review' | 'imports' | 'notifications'>;

export const operationalPageContent: Record<OperationalModuleView, {
    title: string;
    description: string;
    action?: string;
    empty: string;
}> = {
    customers: { title: 'Clientes', description: 'Contacto, condición comercial y estado de cada cliente.', action: 'Nuevo cliente', empty: 'cliente' },
    products: { title: 'Productos', description: 'Catálogo, unidades, precios y niveles mínimos.', action: 'Nuevo producto', empty: 'producto' },
    locations: { title: 'Ubicaciones', description: 'Depósitos y sectores habilitados en la sucursal.', action: 'Nueva ubicación', empty: 'ubicación' },
    lots: { title: 'Inventario por lote', description: 'Disponibilidad, reservas y vencimientos con criterio FEFO.', action: 'Registrar recepción', empty: 'lote' },
    orders: { title: 'Pedidos', description: 'Seguimiento comercial desde el alta hasta la entrega.', action: 'Nuevo pedido', empty: 'pedido' },
    recipes: { title: 'Recetas', description: 'Versiones, rendimiento e ingredientes de producción.', action: 'Nueva receta', empty: 'receta' },
    production: { title: 'Producción', description: 'Órdenes, requerimientos, rendimiento, merma y trazabilidad.', action: 'Nueva orden', empty: 'orden' },
    payments: { title: 'Cobranzas', description: 'Pagos registrados y saldo de los pedidos.', action: 'Registrar pago', empty: 'pago' },
    alerts: { title: 'Alertas', description: 'Situaciones operativas que requieren revisión o acción.', empty: 'alerta' },
};

export const operationalEndpoints: Record<OperationalModuleView, string> = {
    customers: '/customers',
    products: '/products',
    locations: '/locations',
    lots: '/lots',
    orders: '/orders',
    recipes: '/recipes',
    production: '/production-orders',
    payments: '/payments',
    alerts: '/alerts',
};

export function activeOptions() {
    return [{ label: 'Activos', value: '1' }, { label: 'Inactivos', value: '0' }];
}

export function statusLabel(value: string) {
    return ({
        draft: 'Borrador', confirmed: 'Confirmado', in_production: 'En producción', ready: 'Listo',
        delivered: 'Entregado', cancelled: 'Cancelado', planned: 'Planificada', in_progress: 'En curso',
        completed: 'Completada', approved: 'Aprobada', inactive: 'Inactiva', available: 'Disponible',
        sent: 'Enviada', partially_received: 'Recepción parcial', received: 'Recibida',
        depleted: 'Agotado', blocked: 'Bloqueado', expired: 'Vencido', open: 'Abierta',
        acknowledged: 'Reconocida', resolved: 'Resuelta', low: 'Baja', medium: 'Media',
        high: 'Alta', critical: 'Crítica', active: 'Activo',
    } as Record<string, string>)[value] ?? value;
}

export function productType(value: string) {
    return ({ raw_material: 'Materia prima', semi_finished: 'Semielaborado', finished_product: 'Producto terminado', packaging: 'Empaque' } as Record<string, string>)[value] ?? value;
}

export function unitLabel(value: string) {
    return ({ unit: 'unidad', kg: 'kg', g: 'g', l: 'l', ml: 'ml' } as Record<string, string>)[value] ?? value;
}

export function paymentMethod(value: string) {
    return ({ cash: 'Efectivo', transfer: 'Transferencia', mercadopago: 'Mercado Pago', card: 'Tarjeta' } as Record<string, string>)[value] ?? value;
}

export function deliveryLabel(value: string) {
    return ({ pickup: 'Retiro', delivery: 'Reparto', '': 'Sin definir' } as Record<string, string>)[value] ?? value;
}
