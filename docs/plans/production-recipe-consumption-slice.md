# Consumo de recetas y trazabilidad de producción

## Slices

### 1. Persistencia y versionado

Archivos: nueva migración, modelos de receta, consumo y lote.

- Agregar merma teórica y estado versionado.
- Guardar snapshot JSON de receta en la orden al crearla.
- Registrar consumos por lote y referencia al lote elaborado.
- Ampliar lote con elaboración, producción, receta y responsable.
- Agregar restricciones únicas para una sola salida/entrada por finalización.

Salida: migración reversible desde base vacía y datos existentes preservados.

### 2. Unidades y requerimientos

Archivos: servicios de unidades/requerimientos, requests, controller y pruebas
unitarias.

- Normalizar masa a gramos, volumen a mililitros y unidades a milésimas.
- Rechazar dimensiones incompatibles.
- Calcular proporcionalmente con división entera y redondeo hacia arriba.
- Exponer disponibilidad y candidatos FEFO por sucursal sin bloquear stock.

Salida: cálculo exacto, receta/version/snapshot y faltantes visibles.

### 3. Finalización transaccional

Archivos: workflow de producción, inventario, alertas y pruebas API.

- Bloquear producción, ingredientes y lotes candidatos.
- Recalcular dentro de la transacción.
- Consumir todos los ingredientes o ninguno.
- Crear movimientos de salida, lote elaborado y movimiento de entrada.
- Registrar rendimiento, merma, trazabilidad, actor y pedido.
- Deduplicar mediante el mecanismo idempotente existente.

Salida: reintentos reproducen un único resultado; payload distinto devuelve 422.

### 4. API y Vue

Archivos: rutas, OpenAPI, tipos, cliente y pantalla de producción.

- CRUD de recetas versionadas.
- Requerimientos y trazabilidad.
- Formulario de finalización con destino, elaboración y vencimiento.
- Estado desconocido ante timeout y consulta previa al reintento.

Salida: flujo real operable sin mutaciones offline.

### 5. Revisión y compound

Archivos: pruebas PostgreSQL, ADR, solución y skills.

- Probar concurrencia, rollback, tenant A/B y FEFO multi-lote.
- Revisar contrato, idempotencia, stock y simplificar duplicación.
- Registrar invariantes reutilizables.

## Riesgos de concurrencia

- Dos producciones pueden ver el mismo disponible: la decisión final se toma sólo
  después de `FOR UPDATE`.
- Dos finalizaciones de una orden: se bloquea la producción y existe unicidad del
  lote elaborado.
- El cálculo previo puede quedar obsoleto: `complete` siempre recalcula.
- Un fallo tras salidas no debe persistir consumos: una única transacción engloba
  salidas, entrada, lote, producción y pedido.

## Criterios de salida

No hay stock negativo ni cruces de tenant/sucursal; receta histórica inmutable;
movimientos bidireccionales consultables; pruebas SQLite y PostgreSQL, build PWA
y Docker aprobados; revisión sin P0 y working tree limpio.
