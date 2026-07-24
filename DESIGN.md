# Diseño

<!-- impeccable:design-schema 1 -->

## Dirección

**Obrador editorial operativo.** La interfaz toma la disciplina de una planilla
de producción y la calidez material de una panadería, sin convertir cada dato en
tarjeta. Los planos de fondo son papel cálido, la navegación usa tinta cacao y
las acciones se apoyan en terracota y salvia.

## Tipografía

- Interfaz: `Aptos`, `Segoe UI`, system-ui; alta legibilidad y números tabulares.
- Títulos: `Iowan Old Style`, `Palatino Linotype`, Georgia; uso moderado.
- No se cargan fuentes externas para preservar privacidad y operación offline.

## Color

- Papel: `#f5f0e7`; superficie: `#fffdf8`.
- Tinta: `#2d211c`; tinta secundaria: `#695b52`.
- Cacao: `#382019`; terracota: `#9b3f2e`; salvia: `#48634e`.
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
decorativos, animación llamativa ni datos comerciales inventados. El monograma
`LV` es provisional hasta incorporar el logo oficial existente fuera del repo.
