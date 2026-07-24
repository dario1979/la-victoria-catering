# Máquina de estados de pedidos

`Borrador → Pendiente de confirmación → Confirmado → En planificación → En producción → Listo para empaquetar → Empaquetado → Listo para retirar | En reparto → Entregado`.

`Pendiente de pago`, `Pagado parcialmente` y `Pagado` son estados financieros ortogonales. `Cancelado`, `Rechazado`, `Devuelto` y `Con incidencia` requieren motivo y auditoría.

| Transición | Actor autorizado | Efecto obligatorio |
| --- | --- | --- |
| Borrador → Pendiente de confirmación | Vendedor, administrador | Validar cliente, líneas y fecha. |
| Pendiente → Confirmado | Vendedor, encargado | Reservar stock y evaluar capacidad. |
| Confirmado → En planificación | Producción, encargado | Crear o asociar orden de producción. |
| Planificación → En producción | Producción | Registrar inicio de tanda. |
| Producción → Listo para empaquetar | Producción | Registrar rendimiento, merma y lote producido. |
| Empaquetado → Retiro/reparto | Vendedor, encargado | Confirmar preparación y destino. |
| En reparto → Entregado | Repartidor | Registrar evidencia de entrega. |
| Cualquier estado permitido → Cancelado | Encargado, administrador | Motivo, liberación/reversión controlada y alerta si aplica. |

Cada comando valida transición, actor y precondiciones; usa una clave de idempotencia y registra actor, instante, motivo y efectos.
