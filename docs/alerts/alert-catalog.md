# Catálogo inicial de alertas

| Evento | Condición | Severidad | Destinatario | Acción | Cierre |
| --- | --- | --- | --- | --- | --- |
| `OrderConfirmed` | Ingredientes insuficientes | Alta | Compras, producción | Reprogramar o abastecer | Stock disponible o pedido replanificado |
| `StockBelowMinimum` | Stock disponible bajo mínimo | Media | Compras | Crear/revisar compra | Reposición confirmada |
| `LotExpired` | Lote reservado o disponible vencido | Alta | Inventario, producción | Bloquear y reemplazar | Lote bloqueado y demanda resuelta |
| `SupplierDeliveryDelayed` | Fecha prometida superada | Alta | Compras | Contactar proveedor/sustituto | Recepción o reprogramación |
| `PaymentReceived` | Pago pendiente de conciliación | Media | Cobranzas | Conciliar | Movimiento conciliado |
| `CashDifferenceDetected` | Arqueo no coincide | Alta | Finanzas | Revisar conteo y aprobar con observaciones | Diferencia aprobada |
| `ReconciliationMismatchDetected` | Importe externo distinto del interno | Alta | Finanzas | Revisar evidencia externa | Coincidencia, resolución o descarte |
| `IntegrationDeliveryFailed` | Reintentos agotados | Crítica | Administración | Revisar adaptador/canal alternativo | Entrega confirmada o incidente resuelto |

Cada regla define canal, horario, deduplicación, cooldown, escalamiento y evidencia de resolución. Las alertas no accionables se eliminan.
