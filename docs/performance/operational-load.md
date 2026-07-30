# Perfil de carga operativo

Estado: **implementado y ejecutado mediante la imagen oficial de k6 contra la pila E2E aislada**.

## Presupuesto inicial

Presupuesto no contractual para el dataset piloto y carga nominal:

- lecturas comunes: p95 < 500 ms y p99 < 1 s;
- mutaciones internas: p95 < 1,5 s y p99 < 2,5 s;
- error rate: 0;
- todos los checks funcionales deben pasar.

Las cifras sólo son comparables si se conserva hardware, PostgreSQL, dataset, VUs, duración y SHA.

## Ejecución segura

El perfil está en `scripts/load/operational.js`. Por defecto sólo ejecuta login, dashboard, DataTables, búsqueda, exportación pequeña y notificaciones. Las mutaciones están bloqueadas salvo `LOAD_SCOPE=isolated`.

```bash
k6 run \
  -e BASE_URL=http://127.0.0.1:18080 \
  -e EMAIL=admin@lavictoria.test \
  -e PASSWORD='valor-local' \
  -e VUS=5 \
  -e DURATION=30s \
  scripts/load/operational.js
```

Para pedido, confirmación, recepción, producción y pago se deben suministrar rutas y payloads JSON de un escenario descartable, además de `LOAD_SCOPE=isolated`. Nunca usar este modo contra staging compartido. Las claves idempotentes incluyen VU e iteración.

El resultado `performance-summary.json` no incluye credenciales ni cuerpos. Debe archivarse junto con:

- SHA y fecha;
- CPU/RAM del host y `docker stats --no-stream`;
- versión de PostgreSQL y tamaño del dataset;
- longitud de `jobs`, `failed_jobs` y entregas pendientes;
- `pg_stat_activity`, locks y deadlocks antes/después;
- consultas lentas observadas.

## Índices

La revisión estática confirmó índices compuestos para FEFO, compras, caja, conciliaciones, integraciones, notificaciones, revisión manual e importaciones. No se agregó ningún índice: falta un `EXPLAIN (ANALYZE, BUFFERS)` sobre un dataset representativo que demuestre un plan deficiente. Agregar índices sin esa evidencia encarecería escrituras y no sería una optimización verificable.

## Límites

Este perfil no es un SLA ni representa concurrencia productiva. k6 no está instalado en el host Windows actual; la prueba reproducible usa su imagen Docker oficial. Véase el resultado nominal fechado en `docs/performance/results`.
