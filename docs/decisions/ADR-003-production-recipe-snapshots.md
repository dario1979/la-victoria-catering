# ADR-003: Snapshot de receta en la orden de producción

## Estado

Aceptada.

## Contexto

`recipes` ya representa versiones por `(product_id, version)`, pero sus
ingredientes podrían modificarse y una producción histórica cambiaría de
significado. La trazabilidad debe sobrevivir a cambios posteriores.

## Decisión

Al crear una orden de producción se guarda:

- la FK de la versión de receta;
- un snapshot JSON con producto elaborado, versión, rendimiento, unidad,
  ingredientes, cantidades, unidades y merma teórica.

Los requerimientos y consumos usan exclusivamente ese snapshot. Una receta
aprobada no se edita: un cambio crea una versión nueva.

## Consecuencias

- La producción histórica es reproducible sin duplicar relaciones operativas.
- El snapshot es evidencia, no una entidad editable.
- Las consultas actuales siguen usando FK para navegación y el snapshot para
  cálculo.
- Se agrega validación y pruebas que impiden mutar versiones aprobadas.
