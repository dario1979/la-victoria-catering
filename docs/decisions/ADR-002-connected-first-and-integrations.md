# ADR-002: PWA e integraciones externas

**Estado:** aceptada.

La PWA es connected-first: puede cachear lectura limitada y reintentar solicitudes idempotentes, pero pagos, caja y fiscal requieren conexión. ARCA y Mercado Pago se exponen al dominio mediante puertos; adaptadores concretos reciben webhooks y gestionan reintentos.

La configuración sensible permanece fuera del repositorio. Las reglas fiscales se validan antes de producción.
