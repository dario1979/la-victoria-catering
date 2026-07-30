# Incremento de recuperación — Compras

Fecha: 30 de julio de 2026
Rama: `develop`

## Entregado

- proveedores con datos comerciales, plazos, activación y bloqueo de baja con órdenes abiertas;
- catálogo proveedor-producto con unidad, conversión exacta, mínimo, preferencia e historial de precios;
- órdenes con numeración interna, importes exactos, snapshots y estados auditados;
- recepción parcial o total idempotente, discrepancias, lotes y movimientos inmutables;
- aislamiento por organización/sucursal y matriz explícita para compras, inventario, producción y finanzas;
- alertas de stock mínimo, faltante de producción, aprobada no enviada, parcial, demora, inactividad, recepción fallida y precio ausente;
- centro de compras responsive, list-first, con búsqueda, filtros, XLSX, selectores remotos y acciones por estado;
- OpenAPI, mapa de módulos, glosario, operación y modelo de amenazas actualizados;
- datos demo de proveedor, harina, precio y borrador de orden.

## Evidencia

- backend: 50 pruebas, 49 aprobadas, 356 aserciones y 1 prueba de carrera omitida localmente por requerir PostgreSQL/`pcntl`;
- compras: 10 pruebas y 117 aserciones;
- frontend: 8 archivos, 20 pruebas;
- `vue-tsc`, Vite build, contrato PWA, Pint y Composer válidos;
- migración completa y seeder ejecutados sobre SQLite aislado;
- la carrera real está preparada para CI PostgreSQL: dos procesos, una sola recepción y un solo movimiento.

## Bloqueo externo

La revisión visual en navegador real no pudo ejecutarse porque el runtime integrado no expone navegadores. No se sustituyó por evidencia simulada. El bloqueo y la matriz pendiente están en `docs/reviews/visual-accessibility-2026-07-30.md`.
