# Endurecimiento técnico — 28 de julio de 2026

## Resultado

Se corrigieron todos los hallazgos P1 reproducibles de la auditoría y los P2 que podían resolverse sin cambiar la arquitectura ni las reglas de negocio. La aplicación quedó validada con pruebas backend y frontend, análisis estático, build de producción, contrato PWA, auditorías de dependencias, contenedores saludables y comprobaciones HTTP contra el entorno Docker en vivo.

No se hizo `push`, no se abrió un PR y no se modificaron ramas remotas.

## Correcciones por tipo

### Datos, listados y exportaciones

- Se añadió la relación faltante entre lotes y productos que provocaba errores 500.
- Se endureció `ServerDataTable` para búsquedas con closures, comparación PostgreSQL `ilike` y columnas cuyos nombres coinciden con funciones PHP.
- Las exportaciones normalizan fechas, enums, booleanos, escalares y valores serializables.
- Alertas usa un único campo canónico `action` en API, frontend y XLSX.
- Las búsquedas de recetas y órdenes de producción ahora consultan relaciones reales.
- Las búsquedas de clientes y demás listados son insensibles a mayúsculas.
- Se añadieron pruebas de exportación, búsqueda y aislamiento entre organizaciones/sucursales.

### Dashboard, fechas y contrato API

- Se incorporó `GET /api/v1/dashboard/summary`.
- Los indicadores del dashboard se calculan sobre el conjunto completo del tenant; ya no dependen de la primera página de 25 registros.
- El resumen incluye pedidos totales y del día, vencidos, producción pendiente, saldo pendiente, alertas abiertas, stock crítico y listados recientes acotados.
- Se separó el formato de fecha civil del formato de timestamp para evitar desplazamientos por zona horaria.
- OpenAPI y tipos TypeScript quedaron sincronizados con el nuevo endpoint.
- Las pruebas verifican conteos exactos con más de 25 registros y ausencia de fugas entre tenants.

### Formularios, errores y accesibilidad

- Los diálogos operativos usan formularios HTML reales y envío por `submit`.
- Los errores 422 quedan visibles dentro del modal, vinculados al campo correspondiente mediante ARIA, y el primer campo inválido recibe foco.
- Los fallos al cargar detalles o transiciones se muestran al usuario y no quedan ocultos detrás del backdrop.
- El diálogo de descarte aísla correctamente el foco y vuelve inerte el contenido cubierto.
- Los menús de acciones por fila soportan flechas, `Home`, `End`, `Escape` y transferencia de foco.
- La acción móvil “Más” usa el modal accesible compartido.
- `RemoteSelect` incorpora nombre, obligatoriedad, estado inválido, relaciones ARIA, opción activa y objetivos táctiles adecuados.
- La navegación oculta a perfiles de producción las secciones que no tienen permiso de consultar.
- Los selectores de producción muestran únicamente pedidos confirmados y recetas aprobadas, reforzando las reglas existentes.
- Se mejoró el contraste de los grupos de navegación.

### Mantenibilidad y riesgo de regresión

- Se extrajeron `OperationalDetail.vue` y utilidades operativas compartidas.
- `OperationalPage.vue` pasó de 1.062 a 914 líneas físicas.
- Se añadieron pruebas de modales, formularios, errores de validación, acciones por teclado, transporte API y fechas.
- Las lecturas HTTP tienen timeout de 15 segundos y las mutaciones de 30 segundos.
- Los errores de red distinguen una lectura reintentable de una mutación con resultado desconocido.
- La recuperación de sesión sólo interpreta un 401 como sesión ausente; otros fallos se informan.
- La navegación interna usa historial y responde a `popstate`.

### PWA, Docker y producción

- El manifiesto y el service worker quedaron con alcance raíz `/`, manteniendo los archivos compilados bajo `/build/`.
- La página raíz publica el enlace al manifiesto.
- Nginx entrega el manifiesto con su media type y el service worker con `Service-Worker-Allowed: /` y sin caché.
- Se añadió `npm run test:pwa` para impedir regresiones del contrato.
- Docker usa `APP_DEBUG=false` por defecto.
- El entrypoint rechaza el arranque en producción si está activo el debug o si se conserva la contraseña demo `123456`.
- La contraseña demo solicitada se mantiene únicamente para desarrollo local.

### Dependencias y seguridad

- Se actualizaron Laravel, Guzzle, Symfony y dependencias PHP compatibles afectadas por avisos publicados.
- `composer audit` quedó en 0 avisos y 0 paquetes abandonados.
- `npm audit --omit=dev` quedó en 0 vulnerabilidades de producción.

## Evidencia de validación

| Verificación | Resultado |
|---|---:|
| PHPUnit | 33 pruebas, 209 aserciones |
| Vitest | 6 archivos, 13 pruebas |
| TypeScript (`vue-tsc --noEmit`) | correcto |
| Build Vite de producción | correcto |
| Contrato PWA | correcto |
| Laravel Pint | correcto |
| `composer validate --strict` | correcto |
| `composer audit` | 0 avisos |
| `npm audit --omit=dev` | 0 vulnerabilidades |
| Docker Compose | 7 servicios en ejecución; servicios con healthcheck saludables |

La validación HTTP posterior al último rebuild comprobó:

- login de demostración;
- listado y exportación XLSX de lotes;
- búsqueda y exportación XLSX de alertas;
- búsqueda sin resultados de recetas;
- búsqueda de clientes sin distinguir mayúsculas;
- resumen del dashboard;
- página raíz, manifiesto y service worker PWA.

Todos los endpoints comprobados respondieron HTTP 200. Los XLSX se entregaron con `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`; el manifiesto usa `application/manifest+json`, `start_url: "/"`, `scope: "/"` y `lang: "es"`; el service worker permite alcance `/`.

## Riesgos residuales

1. **Iconos PWA:** el manifiesto conserva `icons: []`. El repositorio no incluye activos de marca aprobados de 192×192 y 512×512; generar o deformar el logotipo existente sin aprobación sería una decisión de marca. El siguiente paso es incorporar esos dos recursos y agregarlos al contrato PWA.
2. **Toolchain npm:** la auditoría completa informa 12 vulnerabilidades altas, todas en dependencias de desarrollo transitivas de `@vue/test-utils` y `vite-plugin-pwa`/Workbox. No forman parte del bundle de producción. La simulación de `npm audit fix` falla por un conflicto de resolución con Vite 8 y propone cambios incompatibles o un downgrade; no se aplicó `--force`. Deben revisarse cuando los paquetes upstream publiquen una combinación compatible.
3. **Recorrido visual:** el entorno no expuso ninguna sesión del navegador integrado. La verificación en vivo se completó mediante HTTP contra Docker y la interacción se cubrió con pruebas jsdom, pero queda pendiente una pasada visual manual en navegador real para clipping, responsive y lectores de pantalla.
4. **Menú de acciones en tablas:** el control de teclado está cubierto, pero el posible clipping del desplegable dentro de contenedores con scroll requiere la pasada visual anterior antes de decidir una solución con portal/popover.
5. **Offline total:** la aplicación mantiene deliberadamente el enfoque connected-first. El shell PWA puede instalarse, pero los flujos operativos siguen requiriendo API disponible.

## Commits locales

- `716b3b9` — `fix(api): harden operational listings and exports`
- `4728f67` — `fix(dashboard): compute exact tenant summary metrics`
- `a1dcde2` — `fix(ui): harden operational dialogs and forms`
- `f7ac243` — `fix(pwa): correct root scope and production safeguards`
- `1c1cebc` — `chore(deps): update vulnerable runtime packages`

