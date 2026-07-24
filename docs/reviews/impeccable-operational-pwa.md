# Impeccable · operational-pwa

## Shape

Modo `operate`. La interfaz prioriza decisión y ejecución en administración,
mostrador, depósito y producción. La dirección elegida es **obrador editorial
operativo**: una mesa de trabajo densa, cálida y legible que evita el patrón de
dashboard SaaS basado en tarjetas.

## Crítica inicial

| Severidad | Hallazgo |
| --- | --- |
| P1 | La navegación móvil comprimía ocho módulos dentro de la sidebar desktop. |
| P1 | Los errores de login no se mostraban en la pantalla de acceso. |
| P1 | La finalización de producción omitía campos requeridos por el contrato. |
| P2 | Un único componente y CSS monolítico repetían estados y badges. |
| P2 | Dashboard con métricas no accionables y datos descritos como “de sesión”. |
| P2 | Tablas producían scroll horizontal en móvil sin alternativa de lectura. |
| P2 | Faltaba estado visible de recuperación de sesión. |
| P3 | Jerarquía basada en kickers repetidos y letras decorativas por módulo. |

## Pasadas aplicadas

- **Layout:** shell más denso, paneles de tarea y datos, tablas como estructura
  principal y navegación inferior móvil.
- **Typeset:** serif de sistema solo para títulos y sans de sistema para operación;
  números tabulares en importes.
- **Colorize:** tokens papel, cacao, terracota y salvia con estados semánticos.
- **Adapt:** composiciones verificables a 360, 390, 768, 1024, 1280 y 1440 px.
- **Clarify:** acciones concretas, errores por categoría y estados vacíos recuperables.
- **Harden:** offline, carga, sesión, doble envío, conflicto y resultado desconocido.
- **Polish:** foco visible, objetivos táctiles, reduced motion y contraste reforzado.

## Auditoría final

| Dimensión | Puntaje | Evidencia |
| --- | ---: | --- |
| Accesibilidad | 3/4 | Landmarks, labels, `aria-live`, foco y targets de 44 px; falta auditoría con lector real. |
| Rendimiento | 4/4 | Sin dependencias UI ni fuentes externas; bundle aproximado 104 kB JS. |
| Responsive | 3/4 | Sidebar desktop, navegación móvil y tablas transformadas; falta inspección visual automatizada. |
| Theming | 4/4 | Colores y escalas centralizados en custom properties. |
| Integridad | 3/4 | Detector estático limpio; no existe asset oficial de logo en el repo. |
| **Total** | **17/20** | Sin bloqueos P0 conocidos. |

La revisión independiente detectó y permitió corregir antes del cierre:

- invalidación y recarga de datos al cambiar de sucursal;
- descarte de respuestas tardías pertenecientes a otra vista;
- confirmación previa de pagos, entregas, transiciones y producción;
- detalle operativo adicional para inventario, producción y alertas;
- landmark principal y nombres accesibles de navegación.

## Límites reales

- El logo botánico raster oficial está integrado en acceso y navegación a tamaño
  contenido; siguen pendientes SVG, PNG transparente de alta resolución, versión
  horizontal, isotipo simplificado e iconos específicos para PWA y favicon.
- La auditoría URL de Impeccable requiere `puppeteer`, que no se agregó como
  dependencia pesada. El detector estático y el build Docker sí se ejecutaron.
- Requerimientos y trazabilidad aún usan una representación JSON técnica; deben
  convertirse en una tabla FEFO y una línea temporal en un incremento posterior.
