# Observabilidad operacional

## Estado implementado

La implementación es local y portable: Laravel, PostgreSQL, Redis, logs y
Docker Compose. No depende de un SaaS externo.

Cada request acepta `X-Request-ID` si cumple el patrón
`[A-Za-z0-9][A-Za-z0-9._-]{0,63}`; en otro caso genera un UUID. El mismo valor:

- vuelve en el header de toda respuesta, incluso errores;
- aparece como `request_id` en liveness, readiness y diagnóstico;
- entra al contexto de logs;
- se propaga a jobs mediante el contexto nativo de Laravel.

Al resolver tenant también se agregan `organization_id`, `branch_id` y
`actor_id` al contexto propagado. Nunca se acepta el tenant desde el payload.

## Redacción

Todos los canales operativos configurados aplican un procesador central antes
de escribir. Redacta claves y texto relacionados con:

- autorización, tokens, cookies y contraseñas;
- firmas, certificados y secretos webhook;
- PAN, CVV/CVC y números con forma de tarjeta;
- claves push/VAPID;
- payloads fiscales y identificadores tributarios;
- mensajes de excepciones que contengan esos valores.

La redacción es defensa en profundidad. No autoriza registrar payloads
completos ni reemplaza la selección mínima de contexto.

## Endpoints

| Endpoint | Acceso | Propósito | Detalle |
| --- | --- | --- | --- |
| `GET /health/live` | público | confirma que PHP responde | sin dependencias |
| `GET /health/ready` | público | decide si recibir tráfico | sólo `ready` o `unavailable` |
| `GET /api/v1/operations/status` | sesión + tenant + `owner/admin` | diagnóstico | checks seguros y contadores |

Readiness exige:

- consulta a PostgreSQL;
- `PING` autenticado a Redis;
- escritura y limpieza en storage;
- cero migraciones pendientes;
- heartbeat de worker menor a 180 segundos;
- heartbeat de scheduler menor a 180 segundos.

El despliegue espera readiness hasta 90 segundos y mantiene el frontend sin
exponer si no pasa.

## Heartbeats y señales

El scheduler registra su heartbeat cada minuto y encola un job de heartbeat.
El worker actualiza su heartbeat al comenzar cualquier job y el framework
registra el último job exitoso. Un fallo definitivo registra sólo fecha y nombre
de clase; el payload y la excepción no se exponen.

El diagnóstico presenta:

- cantidad global de registros en `failed_jobs`, sin contenido;
- última ejecución exitosa y último fallo de job;
- notificaciones pendientes por más de 15 minutos y fallidas, limitadas al
  tenant/sucursal;
- transacciones externas en `retrying` o `manual_review`, limitadas al tenant;
- documentos fiscales `pending`, `processing` o `retrying`, limitados al tenant;
- frescura del último manifest de backup sólo si
  `OPS_BACKUP_MANIFEST_DIRECTORY` apunta a un directorio accesible.

Si no existe registro accesible de backups, el estado es `not_configured`; no se
presenta como backup vencido ni como evidencia de protección activa.

## Respuesta operativa

| Señal | Umbral inicial | Acción |
| --- | --- | --- |
| readiness indisponible | 1 comprobación fallida | no exponer/reiniciar; revisar diagnóstico y logs por `request_id` |
| worker/scheduler stale | 180 s | comprobar contenedor, Redis y `schedule:list`; reiniciar sólo tras identificar causa |
| notificación antigua | 15 min | revisar worker, horas silenciosas, intentos y canal; no duplicar manualmente |
| `failed_jobs` > 0 | inmediato | revisar clase y correlación; aplicar runbook de colas antes de reintentar |
| transacción en atención | inmediato | mantener Mercado Pago apagado; conciliación/revisión humana |
| documento fiscal pendiente | inmediato | mantener ARCA apagada; revisión humana, sin editar evidencia |
| backup overdue | 4 h desde manifest | ejecutar backup y ensayo de restauración; no declarar RPO cumplido hasta verificar |

Los umbrales son iniciales para staging, no SLO contractuales. Deben ajustarse
con evidencia del ensayo y del piloto.

## Consultas seguras

Ejemplo:

```powershell
$headers = @{
    'X-Organization-ID' = 1
    'X-Branch-ID' = 1
    'X-Request-ID' = 'incident-20260730-01'
}
Invoke-RestMethod https://staging.example.com/api/v1/operations/status `
    -WebSession $session -Headers $headers
```

No copiar cookies, tokens, payloads fiscales ni secretos en tickets o canales
de chat. Usar el correlation ID, timestamps y nombres de checks.
