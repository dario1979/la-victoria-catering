# Auditoría list-first de listados y CRUD

Fecha: 2026-07-28

## Baseline

- Interfaz: una única SPA Vue controlada por hash desde `App.vue`.
- Listados: `ServerDataTable` reutilizable con consultas server-side.
- Persistencia: búsqueda, filtros, orden, dirección, página y tamaño en URL.
- Exportación: Excel generado por el backend con el tenant activo.
- Mutaciones: conectadas a la API real; las críticas usan idempotencia.
- Problema estructural: los formularios estaban permanentemente visibles junto
  a las tablas y exigían copiar IDs.
- Resultado Impeccable inicial: 20/40, con cero hallazgos del detector estático.

## Inventario real

| Módulo | Vista frontend | Endpoint de listado | Capacidades actuales | Formulario previo | Acciones/API disponibles | Cobertura |
| --- | --- | --- | --- | --- | --- | --- |
| Organizaciones | Contexto de sesión | No existe | Selector derivado de sesión | No | Selección de tenant | Tenancy |
| Sucursales | Encabezado | No existe | Selector de sucursal asignada | No | Cambio de sucursal | Tenancy |
| Usuarios | No existe | No existe | — | — | Sólo usuarios en sesión/seeder | Seguridad |
| Clientes | `#customers` | `GET /customers` | Buscar, filtrar estado, ordenar, paginar, exportar | Fijo | Crear, ver, editar | Feature DataTable |
| Productos | `#products` | `GET /products` | Buscar, filtrar estado/tipo, ordenar, paginar, exportar | Fijo | Crear, ver, editar | Feature DataTable |
| Ubicaciones | No existía | `GET /locations` | Backend server-side completo | No expuesto | Crear | Feature DataTable |
| Lotes | `#lots` | `GET /lots` | Buscar, filtrar, ordenar, paginar, exportar | Recepción fija | Ver, recibir, ajustar | Feature DataTable y flujo |
| Movimientos de stock | Dentro de lote | No existe listado propio | Paginados en detalle de lote | — | Consulta por lote | Flujo |
| Reservas | No existe | No existe | Se crean por dominio FEFO | — | Sólo flujo de pedido | Flujo |
| Pedidos | `#orders` | `GET /orders` | Buscar, filtrar estado, ordenar, paginar, exportar | Alta, transición y entrega fijas | Crear, ver, transicionar, entregar | Flujo vertical |
| Recetas | No existía | `GET /recipes` | Backend server-side completo | No expuesto | Crear, ver, cambiar estado | Producción |
| Producción | `#production` | `GET /production-orders` | Ordenar, paginar, exportar | Alta y seguimiento fijos | Crear, ver, requerimientos, iniciar, completar, trazabilidad | Producción |
| Pagos | `#payments` | `GET /payments` | Buscar, filtrar medio, ordenar, paginar, exportar | Alta fija | Crear, ver | Flujo e idempotencia |
| Movimientos de caja | No existe | No existe | Escritura interna inmutable | — | Sin UI/API pública | Flujo de pago |
| Entregas | Dentro de pedidos | No existe listado propio | Estado reflejado en pedido | Entrega fija | Registrar entrega | Flujo vertical |
| Alertas | `#alerts` | `GET /alerts` | Buscar, filtrar severidad/estado, ordenar, paginar, exportar | Resolución fija | Ver, reconocer, resolver | Flujo de alertas |
| Proveedores | `#procurement` → Proveedores | `GET /suppliers` | Buscar, filtrar estado, ordenar, paginar, exportar | No existía | Crear, ver, editar, activar/desactivar | Compras, permisos y tenancy |
| Catálogo proveedor-producto | `#procurement` → Catálogo y precios | `GET /supplier-products` | Buscar, filtrar estado/preferencia, ordenar, paginar, exportar | No existía | Vincular, ver, actualizar condiciones y precio | Historial de precios |
| Órdenes de compra | `#procurement` → Órdenes | `GET /purchase-orders` | Buscar, filtrar estado, ordenar, paginar, exportar | No existía | Crear/editar borrador, aprobar, enviar, cancelar, recibir | Flujo de compras e idempotencia |
| Recepciones de compra | `#procurement` → Recepciones | `GET /purchase-receipts` | Buscar, ordenar, paginar, exportar | No existía | Ver trazabilidad; alta contextual desde orden | Stock, lotes y discrepancias |
| Auditoría | No existe | No existe | Transiciones persistidas en pedido | — | Visible en detalle de pedido | Flujo |
| Integraciones | No existe | No existe | Adaptadores fake/sandbox sin UI | — | Sin endpoints de gestión | Tests de adaptadores |
| Exportaciones | Acción en toolbar | Endpoint del listado con `export=xlsx` | Conserva búsqueda, filtros y orden | — | Descarga Excel | Feature DataTable |

## Patrón visual previo

- Tabla dentro de un panel secundario.
- Formularios visibles en el mismo viewport.
- Ausencia de acciones por fila.
- Confirmaciones mediante `window.confirm`.
- Producción mostraba requerimientos y trazabilidad como JSON.
- Controles de tabla por debajo de 44 px en varios breakpoints.
- La navegación no exponía ubicaciones ni recetas aunque sus endpoints existían.

## Decisión

Adoptar un patrón list-first único:

1. Encabezado compacto y acción principal.
2. Toolbar, filtros y exportación.
3. Tabla server-side como contenido central.
4. Acciones explícitas por fila.
5. Modal compartido para alta, edición y detalle.
6. Confirmación compartida para acciones críticas.
7. Modal grande o de pantalla completa para pedidos, recetas y producción.
8. Estado del listado preservado después de cada mutación.

Los módulos sin endpoint o interfaz real no se simulan. Se documentan como
ausentes para un incremento funcional posterior.
