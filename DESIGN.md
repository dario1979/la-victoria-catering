# Diseño

<!-- impeccable:design-schema 1 -->

## Dirección

**Obrador editorial operativo.** La interfaz toma la disciplina de una planilla
de producción y la calidez material de una panadería artesanal y familiar, sin
convertir cada dato en tarjeta. Los planos de fondo son papel cálido, la
navegación usa tinta y las acciones se apoyan en salvia y acentos durazno. La
ilustración botánica queda concentrada en el logo y en puntos de identidad.

## Tipografía

- Interfaz: `Aptos`, `Segoe UI`, system-ui; alta legibilidad y números tabulares.
- Títulos: `Iowan Old Style`, `Palatino Linotype`, Georgia; uso moderado.
- No se cargan fuentes externas para preservar privacidad y operación offline.

## Color

- Marfil: `#f7eadb`; crema: `#efddcb`; papel: `#fffdf9`.
- Durazno: `#eccfb7`; taupe: `#bfb09e`.
- Salvia clara: `#a9ad97`; salvia: `#888b75`; salvia oscura: `#5f6252`.
- Tinta: `#514337`.
- Estados semánticos siempre combinan texto, etiqueta y color.

## Forma y densidad

- Radios de 4, 8 y 12 px; los pills se reservan para estados.
- Bordes finos y sombras con desplazamiento solamente en superficies elevadas.
- Tablas y listas son el patrón dominante para datos; paneles agrupan tareas.
- Objetivos táctiles mínimos de 44 px.

## Responsive

- Escritorio: navegación lateral persistente y área de trabajo densa.
- Tablet: lateral angosta, acciones grandes y formularios en dos columnas.
- Móvil: encabezado compacto y navegación inferior; las tablas se transforman
  en filas etiquetadas sin perder acciones críticas.

## Estados

Carga, vacío, error, offline, conflicto, resultado desconocido y éxito usan
lenguaje explícito. El color nunca es la única señal. El foco es visible y el
movimiento se reduce cuando el sistema lo solicita.

## Restricciones

No usar glassmorphism, gradientes violeta/azul, tarjetas anidadas, iconos
decorativos, animación llamativa, flores en todas las pantallas ni datos
comerciales inventados. No redibujar, vectorizar ni ampliar el logo raster por
encima de su tamaño natural.

## Activos de marca pendientes

Solicitar, sin bloquear el prototipo actual:

- versión vectorial SVG;
- PNG transparente de alta resolución;
- versión horizontal;
- isotipo simplificado;
- icono específico para PWA y favicon.
