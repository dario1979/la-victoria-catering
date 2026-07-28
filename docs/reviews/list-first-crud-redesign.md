# Rediseño list-first de listados y CRUD

Fecha: 2026-07-28

## Resultado

La interfaz operativa fue migrada desde formularios permanentes junto a tablas
hacia un sistema list-first compartido. Se preservaron la API, las reglas de
dominio, la idempotencia, el tenant activo y todas las capacidades server-side.

## Listados migrados

- Clientes.
- Productos.
- Ubicaciones.
- Lotes e inventario.
- Pedidos.
- Recetas.
- Órdenes de producción.
- Pagos.
- Alertas.

Organizaciones y sucursales continúan como contexto de sesión. Usuarios,
reservas, movimientos de caja, auditoría e integraciones no tienen endpoint de
listado o CRUD público en el repositorio y no se simularon.

## Formularios fijos eliminados

- Alta de clientes.
- Alta de productos.
- Recepción y ajuste de lotes.
- Alta y transición de pedidos.
- Entrega.
- Registro de pagos.
- Alta, inicio y finalización de producción.
- Resolución de alertas.

Se incorporaron además interfaces reales para ubicaciones y recetas, cuyos
endpoints ya existían pero no estaban expuestos en la navegación.

## Componentes compartidos

- `BaseModal`: foco inicial, focus trap, Escape seguro, restauración de foco,
  bloqueo de scroll, footer persistente y descarte protegido.
- `ConfirmActionModal`: confirmación deliberada con entidad y consecuencias.
- `RemoteSelect`: búsqueda remota paginada, tenant-aware y con descarte de
  respuestas obsoletas.
- `DataTableRowActions`: menú accesible de acciones por registro.
- `ServerDataTable`: toolbar compacta, filtros agrupados, estado en URL,
  actualización sin vaciar filas, highlight de cambios y empty action.

## Mejoras de DataTable

- Tabla como contenido principal de cada módulo.
- Búsqueda con debounce de 280 ms.
- Filtros y contador de filtros activos.
- Ordenamiento con `aria-sort`.
- Paginación `10/25/50/100` ejecutada en backend.
- Exportación Excel generada por backend.
- Reintento, loading, skeleton, vacío y error.
- Conservación de filas durante actualizaciones menores.
- Acciones explícitas por fila.
- Columnas secundarias reducidas en móvil.
- Estado de consulta conservado en URL.

## Formularios y acciones

- Selectores remotos sustituyen IDs en pedidos, inventario, recetas, producción
  y cobranzas.
- Clientes y productos admiten alta, detalle, edición y activación/desactivación.
- Pedidos admiten alta, detalle, transición, pago y entrega desde el registro.
- Recetas muestran ingredientes como filas operativas.
- Requerimientos de producción se presentan como tabla de requerido,
  disponible, faltante y estado.
- Trazabilidad se presenta como secuencia receta → lotes consumidos → lote
  producido.
- Pagos, entregas, transiciones y producción muestran confirmación contextual.
- Las claves idempotentes se conservan hasta una respuesta exitosa.
- Las mutaciones se bloquean offline.
- Las acciones se ocultan según el rol, manteniendo autorización en backend.

## Responsive

- Sidebar agrupada en escritorio.
- Navegación inferior reducida a cuatro destinos principales y menú “Más”.
- Modales de pantalla completa en móvil.
- Tablas compactas por registro en pantallas pequeñas sin descargar datos extra.
- Columnas secundarias se ocultan en móvil; los campos críticos y acciones se
  conservan.
- Controles táctiles de al menos 44 px.
- Safe areas habilitadas mediante `viewport-fit=cover`.

## Accesibilidad

- `role="dialog"`, `aria-modal`, título y descripción accesibles.
- Focus trap y restauración del foco.
- Escape y aviso de cambios sin guardar.
- Labels persistentes.
- Feedback mediante regiones live.
- `aria-sort` en encabezados ordenables.
- Menús y comboboxes operables con teclado.
- Confirmaciones con verbos y objetos explícitos.
- Estados con texto y color.
- Soporte para reduced motion y alto contraste.

## Contrato

Se alinearon backend, OpenAPI, frontend y pruebas para:

- tamaños de página `10/25/50/100`;
- detalle y cambio de estado de recetas;
- requerimientos de producción;
- trazabilidad de producción.

## Validaciones

- `composer validate --strict`: OK.
- `php artisan migrate:fresh --seed` sobre SQLite en memoria: OK.
- `php artisan test`: 29 pruebas, 169 aserciones, OK.
- `vendor/bin/pint --test`: OK.
- `npm run test:frontend`: 6 pruebas, OK.
- `npm run typecheck`: OK.
- `npm run build`: OK.
- detector Impeccable general, layout y type: cero hallazgos.
- `docker compose config --quiet`: OK.
- `docker compose build`: OK.
- stack Docker: backend y frontend healthy; PostgreSQL, Redis y Mailpit healthy.
- frontend `http://127.0.0.1:8080`: HTTP 200.
- backend `http://127.0.0.1:8000/up`: HTTP 200.
- `npm audit --omit=dev`: cero vulnerabilidades de producción.

## Auditoría final

| Dimensión | Puntaje | Evidencia |
| --- | ---: | --- |
| Accesibilidad | 3/4 | Modales, foco, teclado, labels, estados y touch targets; falta lector de pantalla real. |
| Rendimiento | 3/4 | Server-side, debounce, respuestas obsoletas y bundle aproximado de 163 kB JS. |
| Responsive | 3/4 | Tres composiciones y modal móvil completo; falta inspección en dispositivos reales. |
| Theming | 4/4 | Tokens de marca y estados semánticos consistentes. |
| Integridad | 4/4 | Patrón compartido, detector limpio y sin UI paralela. |
| **Total** | **17/20** | **Bueno; sin bloqueos P0 conocidos.** |

## Límites reales

- El entorno no expuso un navegador controlable a Codex; no fue posible generar
  capturas ni ejecutar la pasada visual `impeccable live`.
- No se ejecutó un lector de pantalla real.
- No se agregaron pantallas para entidades sin API pública.
- El pedido actual conserva un solo renglón por formulario porque ése es el
  alcance funcional existente del frontend; no se alteró el dominio.
- El build Docker reporta vulnerabilidades altas en dependencias de desarrollo,
  pero `npm audit --omit=dev` confirma cero vulnerabilidades de producción.
