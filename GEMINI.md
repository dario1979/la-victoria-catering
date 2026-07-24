# La Victoria Bakery

Plataforma integral para panadería en Argentina. Prioriza procesos diarios confiables, trazabilidad y una experiencia operativa rápida.

La marca y el nombre visible del sistema son **La Victoria Bakery**. Su cuenta
comercial es `lavictoria.bakery`.

## Principios

- Monolito modular Laravel con API REST versionada y eventos internos.
- Vue 3 + TypeScript como PWA connected-first; finanzas y fiscal requieren conexión.
- PostgreSQL es la fuente de verdad; Redis sirve para caché, colas y locks.
- Toda operación financiera es idempotente y auditable; todo stock identifica producto, unidad, ubicación, lote, cantidad y motivo.
- ARCA y Mercado Pago se integran por puertos y adaptadores. No guardar secretos ni datos reales.

## Módulos

Identidad y organización, catálogo y recetas, pedidos, producción, inventario, compras, clientes, cobranzas y caja, alertas e integraciones. Sus límites se definen en `docs/domain/module-map.md`.

## Decisiones activas

Consultar `STRATEGY.md`, ADRs en `docs/decisions/`, el plan activo en `docs/plans/mvp-incremental.md` y reglas en `.agents/rules/` antes de trabajar. No implementar WhatsApp, offline completo ni apps nativas en el MVP.
