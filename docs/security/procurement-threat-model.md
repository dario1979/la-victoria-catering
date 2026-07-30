# Modelo de amenazas — Compras

## Activos

- condiciones comerciales e historial de precios;
- snapshots aprobados;
- cantidades pendientes y aceptadas;
- lotes, movimientos y trazabilidad de recepción;
- límites de organización y sucursal;
- identidad de quien crea, aprueba, envía, recibe o cancela.

## Amenazas y controles

| Amenaza | Control |
| --- | --- |
| Leer o mutar un proveedor de otra organización | `ResolveTenant`, filtros por `organization_id`, aserción de tenant y respuesta `404` |
| Recibir una orden de otra sucursal | orden, ubicación y lote se validan contra organización y sucursal activas |
| Elevar privilegios desde la UI | matriz central `ProcurementAccess` y autorización previa a validación en `FormRequest` |
| Repetir una recepción por timeout | idempotencia por organización/orden/clave y movimiento con clave única |
| Sobre-recibir por solicitudes concurrentes | transacción y `lockForUpdate` sobre orden y renglones; saldo recalculado dentro del bloqueo |
| Alterar precio o unidad después de aprobar | snapshots inmutables y edición limitada a `draft` |
| Cambiar historial de precio | cierre de vigencia anterior e inserción de una nueva fila |
| Crear stock sin procedencia | sólo una recepción aceptada crea lote y movimiento con referencias y actor |
| Mezclar unidades o perder precisión | dimensiones compatibles, factor positivo y aritmética entera escalada |
| Ocultar mercadería rechazada | igualdad recibido = aceptado + rechazado y discrepancia obligatoria si hay rechazo |
| Desactivar proveedor con compromisos abiertos | bloqueo si existen órdenes no terminales |
| Filtrar detalles de validación a un rol sin permiso | autorización del request antes de ejecutar reglas de cuerpo |
| Exponer secretos de proveedor | el módulo no almacena credenciales; integraciones futuras usarán variables de entorno |

## Riesgo residual

El bloqueo pesimista se cubre en el flujo ejecutado por CI sobre PostgreSQL; SQLite sólo valida el contrato funcional. Una instalación debe monitorizar deadlocks, reintentar transacciones serializables cuando corresponda y mantener alertas sobre fallos reiterados de recepción.
