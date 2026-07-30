# Evidencia E2E responsive y de accesibilidad

Fecha: 2026-07-30

## Alcance

La suite Playwright valida la aplicación servida desde imágenes de producción en un stack Docker aislado. La matriz cubre:

- 1440 × 900
- 1024 × 768
- 768 × 1024
- 390 × 844
- 360 × 800

En cada viewport se verifican el tablero, ausencia de overflow horizontal, navegación responsive, modal contenido en pantalla, foco inicial y tabulación, cierre con `Escape`, restauración del foco, aviso offline, menú de acciones por teclado y análisis Axe WCAG 2 A/AA.

## Reproducción del defecto

Antes de la corrección, el menú de acciones vivía dentro del contenedor con scroll de la tabla. Playwright demostró clipping en los cinco viewports. Los rectángulos observados fueron:

| Viewport | Rectángulo del menú (`left, top, right, bottom`) |
| --- | --- |
| 1440 × 900 | `1168.20, 587.14, 1358.20, 729.14` |
| 1024 × 768 | `767.25, 543.03, 957.25, 685.03` |
| 768 × 1024 | `536.20, 487.86, 726.20, 629.86` |
| 390 × 844 | `184, 787.61, 374, 929.61` |
| 360 × 800 | `154, 750.61, 344, 892.61` |

También se detectó un contraste de `4.04:1` en el ícono de la opción activa del menú lateral, por debajo del mínimo `4.5:1` aplicable.

## Corrección verificada

`DataTableRowActions` teletransporta ahora el menú al `body`, lo posiciona con coordenadas fijas, elige apertura superior cuando no hay espacio inferior y se recalcula ante scroll o resize. Se conservaron navegación con flechas, `Home`, `End`, `Tab`, `Escape`, cierre exterior y retorno del foco.

El color del ícono se ajustó dentro de la paleta cálida existente. Tras reconstruir las imágenes, los cinco proyectos Playwright pasaron sin clipping, sin desbordamiento y sin violaciones Axe WCAG 2 A/AA.

Comando reproducible:

```bash
bash scripts/e2e-stack.sh up
npm run test:e2e -- tests/e2e/03-responsive-accessibility.spec.ts
bash scripts/e2e-stack.sh down
```
