# Compras y abastecimiento

## Propósito y límites

Compras convierte una necesidad de inventario en stock trazable sin editar existencias ni precios históricos. Es responsable de proveedores, catálogo proveedor-producto, órdenes, transiciones y recepciones. Inventario sigue siendo dueño de lotes y movimientos; Alertas, de la deduplicación y el cierre operativo.

El flujo operativo es:

1. `DetectInventoryRisks` calcula disponibilidad no vencida por producto y sucursal.
2. `StockBelowMinimum` propone revisar una orden abierta, comprar al proveedor preferido o completar el catálogo.
3. Compras crea un borrador con uno o más productos del proveedor.
4. Aprobar congela producto, código, unidad de compra, factor, precio e importes.
5. Enviar habilita recepciones.
6. Cada recepción declara recibido = aceptado + rechazado. Sólo lo aceptado se convierte a la unidad del producto.
7. En una transacción se bloquean orden y renglones, se crea/actualiza el lote, se crea un movimiento inmutable y se actualiza el pendiente.
8. La orden queda `partially_received` o `received`; producción ve inmediatamente el stock aceptado.

## Estados e invariantes

Estados: `draft → approved → sent → partially_received → received`, con cancelación desde borrador, aprobada, enviada o parcial. `received` y `cancelled` son terminales. `partially_received` y `received` sólo los decide una recepción, nunca una transición manual.

- Sólo un borrador puede editar proveedor o renglones.
- Aprobar requiere al menos un renglón y congela sus snapshots.
- Sólo `sent` o `partially_received` admite recepción.
- Una recepción requiere `Idempotency-Key`; reintentar el mismo cuerpo devuelve el mismo resultado.
- La cantidad aceptada no puede superar la pendiente. El exceso puede documentarse como rechazado y discrepancia.
- Unidad de compra y unidad del producto deben pertenecer a la misma dimensión.
- `cantidad × factor` debe representarse con exactamente hasta tres decimales en la unidad del producto.
- Un lote existente debe coincidir en organización, sucursal, producto, ubicación, unidad, código y vencimiento.
- Cada renglón aceptado produce exactamente un movimiento `purchase_receipt`.
- No se borra historial de precios, transiciones, recepciones ni movimientos.

## Permisos efectivos

| Capacidad | Roles |
| --- | --- |
| Ver proveedores y catálogo | `owner`, `admin`, `purchasing`, `inventory`, `production` |
| Gestionar proveedores, catálogo, borradores y transiciones | `owner`, `admin`, `purchasing` |
| Ver órdenes y recepciones | `owner`, `admin`, `purchasing`, `inventory`, `production`, `finance` |
| Recibir mercadería | `owner`, `admin`, `purchasing`, `inventory` |

El backend es la autoridad. La interfaz replica estas reglas para evitar controles que terminarían en `403`, pero todos los endpoints vuelven a autorizarlas antes de mutar.

## Alertas

| Evento | Severidad | Responsable | Acción y cierre |
| --- | --- | --- | --- |
| `StockBelowMinimum` | alta/crítica | compras | revisar orden abierta o crearla; cierra al recuperar el mínimo |
| `PurchaseOrderApprovedNotSent` | advertencia | compras | enviar; cierra al enviar o cancelar |
| `PurchaseOrderPartiallyReceived` | advertencia | compras | coordinar pendiente; cierra al completar |
| `PurchaseReceiptDiscrepancy` | alta | compras | reclamar, reemplazar o acreditar; cierre manual |
| `PurchaseReceiptFailed` | alta | compras | corregir y reintentar con la misma clave; cierra al recibir |
| `SupplierDeliveryDelayed` | alta | compras | contactar y actualizar previsión; cierra al dejar de estar vencida |
| `PurchaseOrderInactive` | media | compras | completar, aprobar o cancelar borrador |
| `SupplierProductWithoutValidPrice` | advertencia | compras | registrar precio vigente |

Los detectores se ejecutan cada quince minutos con exclusión mutua. Repetir una condición incrementa ocurrencias y actualiza condición, severidad y acción sin duplicar alertas abiertas.

## API y operación

El contrato normativo está en `docs/contracts/openapi.yaml`. Los listados usan paginación, búsqueda, filtros, orden y `export=xlsx`. Las creaciones de orden, transiciones y recepciones requieren `Idempotency-Key`.

Para verificar:

```powershell
php artisan test --filter=Procurement
npm.cmd run test:frontend
npm.cmd run typecheck
```

PostgreSQL aplica `FOR UPDATE` sobre orden y renglones durante la recepción. Dos recepciones simultáneas no pueden aceptar la misma cantidad pendiente; la segunda reevalúa el saldo bajo bloqueo.
