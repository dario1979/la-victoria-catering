# Primer flujo vertical operable

## Estrategia

Entregar un único recorrido demostrable: login, selección de sucursal, cliente,
producto/lote, pedido, reserva FEFO, producción, pago, preparación, entrega,
alertas y auditoría.

Se preservan Laravel 13, Vue 3, PostgreSQL, Redis y el monolito modular. ARCA y
Mercado Pago permanecen detrás de adaptadores fake/sandbox. No se implementa
offline transaccional.

## Riesgos prioritarios

1. Aislamiento por organización y sucursal en consultas y route binding.
2. Reintentos que dupliquen pagos, reservas, producción o entregas.
3. Concurrencia sobre disponibilidad de lotes.
4. Divergencia entre OpenAPI, migraciones, API y tipos del cliente.
5. Build frontend bloqueado por el almacén TLS del host.

## Contradicciones encontradas

- El documento de estados describe un flujo más granular que el código. Este
  incremento conserva los seis estados ejecutables actuales y registra la
  granularidad mayor como evolución posterior.
- La creación actual acepta `organization_id` del cliente. Debe reemplazarse por
  la organización y sucursal activas de la sesión.
- Los movimientos de stock existentes no contienen aún organización, sucursal,
  tipo ni actor. El esquema se ampliará antes de exponer ajustes.
- Las tablas de producción no relacionan todavía una tanda con un pedido.
- OpenAPI documenta sólo tres mutaciones y debe crecer junto con cada slice.

## Slices y criterios de salida

### 1. Entorno reproducible

Archivos: `compose.yaml`, `docker/`, `.env.example`, `.github/workflows/`.

Salida: backend, frontend, PostgreSQL, Redis, worker, scheduler y Mailpit tienen
health checks; el frontend compila dentro de imagen sin desactivar TLS.

### 2. Sesión y tenant

Archivos: migraciones de organización/sucursal/membresía, middleware, políticas,
auth API, seeders y pruebas.

Salida: login/logout/me/sucursal activa funcionan y un usuario de A recibe 404 o
403 al intentar acceder a B.

### 3. Maestros operativos

Archivos: clientes, productos, ubicaciones, lotes, requests, resources y pruebas.

Salida: CRUD mínimo paginado y tenant-aware; la cantidad de lote sólo cambia por
movimientos.

### 4. Pedido a entrega

Archivos: servicios de pedidos, producción, pagos, entrega, alertas y auditoría.

Salida: el escenario principal completo funciona con claves idempotentes,
transacciones, locks y actores auditados.

### 5. Cliente conectado

Archivos: `resources/js/`, PWA y pruebas de frontend.

Salida: Vue usa la API real, recupera sesión, evita doble envío y bloquea
mutaciones offline.

### 6. Automatización e integraciones

Archivos: comandos/jobs, scheduler, adaptadores fake/sandbox y contract tests.

Salida: detecciones y reintentos son idempotentes, observables y protegidos con
locks.

## Definición de terminado

El recorrido completo se ejecuta desde Vue contra la API en Docker; migraciones
y seeders parten de cero; CI valida backend y frontend; OpenAPI coincide con las
rutas; no hay hallazgos críticos de tenancy, autorización, stock, dinero o
idempotencia.
