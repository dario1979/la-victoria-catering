# Revisión visual y de accesibilidad — 30 de julio de 2026

## Resultado

La revisión en navegador real quedó bloqueada por una dependencia externa: el
runtime de navegador integrado respondió que no había navegadores disponibles y
la enumeración posterior devolvió `[]`. No se sustituyó por automatización
externa ni se afirmó una validación visual inexistente.

La auditoría estática y las pruebas de componentes sí se completaron:

- detector Impeccable sin hallazgos;
- 7 archivos y 17 pruebas Vitest aprobados;
- navegación por teclado del menú de acciones cubierta;
- foco, descarte y restauración en modales cubiertos;
- typecheck y build aprobados;
- contrato PWA aprobado.

El puntaje visual anterior de 17/20 se mantiene como referencia provisional. No
se recalculó sin evidencia visual nueva.

## Matriz pendiente

No fue posible inspeccionar visualmente los siguientes anchos:

- 1440 px;
- 1280 px;
- 1024 px;
- 768 px;
- 390 px;
- 360 px.

Pantallas pendientes de evidencia visual:

- login;
- dashboard;
- clientes;
- productos;
- lotes;
- pedidos;
- recetas;
- producción;
- cobranzas;
- alertas.

También quedan pendientes el recorrido con lector de pantalla, contraste
renderizado, zoom, clipping, overlays, scroll y foco visible en navegador real.

## Menú de acciones

La inspección de código confirma una condición de riesgo, no un defecto visual
reproducido:

- `.table-wrap` usa `overflow-x: auto`;
- `.row-actions` usa `position: relative`;
- `.row-actions-menu` usa `position: absolute`;
- el menú se renderiza dentro del contenedor con overflow;
- en móvil el menú cambia a `position: static` y el overflow de la tabla pasa a
  `visible`.

El navegador no estuvo disponible para comprobar si el menú se recorta en
escritorio o tablet. No se implementó `Teleport`, portal o popover porque el
programa exige una reproducción real antes de cambiar este comportamiento.

## Accesibilidad verificable

La implementación conserva:

- landmarks y tablas con nombre accesible;
- mensajes asíncronos mediante `aria-live`;
- menús con `aria-haspopup`, `aria-expanded`, roles `menu` y `menuitem`;
- teclas Flecha arriba/abajo, Inicio, Fin, Escape y Tab;
- devolución de foco al disparador con Escape;
- modales con captura y restauración de foco;
- formularios HTML, etiquetas, errores asociados y foco en el primer campo
  inválido;
- objetivos táctiles de 44 px y `prefers-reduced-motion`.

Estas comprobaciones son de código y pruebas jsdom. No reemplazan una prueba
manual con lector de pantalla.

## Severidad

- P0: 0 hallazgos verificados.
- P1: 0 hallazgos verificados.
- P2: 1 riesgo pendiente de reproducción, posible clipping del menú de acciones.
- P3: 0 hallazgos nuevos.

## Próxima acción

Cuando el navegador integrado esté disponible:

1. ejecutar la matriz de anchos y pantallas;
2. abrir el menú en las primeras y últimas filas con scroll horizontal y
   vertical;
3. verificar posición frente a viewport, modales y navegación por teclado;
4. recorrer login, tablas, filtros, formularios y modales con un lector de
   pantalla;
5. aplicar `impeccable adapt` o `impeccable harden` sólo sobre defectos
   reproducidos;
6. cerrar con `impeccable polish` y repetir `impeccable audit`.

## Activos PWA

El único logo disponible sigue siendo un raster aproximado de 150 × 150 px. No
es apto para fabricar iconos definitivos de 192 × 192 y 512 × 512 sin ampliación
agresiva. El manifiesto conserva `icons: []` y el bloqueo de marca permanece
documentado hasta recibir activos aprobados.
