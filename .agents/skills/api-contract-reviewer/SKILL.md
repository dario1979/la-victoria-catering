---
name: api-contract-reviewer
description: Detect drift among Laravel routes, request validation, response shapes, OpenAPI, TypeScript types, and feature tests. Use whenever an API endpoint or client call changes.
---

# API contract reviewer

## Entradas

`route:list`, controllers/requests, OpenAPI, TypeScript client/types y pruebas.

## Procedimiento

Comparar método, path, headers, autenticación, tenant, payload, estados HTTP y
respuesta; agregar una prueba de contrato para cada divergencia corregida.

## Validaciones

Idempotency-Key y headers tenant deben coincidir; errores 401/403/404/409/422/429
deben ser consumibles; no documentar endpoints inexistentes.

## Salida

Lista de divergencias, archivos corregidos y comandos de verificación.
