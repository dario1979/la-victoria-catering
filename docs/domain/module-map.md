# Mapa de módulos y límites

| Módulo | Responsabilidad | Eventos publicados |
| --- | --- | --- |
| Identidad y organización | Usuarios, roles, sucursales y auditoría | `UserRoleChanged` |
| Catálogo y recetas | Productos, unidades, precios y BOM versionada | `RecipeApproved` |
| Pedidos | Cotización, pedido y máquina de estados | `OrderCreated`, `OrderConfirmed`, `OrderCancelled` |
| Producción | Planes, órdenes, tandas, rendimiento y merma | `ProductionBatchCompleted` |
| Inventario | Lotes, ubicaciones, reservas y movimientos | `StockReserved`, `StockBelowMinimum`, `LotExpired` |
| Compras | Proveedores, catálogo con historial de precios, órdenes congeladas y recepciones parciales | `PurchaseOrderApproved`, `PurchaseOrderSent`, `PurchaseOrderPartiallyReceived`, `PurchaseOrderReceived`, `SupplierDeliveryDelayed` |
| Clientes y finanzas | Clientes, ledger, caja, cuentas por pagar y conciliación | `PaymentReceived`, `CashDifferenceDetected`, `ReconciliationMismatchDetected` |
| Alertas | Reglas, notificaciones, escalamiento y cierre | `AlertRaised`, `AlertResolved` |
| Integraciones | Puertos, adaptadores y webhooks externos | `IntegrationDeliveryFailed` |

Los módulos solo se comunican por servicios explícitos, contratos y eventos internos; controllers y componentes Vue no contienen reglas de dominio.
