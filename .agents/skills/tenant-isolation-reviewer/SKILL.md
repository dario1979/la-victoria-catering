---
name: tenant-isolation-reviewer
description: Review organization and branch isolation in queries, validation, route binding, authorization, jobs, and tests. Use whenever an operational entity or endpoint is added or changed.
---

# Tenant isolation reviewer

## Entradas

Entidad, ruta, actor, organización activa, sucursal activa y rol.

## Procedimiento

Trazar la autoridad desde la sesión; revisar consultas, validación de IDs,
políticas y route binding; intentar acceso con un usuario de otro tenant.

## Validaciones

No aceptar tenant desde el payload. Exigir pertenencia directa o indirecta,
sucursal activa y una prueba A/B que devuelva 403 o 404 sin filtrar existencia.

## Salida

Controles comprobados, prueba ejecutada y cualquier ruta vulnerable.
