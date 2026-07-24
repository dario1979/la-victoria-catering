# Mapa de módulos y límites

| Módulo | Responsabilidad | Eventos publicados |
| --- | --- | --- |
| Identidad y organización | Usuarios, roles, sucursales y auditoría | `UserRoleChanged` |
| Catálogo y recetas | Productos, unidades, precios y BOM versionada | `RecipeApproved` |
| Pedidos | Cotización, pedido y máquina de estados | `OrderCreated`, `OrderConfirmed`, `OrderCancelled` |
| Producción | Planes, órdenes, tandas, rendimiento y merma | `ProductionBatchCompleted` |
| Inventario | Lotes, ubicaciones, reservas y movimientos | `StockReserved`, `StockBelowMinimum`, `LotExpired` |
| Compras | Proveedores, órdenes y recepciones | `PurchaseOrderReceived`, `SupplierDeliveryDelayed` |
| Clientes y finanzas | Clientes, cuenta corriente, pagos y caja | `PaymentReceived`, `CashDifferenceDetected` |
| Alertas | Reglas, notificaciones, escalamiento y cierre | `AlertRaised`, `AlertResolved` |
| Integraciones | Puertos, adaptadores y webhooks externos | `IntegrationDeliveryFailed` |

Los módulos solo se comunican por servicios explícitos, contratos y eventos internos; controllers y componentes Vue no contienen reglas de dominio.
