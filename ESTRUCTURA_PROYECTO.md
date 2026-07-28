# Estructura y estado del proyecto

## Resumen ejecutivo

**La Victoria Bakery** es una plataforma integral para centralizar la operación
diaria de una panadería: clientes, productos, pedidos, recetas, producción,
inventario por lote, cobranzas, entregas y alertas.

El proyecto está construido como un **monolito modular**, con una API REST que
conecta el backend Laravel con una PWA desarrollada en Vue.

### Estado actual

- Rama activa: `develop`.
- Repositorio limpio y sincronizado con `origin/develop`.
- Backend validado con 27 pruebas y 161 aserciones.
- Flujo vertical operativo implementado de pedido a entrega.
- Infraestructura Docker y CI configuradas.
- Aproximadamente 141 archivos relevantes y 8.165 líneas, sin contar
  dependencias ni imágenes.
- Las dependencias frontend de la copia local necesitan reinstalarse antes de
  volver a ejecutar `typecheck` y `build`.

## Base tecnológica

| Capa | Tecnología |
| --- | --- |
| Backend | Laravel 13, PHP 8.3+ |
| Frontend | Vue 3, TypeScript, Pinia |
| Interfaz | Tailwind CSS 4 |
| PWA | Vite PWA, estrategia connected-first |
| Base de datos | PostgreSQL 17 |
| Cache, colas y locks | Redis 7.4 |
| Servidor frontend | Nginx |
| Correo local | Mailpit |
| Infraestructura | Docker Compose |
| Contrato HTTP | REST `/api/v1` y OpenAPI |
| Pruebas | PHPUnit |
| Integración continua | GitHub Actions |

## Flujo operativo implementado

```text
Sesión y selección de sucursal
              ↓
Clientes + productos + ubicaciones + lotes
              ↓
Creación y confirmación del pedido
              ↓
Reserva automática de inventario mediante FEFO
              ↓
Orden de producción + receta versionada
              ↓
Consumo trazable de lotes + producto terminado
              ↓
Registro de pago + preparación + entrega
              ↓
Alertas, estados y auditoría
```

## Estructura del repositorio

```text
la-victoria-catering/
│
├── app/
│   ├── Domain/
│   │   ├── Alerts/
│   │   ├── Integrations/
│   │   ├── Inventory/
│   │   ├── Orders/
│   │   └── Production/
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Infrastructure/Integrations/
│   ├── Jobs/
│   ├── Models/
│   ├── Policies/
│   ├── Providers/
│   └── Support/
│
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
│
├── resources/
│   ├── css/
│   ├── js/
│   │   ├── assets/brand/
│   │   ├── components/
│   │   │   ├── data-table/
│   │   │   └── ui/
│   │   ├── stores/
│   │   ├── api.ts
│   │   ├── app.ts
│   │   └── types.ts
│   └── views/
│
├── routes/
│   ├── api.php
│   ├── console.php
│   └── web.php
│
├── tests/
│   ├── acceptance/
│   ├── Feature/
│   └── Unit/
│
├── docs/
│   ├── alerts/
│   ├── contracts/
│   ├── decisions/
│   ├── domain/
│   ├── plans/
│   ├── reviews/
│   └── solutions/
│
├── config/
├── docker/
├── scripts/
├── storage/
├── compose.yaml
├── composer.json
├── package.json
├── phpunit.xml
├── vite.config.js
├── PRODUCT.md
├── DESIGN.md
├── STRATEGY.md
└── README.md
```

## Responsabilidad de cada área

### `app/Domain`

Contiene las reglas principales del negocio, separadas de los controladores y
de la interfaz:

- Flujo y transiciones de pedidos.
- Entrega de pedidos.
- Ajustes de inventario.
- Recetas y conversiones de unidades.
- Cálculo de requerimientos de producción.
- Finalización de producción.
- Consumo de ingredientes por lote.
- Trazabilidad de producción.
- Gestión y deduplicación de alertas.
- Contratos para integraciones fiscales y de pagos.

### `app/Http`

Expone la API versión 1:

- Autenticación y sesión.
- Clientes.
- Productos.
- Ubicaciones.
- Lotes y movimientos de inventario.
- Pedidos y transiciones.
- Recetas.
- Órdenes de producción.
- Pagos.
- Entregas.
- Alertas.

El middleware `ResolveTenant` valida la organización y sucursal activas.

### `app/Infrastructure`

Contiene adaptadores para servicios externos:

- ARCA fake.
- ARCA sandbox.
- Mercado Pago fake.
- Mercado Pago sandbox.

Estos adaptadores permiten probar el diseño sin depender todavía de servicios
productivos.

### `app/Jobs`

Incluye procesos automáticos para:

- Detectar riesgos de inventario.
- Detectar pedidos demorados.

Ambos se programan cada 15 minutos y evitan ejecuciones superpuestas.

### `app/Models`

El dominio persistente incluye:

- Organizaciones, sucursales y usuarios.
- Clientes y productos.
- Ubicaciones y lotes.
- Movimientos y reservas de stock.
- Pedidos, ítems y transiciones.
- Recetas e ingredientes.
- Tandas y consumos de producción.
- Pagos.
- Alertas.
- Claves de idempotencia.

### `app/Support`

Agrupa componentes técnicos reutilizables:

- Operaciones idempotentes.
- Contexto de organización y sucursal.
- Cálculos decimales exactos.
- Paginación, búsqueda, filtros y ordenamiento server-side.
- Exportación de tablas a Excel.

### `resources/js`

Contiene la PWA operativa:

- Cliente HTTP con CSRF, sesión e identificación del tenant.
- Store de sesión con Pinia.
- Tipos TypeScript.
- Shell responsive para escritorio, tablet y móvil.
- Tablas reutilizables con paginación, búsqueda, filtros y exportación.
- Estados de carga, error, vacío, offline y resultado desconocido.
- Confirmación previa para acciones críticas.
- Bloqueo de mutaciones cuando no existe conexión.

La navegación principal ofrece:

1. Resumen.
2. Clientes.
3. Productos.
4. Inventario.
5. Pedidos.
6. Producción.
7. Cobranzas.
8. Alertas.

## Funcionalidad disponible

| Módulo | Estado |
| --- | --- |
| Sesión | Login, recuperación, usuario actual y logout |
| Multiempresa | Organizaciones, sucursales y membresías |
| Seguridad | Roles, políticas y aislamiento tenant |
| Clientes | Listar, buscar, filtrar, crear, consultar y actualizar |
| Productos | Catálogo, tipos, unidades, precios y stock mínimo |
| Ubicaciones | Listado y creación por sucursal |
| Inventario | Lotes, vencimientos, movimientos y ajustes |
| Reservas | Reserva automática FEFO al confirmar pedidos |
| Pedidos | Alta, seis estados, transiciones, auditoría y entrega |
| Recetas | Versionado, ingredientes y rendimiento esperado |
| Producción | Alta, requerimientos, inicio, rendimiento, merma y cierre |
| Trazabilidad | Consumo multilote FEFO y seguimiento bidireccional |
| Cobranzas | Registro idempotente de pagos y saldo pagado |
| Alertas | Detección, deduplicación, reconocimiento y resolución |
| Integraciones | Contratos y adaptadores fake/sandbox |
| Tablas | Paginación, filtros, ordenamiento y exportación Excel |
| PWA | Connected-first, responsive y bloqueo offline |

## API

La API se publica bajo `/api/v1` y actualmente contiene aproximadamente 41
operaciones.

Todas las rutas operativas requieren:

- Sesión autenticada.
- Organización autorizada.
- Sucursal activa y asignada al usuario.

Las operaciones críticas utilizan el encabezado `Idempotency-Key` para evitar
duplicados ante reintentos. El frontend envía además:

- `X-Organization-ID`.
- `X-Branch-ID`.
- `X-CSRF-TOKEN` para mutaciones.

## Seguridad y consistencia

El proyecto implementa:

- Regeneración de sesión durante el login.
- Invalidación de sesión durante el logout.
- Rate limiting en autenticación.
- Autorización por rol.
- Aislamiento de organización y sucursal.
- Route binding protegido contra acceso cruzado.
- Transacciones y locks para operaciones críticas.
- Idempotencia en pedidos, reservas, producción, pagos y entregas.
- Protección contra reutilización de una clave con un payload diferente.
- Cálculos monetarios y de cantidades sin depender de `float`.

## Datos de demostración

El seeder crea:

- La organización `La Victoria Bakery`.
- Las sucursales `Centro` y `Producción`.
- Usuarios demo para administración, ventas, producción, inventario, compras y
  cobranzas.
- Una ubicación inicial.
- Un producto terminado.
- Un lote disponible.
- Un cliente de mostrador.
- Una receta aprobada.

La contraseña se toma de la variable `DEMO_USER_PASSWORD`.

## Pruebas

El backend cuenta con 27 pruebas y 161 aserciones que validan:

- Login y logout.
- Tenancy entre organizaciones y sucursales.
- Roles y autorización.
- Flujo vertical completo.
- Transiciones de pedidos.
- Reservas FEFO.
- Idempotencia de pedidos, pagos e integraciones.
- Deduplicación de alertas.
- Consumo de ingredientes desde múltiples lotes.
- Snapshots inmutables de recetas.
- Rollback cuando el inventario cambia concurrentemente.
- Conversión exacta de unidades.
- Tablas server-side.
- Exportación Excel limitada al tenant.

## Infraestructura

`compose.yaml` levanta:

- PostgreSQL.
- Redis.
- Backend Laravel.
- Worker de colas.
- Scheduler.
- Frontend servido por Nginx.
- Mailpit.

La aplicación queda preparada para:

- Frontend: `http://localhost:8080`.
- Backend/API: `http://localhost:8000/api/v1`.
- Mailpit: `http://localhost:8025`.

La CI ejecuta:

- Instalación de dependencias PHP y Node.
- Validación de Composer.
- Migraciones y seeders desde cero.
- Pruebas de backend.
- Laravel Pint.
- Typecheck de Vue.
- Build de Vite.
- Validación y build de Docker Compose.

## Diseño y experiencia

La dirección visual se denomina **obrador editorial operativo**:

- Fondos cálidos tipo papel.
- Navegación en tonos tinta.
- Acentos salvia y durazno.
- Tablas y listas como estructura principal.
- Diseño responsive para escritorio, tablet y móvil.
- Objetivos táctiles mínimos de 44 px.
- Foco visible y soporte para reducción de movimiento.
- Estados comunicados mediante texto, etiquetas y color.
- Logo oficial integrado en acceso y navegación.

La revisión visual existente obtuvo 17/20 y no reportó bloqueos P0.

## Pendientes principales

### Funcionales

- Implementar compras, proveedores, órdenes de compra y recepciones.
- Completar caja, cuenta corriente, cierres y conciliación.
- Conectar Mercado Pago en producción.
- Validar e integrar ARCA en producción.
- Completar email, notificaciones internas y push.
- Incorporar reglas configurables y escalamiento de alertas.

### Frontend

- Reemplazar campos de ID por selectores y búsquedas operativas.
- Convertir requerimientos y trazabilidad JSON en tablas y líneas temporales.
- Agregar pruebas automatizadas de componentes y flujos.
- Realizar auditoría con lector de pantalla.
- Reinstalar las dependencias locales y volver a validar typecheck y build.

### Contratos

Actualizar OpenAPI para documentar:

- Consulta individual de receta.
- Actualización de receta.
- Requerimientos de producción.
- Trazabilidad de producción.

### Marca

Solicitar:

- Logo SVG.
- PNG transparente de alta resolución.
- Versión horizontal.
- Isotipo simplificado.
- Favicon.
- Iconos específicos para la PWA.

## Próxima etapa recomendada

1. Normalizar las dependencias frontend y confirmar `typecheck` y `build`.
2. Corregir la divergencia del contrato OpenAPI.
3. Mejorar los formularios operativos y la visualización de trazabilidad.
4. Implementar compras y proveedores.
5. Completar caja y conciliación.
6. Integrar Mercado Pago y ARCA en ambientes reales controlados.
7. Agregar pruebas frontend y una prueba end-to-end del flujo completo.

## Documentos relacionados

- `README.md`
- `PRODUCT.md`
- `DESIGN.md`
- `STRATEGY.md`
- `docs/domain/module-map.md`
- `docs/contracts/openapi.yaml`
- `docs/plans/first-operable-vertical-slice.md`
- `docs/plans/initial-backlog.md`
- `docs/reviews/impeccable-operational-pwa.md`
