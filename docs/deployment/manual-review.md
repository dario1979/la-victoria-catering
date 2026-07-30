# Revisión manual y recuperación de colas

## Alcance implementado

La revisión operacional reutiliza los listados paginados de PostgreSQL y agrega
tres endpoints `owner/admin` limitados a organización y sucursal:

- `/api/v1/operations/failures`;
- `/api/v1/operations/notification-deliveries`;
- `/api/v1/operations/webhooks`.

Todos aceptan búsqueda, filtros, orden, paginación y `export=xlsx`. Los listados
financieros ya existentes cubren transacciones externas, documentos fiscales y
conciliaciones con las mismas capacidades.

## Evidencia y privacidad

`operational_failures` se alimenta sólo para jobs que llegan al fallo
definitivo y cuyo contexto propagado contiene tenant. Guarda:

- UUID del job;
- nombre de clase;
- conexión y cola;
- correlation ID;
- organización, sucursal, estado y timestamps.

No copia comando serializado, payload ni excepción. Los fallos globales sin
tenant no se muestran a administradores de una organización; únicamente suman
en la señal agregada de plataforma.

Los webhooks conservan `signature`, `headers` y `payload` originales. El listado
y el XLSX seleccionan sólo metadata segura: proveedor, referencia, resultado
de firma, hash, estado y timestamps.

## Acciones seguras

### Jobs fallidos

No existe reintento genérico. Un job puede representar una operación que ya
ocurrió fuera del sistema; volver a ejecutar su payload sin validar el dominio
podría duplicar cobros, recepciones o documentos. La acción disponible registra
una resolución humana idempotente y auditada.

### Notificaciones

Sólo una entrega `failed` puede volver a `pending`. Antes se verifica:

- pertenencia actual del destinatario a la organización;
- acceso actual a la sucursal activa;
- alerta todavía abierta, cuando la entrega proviene de una;
- transporte habilitado; push continúa bloqueado sin VAPID.

La acción no cambia asunto, mensaje, acción ni clave de deduplicación. Restablece
los metadatos de entrega, encola el procesador y registra auditoría.

### Webhooks

Sólo `rejected`, `failed` o `manual_review` pueden marcarse como
`manual_review` o `resolved`. Se exige una resolución textual e
`Idempotency-Key`. No hay reenvío al proveedor ni edición del payload.
Mercado Pago permanece deshabilitado.

### Pagos, fiscales y conciliaciones

Usar los endpoints existentes. La sincronización Mercado Pago y el reproceso
ARCA fallan cerrado mientras sus flags estén apagados. La conciliación sólo
permite transiciones de dominio y registra al actor.

## Procedimiento

1. Abrir el diagnóstico operacional y copiar el `request_id`.
2. Filtrar el listado por estado, fecha, clase o correlation ID.
3. Confirmar tenant y sucursal antes de actuar.
4. Consultar evidencia externa por canales autorizados; no copiar secretos.
5. Preferir resolución sin reintento si no puede probarse que la operación
   sigue siendo válida.
6. Para una acción permitida, generar una `Idempotency-Key` nueva y estable.
7. Repetir el diagnóstico y comprobar la entrada de auditoría.
8. Exportar XLSX sólo cuando sea necesario y tratarlo como información interna.

No editar ni borrar `failed_jobs`, webhooks, documentos fiscales, transacciones
o auditoría para “limpiar” un incidente.
