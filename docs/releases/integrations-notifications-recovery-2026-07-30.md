# Incremento recuperado — integraciones y notificaciones

Fecha: 2026-07-30

## Entregado

- puertos, fake contractual y adaptadores sandbox cerrados para Mercado Pago y ARCA;
- persistencia de estados internos/externos, centavos exactos e idempotencia;
- webhook MP deduplicado, reducido y sin datos sensibles;
- jobs con reintentos, backoff, revisión manual, alertas y auditoría;
- comprobantes fiscales explícitos e inmutables;
- centro interno de notificaciones, email, preferencias y horas silenciosas;
- almacenamiento cifrado y feature flag para suscripciones push;
- OpenAPI, pruebas de contrato, integración, seguridad y UI compilada;
- runbooks de sandbox/homologación y rollback.

## No habilitado

Mercado Pago, ARCA y push PWA permanecen desactivados. No hubo credenciales sandbox, certificados fiscales, transporte VAPID ni URL HTTPS disponible para certificarlos. No se declara operación real.

## Verificación

Ejecutar migración y seed desde cero, suite backend, Pint, pruebas frontend, TypeScript, build y verificación PWA. La inspección visual en navegador continúa bloqueada porque el entorno no ofrece una sesión de navegador.
