# Caja, cuentas operativas y conciliación

## Alcance

Este módulo cubre control de caja por sucursal, cuenta corriente de clientes, obligaciones básicas con proveedores y conciliación. No implementa contabilidad general ni doble partida.

## Invariantes

- Todo importe se convierte a centavos enteros antes de persistir o comparar.
- Caja, movimientos, obligaciones y conciliaciones pertenecen a una organización y sucursal explícitas.
- Una caja sólo puede tener una sesión `open` o `closing`; la apertura bloquea la caja antes de comprobarlo.
- Sólo usuarios asignados a la caja pueden abrirla, mover efectivo o cerrarla.
- Movimientos de caja, partidas de cliente y pagos a proveedor son inmutables. Una corrección crea un reverso único con importe opuesto.
- El saldo esperado de caja es la suma del saldo inicial y todos sus movimientos, incluidos reversos.
- El saldo y la deuda vencida de un cliente se derivan de `customer_account_entries`; nunca se editan.
- Un pedido de cliente genera como máximo un cargo y un pago genera como máximo un crédito en el ledger.
- Cada recepción genera como máximo una obligación. El total usa cantidad aceptada y costo real o precio congelado de la orden.
- Un pago a proveedor nunca supera el saldo y su reverso reduce lo pagado sin borrar evidencia.
- Una referencia externa de conciliación es única por organización y proveedor.
- `matched` sólo es válido con diferencia cero. Webhooks futuros podrán proponer evidencia, pero no serán fuente única de verdad.
- Todos los instantes operativos se persisten en UTC.

## Estados

Caja:

`open → closing → closed`

Una sesión contada con diferencia termina en `closed_with_difference` y registra aprobador y fecha sin ocultar la diferencia.

Cuenta corriente:

- `settled`: saldo cero.
- `credit`: saldo a favor.
- `open`: deuda vigente.
- `overdue`: deuda positiva con cargos vencidos.

Cuenta por pagar:

`open → partial → paid`; las consultas presentan como vencida una obligación impaga posterior a su fecha.

Conciliación:

`pending → matched | mismatched | ignored | resolved`

## Integración con ventas y compras

Un cobro de pedido bloquea el pedido, valida el pendiente, crea el pago y, si es efectivo, exige una sesión abierta. Para clientes identificados crea una sola vez el cargo del pedido y acredita cada pago.

Una recepción crea inventario y luego una obligación proporcional a lo aceptado. El fallo en cualquiera de las escrituras revierte la operación completa.

## Permisos

- `owner`, `admin`, `finance`: administración financiera, diferencias, ledgers, pagos y conciliación.
- `sales`: puede operar únicamente cajas a las que fue asignado y registrar cobros.
- Otros roles no reciben formularios financieros y la API responde `403` antes de validar payloads.

## Exportaciones y auditoría

Los listados usan paginación, búsqueda, filtros, orden y XLSX server-side. Aperturas, cierres, diferencias y movimientos críticos quedan en `audit_logs`; discrepancias abren alertas deduplicadas.
