# Importación inicial del piloto

## Estado

Implementado y probado para clientes, productos, proveedores, catálogo
proveedor-producto, ubicaciones, stock inicial por lote y recetas con
ingredientes. No admite operaciones financieras históricas.

## Flujo obligatorio

1. Una persona con rol `owner` o `admin` elige el tipo y descarga la plantilla.
2. Completa un CSV o XLSX de hasta 5 MB y 5.000 filas.
3. Sube el archivo; queda temporalmente en el disco privado configurado por
   `IMPORT_DISK` y nunca dentro de `public`.
4. Revisa y corrige el mapeo entre encabezados y campos conocidos.
5. Ejecuta el dry-run. Ninguna entidad operativa se crea en este paso.
6. Descarga el CSV de errores si existe alguna fila inválida.
7. Confirma sólo un dry-run sin errores. La confirmación exige
   `Idempotency-Key` y despacha `ProcessImportBatch` al worker.
8. Consulta el lote hasta ver `completed` o `failed`.

El tenant y la sucursal se resuelven desde la sesión y los encabezados
`X-Organization-ID`/`X-Branch-ID`; no pueden venir del archivo.

## Reglas de seguridad

- extensiones permitidas: `.csv` y `.xlsx`;
- MIME, tamaño, cantidad de filas, XML y tamaño descomprimido acotados;
- primera hoja XLSX únicamente;
- fórmulas rechazadas en entrada;
- fórmulas neutralizadas en plantillas e informes descargables;
- encabezados vacíos o duplicados rechazados;
- dinero y cantidades validados como decimales, nunca como `float`;
- fechas civiles en formato `YYYY-MM-DD`;
- unidades limitadas a `unit`, `kg`, `g`, `l` y `ml`;
- referencias resueltas por claves naturales dentro del tenant;
- duplicados se informan y nunca se actualizan silenciosamente;
- el archivo, hash y razón técnica interna no se exponen por API.

## Stock y recetas

El stock inicial crea un lote y un movimiento `initial_import` inmutable por
fila. Un rollback sólo se permite si el lote no tuvo reservas ni movimientos
posteriores; genera un movimiento compensatorio `initial_import_rollback` y
agota el lote, sin borrar evidencia.

Cada receta se crea en estado `draft`, agrupada por producto y versión. Sus
ingredientes deben existir en la misma organización y usar la unidad base del
producto. La aprobación continúa siendo una decisión operativa separada.

## Rollback

El rollback es idempotente. Para datos maestros elimina únicamente registros
sin modificaciones ni referencias posteriores. Ante pedidos, compras, lotes,
producción u otra dependencia, falla cerrado y requiere revisión manual.

## Retención y operación

`IMPORT_RETENTION_HOURS` define 72 horas por defecto. El scheduler ejecuta
`php artisan bakery:imports-prune` a diario y purga sólo archivos vencidos que
no estén en cola ni procesándose; conserva el lote, su resumen y auditoría.
Nunca editar el archivo temporal después del dry-run.
