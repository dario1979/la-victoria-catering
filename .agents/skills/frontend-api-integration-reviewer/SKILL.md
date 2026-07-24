---
name: frontend-api-integration-reviewer
description: Review Vue-to-Laravel session, tenant headers, CSRF, validation errors, retries, idempotency keys, loading state, and connected-first behavior. Use for frontend flows that call the API.
---

# Frontend API integration reviewer

## Entradas

Pantalla, operación, contrato API, estado de sesión y política de conectividad.

## Procedimiento

Recorrer éxito, validación, expiración de sesión, permiso denegado, timeout,
reintento y desconexión; comprobar que una clave idempotente vive hasta éxito.

## Validaciones

Enviar CSRF y credenciales; derivar tenant de la sesión; bloquear mutaciones
offline; no interpretar timeout como fracaso; mostrar datos cacheados como viejos.

## Salida

Flujo verificado, estados visibles y defectos de integración reproducibles.
