---
target: rediseño integral de listados y CRUD
total_score: 20
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 4
timestamp: 2026-07-28T14-49-26Z
slug: resources-js-components-app-vue
---
# Impeccable critique · Listados y CRUD

## Design Health Score

| # | Heurística | Puntaje | Hallazgo principal |
| --- | --- | ---: | --- |
| 1 | Visibilidad del estado | 3 | Buenos estados globales; el progreso de mutaciones es poco específico. |
| 2 | Correspondencia con el mundo real | 2 | El lenguaje es cercano, pero IDs, unidades inglesas y JSON filtran estructura técnica. |
| 3 | Control y libertad | 2 | Hay navegación y confirmaciones; faltan cancelar, descartar y recuperación contextual. |
| 4 | Consistencia y estándares | 3 | Tokens y componentes coherentes; `window.confirm` rompe el sistema visual. |
| 5 | Prevención de errores | 2 | Idempotencia y validación sólidas; los IDs opacos aumentan el riesgo de actuar sobre otra entidad. |
| 6 | Reconocer antes que recordar | 1 | Los flujos críticos obligan a copiar y recordar IDs. |
| 7 | Flexibilidad y eficiencia | 2 | La tabla server-side es potente; faltan acciones por fila y atajos operativos. |
| 8 | Diseño estético y minimalista | 2 | La identidad es buena; formularios y tablas simultáneos generan ruido. |
| 9 | Recuperación de errores | 3 | Buen tratamiento de errores globales; faltan errores asociados a campos. |
| 10 | Ayuda y documentación | 0 | No existe ayuda contextual ni orientación de tarea. |
| **Total** |  | **20/40** | **Aceptable, con mejoras importantes de flujo** |

## Veredicto de especificidad

La dirección “obrador editorial operativo” es reconocible en la paleta cálida,
la tipografía, el logo y el lenguaje. La interacción todavía es la de un CRUD
genérico: navegación plana, formularios fijos junto a tablas, acciones mediante
IDs y objetos de producción representados como JSON. La oportunidad principal
es expresar la cadena pedido–receta–lote–producción–cobranza como objetos
operativos conectados y reconocibles.

El detector determinístico recorrió `App.vue` y los componentes de DataTable.
Resultado: cero hallazgos, sin falsos positivos. No hubo navegador disponible en
el entorno, por lo que no se generaron overlays ni inspección visual en vivo.

## Lo que funciona

- Identidad visual disciplinada, cálida y específica de La Victoria.
- Base de DataTable server-side con búsqueda, filtros, ordenamiento, paginación,
  exportación, persistencia en URL, estados y `aria-sort`.
- Estado operativo visible: sucursal, conectividad, offline, sesión, errores y
  feedback accesible.

## Problemas prioritarios

### P1 · Acciones desconectadas de los registros

Los formularios exigen IDs mientras la evidencia está en otra zona de la
pantalla. Se debe abrir detalle y acciones desde cada fila, transportando el
registro seleccionado al modal.

### P1 · Confirmaciones críticas sin contexto humano

Pagos, entregas, transiciones y producción nombran un ID, pero no cliente,
producto, estado, saldo o consecuencia. Se deben reemplazar por diálogos de
confirmación accesibles con resumen de la entidad y del impacto.

### P1 · Carga cognitiva por formularios simultáneos

Pedidos y producción muestran alta, seguimiento, acciones y tabla al mismo
tiempo. Las páginas deben ser list-first y revelar cada tarea en un modal
estándar, grande o de pantalla completa.

### P1 · Controles por debajo del objetivo táctil

Selectores, ordenamiento y paginación usan alturas inferiores a 44 px. Se debe
normalizar el sistema de controles y revisar especialmente tablet y móvil.

### P2 · Estructura técnica expuesta

Estados ingleses, IDs y JSON de requerimientos/trazabilidad obligan al operador
a traducir el sistema. Se deben localizar valores y convertir producción en
tablas, badges y una línea temporal.

## Personas

### Usuario intensivo

No puede iniciar acciones desde una fila y repite búsquedas/copias de IDs. La
infraestructura de selección existe pero no está integrada en las pantallas.

### Usuario nuevo

“Producto ID”, “Receta ID” y “Ubicación ID” presuponen conocimiento interno.
Las unidades y estados mezclan inglés técnico, y no existe ayuda contextual.

### Operador de tablet interrumpido

Al apilarse, el formulario separa la acción de la tabla y obliga a recordar
datos durante el scroll. Varios controles son pequeños y el resultado global
puede quedar fuera del viewport.

## Observaciones menores

- Las letras de navegación aportan poca capacidad de reconocimiento.
- Se repite la identidad de página en topbar y encabezado grande.
- Los vacíos genéricos no siempre ofrecen la acción principal del módulo.
- La paginación permanece visible cuando no hay filas.
- Las fechas no tienen una estrategia de localización evidente.

## Preguntas que guían la implementación

- ¿Puede un operador completar el flujo sin conocer un ID de base de datos?
- ¿Cada acción crítica identifica claramente a la persona, pedido o producto?
- ¿La tabla conserva su estado después de crear, editar o actuar?
- ¿La trazabilidad se entiende sin leer JSON?
