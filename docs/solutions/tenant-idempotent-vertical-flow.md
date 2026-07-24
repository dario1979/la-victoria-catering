# Flujo vertical tenant-aware e idempotente

## Problema

Las operaciones originales aceptaban `organization_id` del payload y no
identificaban sucursal. Los reintentos estaban cubiertos sólo en pedidos y pagos.

## Solución aplicada

- Resolver organización, sucursal activa y rol desde sesión más headers.
- Validar membresía, asignación de sucursal y pertenencia de cada entidad.
- Usar scopes de idempotencia por organización, agregado y operación.
- Bloquear filas de pedido, lote y producción dentro de transacciones.
- Representar dinero y cantidades como enteros escalados durante cálculos.
- Registrar transiciones, movimientos, pagos, entrega y alertas como hechos
  auditables.
- Bloquear mutaciones PWA sin conexión y conservar la clave hasta una respuesta
  exitosa.

## Pruebas obligatorias para cambios futuros

1. Usuario de A intenta un ID de B.
2. Misma clave con mismo payload reproduce respuesta.
3. Misma clave con payload diferente devuelve 422.
4. Dos reservas no producen disponibilidad negativa.
5. Pago o entrega reintentados generan un solo efecto.

## Límites

Los headers seleccionan contexto, no conceden autoridad. El middleware siempre
los contrasta con la sesión. ARCA y Mercado Pago productivos requieren
credenciales y validaciones regulatorias externas.
