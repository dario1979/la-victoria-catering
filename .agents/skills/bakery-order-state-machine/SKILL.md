# bakery-order-state-machine

## Cuándo usarla
Al modificar estados o acciones sobre pedidos.
## Entradas
Estado actual, actor, comando, motivo y evidencia.
## Procedimiento
Validar transición, permisos y efectos de reserva, producción, entrega o cancelación.
## Validaciones
Exigir idempotencia, auditoría y rechazo explícito de transiciones inválidas.
## Salidas esperadas
Transición autorizada o error accionable documentado.
