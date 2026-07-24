# Escenarios de aceptación del MVP

## Transición inválida
Un usuario intenta entregar un pedido en producción. El sistema la rechaza, explica la transición permitida y audita el intento cuando corresponda.

## Lote vencido reservado
Un lote vence con reservas activas. El sistema lo bloquea, recalcula disponibilidad, identifica pedidos afectados y eleva una alerta.

## Pago duplicado
El mismo pago se reintenta con la misma clave. El saldo y la caja se actualizan una sola vez y se devuelve el resultado original.

## Alerta deduplicada
Un faltante repetido dentro del cooldown mantiene una alerta abierta, sin generar notificaciones equivalentes adicionales.

## Integración fallida
Un canal o adaptador falla tras los reintentos. Se registra el fallo, se aplica canal alternativo si la regla lo permite y se escala el incidente.
