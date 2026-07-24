# Backlog inicial

| Prioridad | Elemento | Criterio de aceptación |
| --- | --- | --- |
| P0 | Crear y confirmar pedidos | Rechaza transiciones inválidas, reserva stock y deja auditoría. |
| P0 | Movimiento y reserva de stock | Exige producto, unidad, ubicación, lote, cantidad y motivo; no duplica por reintento. |
| P0 | Lotes y vencimientos | Bloquea lote vencido y muestra pedidos afectados. |
| P0 | Recetas y producción | Versiona receta y registra rendimiento/merma por tanda. |
| P0 | Compras y recepción | Compara orden, recepción y lote recibido. |
| P0 | Pagos y caja | Aplica pago una vez, conserva saldo conciliable y detecta diferencias. |
| P1 | Alertas configurables | Toda alerta tiene evento, condición, responsable, deduplicación y cierre. |
| P1 | ARCA y Mercado Pago | Adaptadores idempotentes, observables y aislados del dominio. |
